<?php
/**
 * REDCap External Module: Copy Data on Save
 * Copy data from one place to another when you save a form.
 * Example URL: 
 * /redcap_v13.10.6/ExternalModules/?prefix=copy_data_on_save&page=summary&pid=45
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\CopyDataOnSave;

use ExternalModules\AbstractExternalModule;

require_once 'CopyInstruction.php';
require_once 'ModuleSettingsManager.php';

class CopyDataOnSave extends AbstractExternalModule {
    protected const DISPLAY_MAX_FIELD_MAP = 5;
    protected const IMPORT_ACTION = 'import-copy-instructions';
    protected $sourceProj;
    protected $sourceProjectData;
    protected $destProj;
    protected $configArray;

	/** @var int Match record id (do not create) = 0 */
	const rmDoNotCreate = 0;
	/** @var int Match record id (create matching) = 1 */
	const rmCreateMatching = 1;
	/** @var int Match record id (create auto-numbered) = 2 */
	const rmCreateAutoNumbered = 2;
	/** @var int Look up via secondary unique field = 3 */
	const rmLookupSUF = 3;

    /** @var int Ignore or N/A = 0 */
    const dagIgnoreOrNA = 0;
    /** @var int Include DAG in copy = 1 */
    const dagInclude = 1;
    /** @var int Map source to destination [deprecated] = 2 */
    const dagMap = 2;


    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
        global $Proj;

        if ($this->getProjectSetting('delay') && $this->delayModuleExecution()) return; 

        $this->sourceProj = $Proj;
        $settings = $this->getSubSettings('copy-config');

        foreach($settings as $instructionNum => $instruction) {

            $copyInstruction = new CopyInstruction($instruction, $instructionNum);
            $configErrors = $copyInstruction->getConfigErrors();
            if (count($configErrors)) {
                $title = "CopyDataOnSave module";
                $detail = "Errors in Instruction #".($copyInstruction->sequence)." \n";
                $detail .= implode('\n - ',$configErrors);
                \REDCap::logEvent($title, $detail, '', $record, $event_id);
                $this->notify($title, $detail);
                continue;
            }

            if (!$copyInstruction->copy_enabled) continue; 
            if (array_search($instrument, $copyInstruction->trigger_form)===false) continue;
            $repeat_instrument = $Proj->isRepeatingForm($event_id, $instrument) ? $instrument : "";
            if (!empty($copyInstruction->trigger_logic) && true!==\REDCap::evaluateLogic($copyInstruction->trigger_logic, $project_id, $record, $event_id, $repeat_instance, $repeat_instrument)) continue;

            $destProjectId = $copyInstruction->dest_project->project_id;
            $destEventName = $copyInstruction->dest_event;
            $recIdField = $copyInstruction->record_id_field;
            $recMatchOpt = $copyInstruction->record_match_option;
            $dagOption = $copyInstruction->dag_option;
            $dagMap = $instruction['dag-map']; // deprecated
            $copyFields = $copyInstruction->copy_fields;

            $readSourceFields[] = $recIdField;
            if ($copyInstruction->dest_event_source == CopyInstruction::evtSourceField) $readSourceFields[] = $copyInstruction->dest_event;

            $sourceInstanceField = null;
            foreach ($copyFields as $cf) {
                $readSourceFields[] = trim($cf['source-field']);
                $readDestFields[] = trim($cf['dest-field']); // #25 @bigdanfoley
                if ($cf['dest-field']=='redcap_repeat_instance') $sourceInstanceField = $cf['source-field'];
            }

            // when specifying instance number for destination, is field for instance repeating in the source?
            $sourceInstanceFieldRptFormKey = null;
            if (is_null($sourceInstanceField)) {
                $sourceInstanceFieldRpt = false;
            } else {
                $sourceInstanceFieldForm = $this->sourceProj->metadata[$sourceInstanceField]['form_name'];
                $sourceInstanceFieldRpt = $this->sourceProj->isRepeatingFormOrEvent($event_id, $sourceInstanceFieldForm);
                if ($sourceInstanceFieldRpt) {
                    $sourceInstanceFieldRptFormKey = ($this->sourceProj->isRepeatingEvent($event_id)) 
                        ? ''
                        : $sourceInstanceFieldForm;
                }
            }
                
            $this->sourceProjectData = \REDCap::getData(array(
                'return_format' => 'array', 
                'records' => $record, 
                'fields' => $readSourceFields,
                'exportDataAccessGroups' => true
            ));

            $this->destProj = new \Project($destProjectId);

            $matchValue = $this->getMatchValueForLookup($record, $event_id, $repeat_instance, $recIdField);
            $destRecord = $this->getDestinationRecordId($recMatchOpt, $matchValue);
            
            if ($recMatchOpt==self::rmDoNotCreate || $recMatchOpt==self::rmLookupSUF) { 
                // Matching destination record not found, do not create
                if ($destRecord=='') continue; 
            } else if ($recMatchOpt==self::rmCreateAutoNumbered) { 
                // To copy, either lookup field is empty and destination is next autonumbered, or lookup and dest match
                if (!(($matchValue=='' && $destRecord!='') || $matchValue!='' && $matchValue==$destRecord)) continue;

            } else if ($recMatchOpt==self::rmCreateMatching) { 
                // If have no id for destination then something amiss
                if ($destRecord=='') continue;
            } else {
                // Other options not implemented
                continue;
            }

            if ($copyInstruction->dest_event_source == CopyInstruction::evtSourceField) {
                // if using source field for destination event name then reaad the value and validate
                $sourceEventFieldForm = $this->sourceProj->metadata[$copyInstruction->dest_event]['form_name'];
                if (!in_array($event_id, $this->sourceProj->getEventsFormDesignated($sourceEventFieldForm))) {
                    $this->log("Error in Copy Data on Save instruction #$instructionNum configuration: field for destination event source '$destEventName' is invalid for event_id=$event_id");
                    continue;
                }
                // read value of event name from source field - may be on a repeating event or form
                $sourceEventFieldRpt = $this->sourceProj->isRepeatingForm($event_id, $sourceEventFieldForm);
                if ($sourceEventFieldRpt) {
                    $sourceEventFieldRptFormKey = ($this->sourceProj->isRepeatingEvent($event_id)) ? '' : $sourceEventFieldForm;
                }

                if ($sourceEventFieldRpt) {
                    $destEventName = $this->sourceProjectData[$record]['repeat_instances'][$event_id][$sourceEventFieldRptFormKey][$repeat_instance][$copyInstruction->dest_event];
                } else {
                    $destEventName = $this->sourceProjectData[$record][$event_id][$copyInstruction->dest_event];
                }       
            }

            if (empty($destEventName)) {
                $destEventId = $this->destProj->firstEventId;
            } else {
                $destEventId = $this->destProj->getEventIdUsingUniqueEventName($destEventName);
                if (!$destEventId) {
                    $this->log("Error in Copy Data on Save instruction #$instructionNum configuration: invalid destination event '$destEventName'");
                    continue;
                }
            }

            // if field in copy instruction for destination instance (should be int or string 'new') then fix instance number (or 'new')
            if (is_null($sourceInstanceField)) {
                $specifiedInstance = null;
            } else if ($sourceInstanceFieldRpt) {
                $specifiedInstance = $this->sourceProjectData[$record]['repeat_instances'][$event_id][$sourceInstanceFieldRptFormKey][$repeat_instance][$sourceInstanceField];
            } else {
                $specifiedInstance = $this->sourceProjectData[$record][$event_id][$sourceInstanceField];
            }       

            $readDestFields[] = $this->destProj->table_pk;

            $destProjectData = \REDCap::getData(array(
                'return_format' => 'array', 
                'project_id' => $destProjectId,
                'records' => $destRecord, 
                'fields' => $readDestFields,
                'exportDataAccessGroups' => true
            ));

            if (($recMatchOpt==self::rmDoNotCreate || $recMatchOpt==self::rmLookupSUF) && !array_key_exists($destRecord, $destProjectData)) continue; // do not create new record 

            $saveArray = array();
            $fileCopies = array();
            $fileDeletes = array();
            $overwriteBlocked = array();
            foreach ($copyFields as $cf) {
                $sf = $cf['source-field'];
                $df = $cf['dest-field'];
                $noOverwrite = $cf['only-if-empty'];
                if ($df=='redcap_repeat_instance') continue;

                $rptEvtInSource = $this->sourceProj->isRepeatingEvent($event_id);
                $rptEvtInDest = $this->destProj->isRepeatingEvent($destEventId);
                $rptFrmInSource = $this->sourceProj->isRepeatingForm($event_id, $this->sourceProj->metadata[$sf]['form_name']);
                $rptFrmInDest = $this->destProj->isRepeatingForm($destEventId, $this->destProj->metadata[$df]['form_name']);
                
                if ($rptFrmInSource) {
                    $rptInstrumentKeySrc = $this->sourceProj->metadata[$sf]['form_name'];
                } else {
                    $rptInstrumentKeySrc = ($rptEvtInSource) ? '' : null;
                }
                if ($rptFrmInDest) {
                    $rptInstrumentKeyDest = $this->destProj->metadata[$df]['form_name'];
                } else {
                    $rptInstrumentKeyDest = ($rptEvtInDest) ? '' : null;
                }

                /* behaviour with repeating data
                    Src  Dest Copy to
                    N    N    Non-rpt
                    Y    N    Non-rpt
                    N    Y    New instance*  only-if-empty when ticked means new instance only if value not same as last instance
                    Y    Y    Same instance (unless specified to create new instance)
                */
                if ($rptFrmInSource || $rptEvtInSource) {
                    $valueToCopy = $this->sourceProjectData[$record]['repeat_instances'][$event_id][$rptInstrumentKeySrc][$repeat_instance][$sf];
                } else {
                    $valueToCopy = $this->sourceProjectData[$record][$event_id][$sf];
                }

                if ($rptFrmInDest || $rptEvtInDest) {
                    if (!is_null($specifiedInstance)) {
                        $destInstance = $specifiedInstance; // copying _to_ repeating : set destination instance number
                    } else if ($rptFrmInSource || $rptEvtInSource) {
                        $destInstance = $repeat_instance; // rpt src -> rpt dest: same instance
                    } else {
                        if (is_array($destProjectData)
                                && is_array($destProjectData[$destRecord])
                                && is_array($destProjectData[$destRecord]['repeat_instances'])
                                && array_key_exists($rptInstrumentKeyDest, $destProjectData[$destRecord]['repeat_instances'][$destEventId])
                                && is_array($destProjectData[$destRecord]['repeat_instances'][$destEventId][$rptInstrumentKeyDest])) {
                            $destInstances = $destProjectData[$destRecord]['repeat_instances'][$destEventId][$rptInstrumentKeyDest];
                            ksort($destInstances, SORT_NUMERIC);
                            $maxInstance = key(array_slice($destInstances, -1, 1, true));
                            $valInMax = $destProjectData[$destRecord]['repeat_instances'][$destEventId][$rptInstrumentKeyDest][$maxInstance][$df];

                            if ($valueToCopy==$valInMax && $noOverwrite) continue; // skip creating a new instance as no new value

                            $destInstance = 1 + $maxInstance;
                        } else {
                            $destInstance = 1;
                        }
                    }
                    $valueInDest = ($destInstance == 'new' || !array_key_exists($destInstance, $destProjectData[$destRecord]['repeat_instances'][$destEventId][$rptInstrumentKeyDest])) 
                        ? '' 
                        : $destProjectData[$destRecord]['repeat_instances'][$destEventId][$rptInstrumentKeyDest][$destInstance][$df];
                } else {
                    $destInstance = null;
                    $valueInDest = $destProjectData[$destRecord][$destEventId][$df];
                }

                if ($valueInDest!='' && $noOverwrite) {
                    $overwriteBlocked[] = "$sf=>$df"; // update only if destination empty
                } else {
                    if ($this->sourceProj->metadata[$sf]['element_type'] == 'file') {
                        // for file fields as destination need to copy the source file and get a new doc id
                        // if destination already has a file, only copy if the file has changed
                        if ($valueInDest!='') {
                            list ($sourceMimeType, $sourceDocName, $sourceFileContent) = \REDCap::getFile($valueToCopy);
                            list ($destMimeType, $destDocName, $destFileContent) = \REDCap::getFile($valueInDest);
                            $fileChanged = ($sourceMimeType!=$destMimeType || $sourceDocName!=$destDocName || $sourceFileContent!=$destFileContent);
                        } else {
                            $fileChanged = true;
                        }

                        if ($fileChanged) {
                            if ($valueToCopy=='' && $valueInDest!='') {
                                $fileDeletes[] = array(
                                    'doc_id' => $valueInDest, 
                                    'project_id' => $destProjectId, 
                                    'record' => $destRecord, 
                                    'field_name' => $df, 
                                    'event_id' => $destEventId, 
                                    'repeat_instance' => $destInstance
                                );
                            } else {
                                $fileCopies[] = array(
                                    'doc_id' => \REDCap::copyFile($valueToCopy, $destProjectId), 
                                    'project_id' => $destProjectId, 
                                    'record' => $destRecord, 
                                    'field_name' => $df, 
                                    'event_id' => $destEventId, 
                                    'repeat_instance' => $destInstance
                                );
                            }
                        }
                    }
                    if ($rptFrmInDest || $rptEvtInDest) {
                        $saveArray[$destRecord]['repeat_instances'][$destEventId][$rptInstrumentKeyDest][$destInstance][$df] = $valueToCopy;
                    } else {
                        $saveArray[$destRecord][$destEventId][$df] = $valueToCopy;
                    }
                }
            }

            if ($dagOption != self::dagIgnoreOrNA) {
                if ($Proj->isRepeatingEvent($event_id)) {
                    $sdag = $this->sourceProjectData[$record]['repeat_instances'][$event_id][''][$repeat_instance]['redcap_data_access_group'] ?? '';
                } else if ($Proj->isRepeatingForm($event_id, $instrument)) {
                    $sdag = $this->sourceProjectData[$record]['repeat_instances'][$event_id][$instrument][$repeat_instance]['redcap_data_access_group'] ?? '';
                } else {
                    $sdag = $this->sourceProjectData[$record][$event_id]['redcap_data_access_group'] ?? '';
                }

                if ($dagOption == self::dagInclude) {
                    $saveArray[$destRecord][$destEventId]['redcap_data_access_group'] = $sdag;
                }
                else if ($dagOption == self::dagMap) {
                    $ddag = '';
                    if ($sdag!='') {
                        foreach ($dagMap as $dm) {
                            if ($sdag==\REDCap::getGroupNames(true, $dm['source-dag'])) {
                                $ddag = $dm['dest-dag'];
                                // Don't break so last wins, not first, if source dag specified more than once (same behaviour as for source fields)
                            }
                        }
                    }
                    $saveArray[$destRecord][$destEventId]['redcap_data_access_group'] = $ddag;
                }
            }

            try {
                $saveResult = \REDCap::saveData($destProjectId, 'array', $saveArray, 'overwrite');
                foreach ($fileCopies as $copiedFile) {
                    \REDCap::addFileToField(
                        $copiedFile['doc_id'],
                        $copiedFile['project_id'], 
                        $copiedFile['record'], 
                        $copiedFile['field_name'], 
                        $copiedFile['event_id'], 
                        $copiedFile['repeat_instance']
                    );
                }
                $redcap_data = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($destProjectId) : "redcap_data";
                foreach ($fileDeletes as $deletedFile) { 
                    // No developer method for removing a file: DataEntry.php L5668 FILE UPLOAD FIELD: Set the file as "deleted" in redcap_edocs_metadata table
                    $instance = ($deletedFile['instance'] > 1) ? "instance = ".$this->escape($deletedFile['instance']) : "instance is null";
                    $sql = "UPDATE redcap_edocs_metadata e, $redcap_data d LEFT JOIN $redcap_data d2 
                            ON d2.project_id = d.project_id AND d2.value = d.value AND d2.field_name = d.field_name AND d2.record != d.record
                            SET e.delete_date = ?
                            WHERE e.project_id = ? AND e.project_id = d.project_id
                            AND d.field_name = ? AND d.value = e.doc_id AND d.record = ?
                            AND d.$instance 
                            AND e.delete_date IS NULL AND d2.project_id IS NULL AND e.doc_id = ?";
                    $params = [NOW, $deletedFile['project_id'],$deletedFile['field_name'],$deletedFile['record'],$deletedFile['doc_id']];
                    $sql_all[] = $this->getSqlForLogging($sql, $params);
                    $this->query($sql, $params);

                    $sql = "DELETE FROM $redcap_data WHERE project_id = ? AND record = ? AND event_id = ? AND field_name = ? AND $instance ";
                    $params = [$deletedFile['project_id'],$deletedFile['record'],$deletedFile['event_id'],$deletedFile['field_name']];
                    $sql_all[] = $this->getSqlForLogging($sql, $params);
                    $this->query($sql, $params);

                    \Logging::logEvent(implode('\n',$sql_all), 'redcap_data', 'UPDATE', $deletedFile['record'], $deletedFile['field_name']." = ''", 'Update record');
                }
            } catch (\Throwable $e) {
                $saveResult = array('errors'=>$e->getMessage());
            }

            $title = "CopyDataOnSave module";
            $detail = "Instruction #".($instructionNum+1);
            $detail .= " \nCopy from: record=$record, event=$event_id, instrument=$instrument, instance=$repeat_instance";
            $detail .= " \nCopy to: project_id=$destProjectId, record=$destRecord, event=$destEventId, instance=$destInstance";
            if ($this->getProjectSetting('log-copy-contents')) $detail .= " \Data: ".json_encode($saveArray);

            if ((is_array($saveResult['errors']) && count($saveResult['errors'])>0) || 
                (!is_array($saveResult['errors']) && !empty($saveResult['errors'])) ) {
                $title .= ": COPY FAILED ";
                $detail .= " \n".print_r($saveResult['errors'], true);
                \REDCap::logEvent($title, $detail, '', $record, $event_id);
                $this->notify($title, $detail);
            } else {
                if (count($overwriteBlocked)) $detail .= " \nCopy to non-empty fields skipped: ".implode(',', $overwriteBlocked);
                \REDCap::logEvent($title, $detail, '', $record, $event_id);

                // if created autonumbered record, capture new id to lookup field
                if ($recMatchOpt==self::rmCreateAutoNumbered && $destRecord!='') {
                    $saveAuto = array(array(
                        $this->sourceProj->table_pk => $record,
                        $recIdField => $destRecord
                    ));
                    if ($this->sourceProj->longitudinal) {
                        $saveAuto[0]['redcap_event_name'] = \REDCap::getEventNames(true, false, $event_id);
                    }
                    if ($this->sourceProj->isRepeatingEvent($event_id)) {
                        $saveAuto[0]['redcap_repeat_instrument'] = '';
                        $saveAuto[0]['redcap_repeat_instance'] = $repeat_instance;
                    } else if ($this->sourceProj->isRepeatingForm($event_id, $instrument)) {
                        $saveAuto[0]['redcap_repeat_instrument'] = $instrument;
                        $saveAuto[0]['redcap_repeat_instance'] = $repeat_instance;
                    }
                    $saveResult = \REDCap::saveData('json-array', $saveAuto);
                }
            }
        }
	}

    /**
     * redcap_module_project_enable($version, $project_id)
     * When enabling the module on a project, check for existing settings (e.g. if this enabling module in a copy of another project).
     * If settings are found and the destination project is not the same as the current project then disable the rule and remove the project as a safety measure.
     * @param string $version
     * @param string $project_id
     * @return void
     */
    public function redcap_module_project_enable($version, $project_id)
    {
        $project_settings = $this->getProjectSettings($project_id);
        $settings = $this->getSubSettings('copy-config');

        foreach($settings as $key => $instruction) {
            $enabled = $instruction['copy-enabled'];
            $destPid = $instruction['dest-project'];
            if ($enabled && $destPid!=$project_id) { // for active rules pointing at other projects - deactivate and remove project
                $project_settings['copy-enabled'][$key] = false;
                $project_settings['dest-project'][$key] = null;
                $msm = new ModuleSettingsManager($this);
                $msm->saveSettingsToHistory($project_settings);
            }
        }
    }

    /**
     * getMatchValueForLookup
     * Find the source value to use for looking up the record in the destination
     * @param string $record
     * @param string $event_id
     * @param string $instance
     * @param string $recIdField
     * @return string
     * @since 1.3.0
     */
    protected function getMatchValueForLookup($record, $event_id, $instance, $recIdField) {
        if ($recIdField === $this->sourceProj->table_pk) return $record;
        $lookupRecordId = '';
        try {
            if ($this->sourceProj->isRepeatingEvent($event_id)) {
                $lookupRecordId = $this->sourceProjectData[$record]['repeat_instances'][$event_id][''][$instance][$recIdField];
            } else if ($this->sourceProj->isRepeatingForm($event_id, $this->sourceProj->metadata[$recIdField]['form_name'])) {
                $lookupRecordId = $this->sourceProjectData[$record]['repeat_instances'][$event_id][$this->sourceProj->metadata[$recIdField]['form_name']][$instance][$recIdField];
            } else {
                $lookupRecordId = $this->sourceProjectData[$record][$event_id][$recIdField];
            }
        } catch (\Throwable $th) { }
        return $lookupRecordId;
    }

    /**
     * getDestinationRecordId
     * Get the record id to use in the destination from the source data based on the lookup value - supports using field from repeating form/event
     * @param int $recMatchOpt Matching option
     * @param string $lookupRecordId
     * @return string
     * @since 1.1.0
     */
    protected function getDestinationRecordId($recMatchOpt, $lookupRecordId) {
        $destRecordId = '';
        $destData = array();
        if ($lookupRecordId!='') {
            $destFields = array();
            $destFields[] = $this->destProj->table_pk;
            if (!empty($this->destProj->project['secondary_pk'])) $destFields[] = $this->destProj->project['secondary_pk'];

            $params = array(
                'format' => 'array',
                'project_id' => $this->destProj->project_id,
                'fields' => $destFields
            );

            if ($recMatchOpt==self::rmLookupSUF) {
                $params['filterLogic'] = '[first-event-name]['.$this->destProj->project['secondary_pk']."]='$lookupRecordId'"; // assume 2nd id is in first event for now
            } else {
                $params['records'] = $lookupRecordId;
            }

            $destData = \REDCap::getData($params);
        }

        switch ($recMatchOpt) {
            case self::rmDoNotCreate: // Match record id (do not create)
                $destRecordId = (count($destData)) ? $lookupRecordId : ''; // return matched record id if already exists, empty if not
                break;
            case self::rmCreateMatching: // Match record id (create matching)
                $destRecordId = $lookupRecordId; // use lookup value and create if not existing - match not required
                break;
            case self::rmCreateAutoNumbered: // Match record id (create auto-numbered)
                $destRecordId = (count($destData)) ? $lookupRecordId : \REDCap::reserveNewRecordId($this->destProj->project_id); // return matched record id if already exists, reserve new if not
                break;
            case self::rmLookupSUF: // Look up via secondary unique field
                $destRecordId = (count($destData)) ? array_key_first($destData) : ''; // return matched record id if second id found, empty if not
                break;
            default: 
                break;
        }

        return $destRecordId;
    }

    /** 
     * getSqlForLogging()
     * Get SQL with ? placeholders replaced by parameter values for the purposes of logging 
     * e.g. "select a from b where c=?" / ['d'] -> "select a from b where c='d'"
     * @param string sql
     * @param array params
     * @return array sql with params inserted
     */
    protected function getSqlForLogging(string $sql, array $params) : string {
        return implode('', array_map('implode', array_map(null, explode('?',$sql), array_map(fn($p):mixed=>(is_numeric($p))?$p:"'$p'",$params))));
    }

	/**
	 * notify()
	 */
	protected function notify($subject, $bodyDetail) {
		global $project_contact_email;
        $bodyDetail = str_replace(PHP_EOL,'<br>',$bodyDetail);
        $failEmails = $this->getProjectSetting('fail-alert-email');
        if (is_array($failEmails) && count($failEmails)>0 && !empty($failEmails[0])) {
            $email = new \Message();
            $email->setFrom($project_contact_email);
            $email->setTo(implode(';', $failEmails));
            $email->setSubject($subject);
            $email->setBody("$subject<br><br>$bodyDetail", true);
            $email->send();
        }
    }

    /**
     * redcap_module_configuration_settings
     * Triggered when the system or project configuration dialog is displayed for a given module.
     * Allows dynamically modify and return the settings that will be displayed.
     * @param string $project_id, $settings
     */
    public function redcap_module_configuration_settings($project_id, $settings) {
        if (!empty($project_id)) {
            foreach ($settings as $si => $sarray) {
                if ($sarray['key']=='summary-page') {
                    $url = $this->getUrl('summary.php',false,false);
                    $settings[$si]['name'] = str_replace('href="#"', 'href="'.$url.'"', $settings[$si]['name']);
                    break;
                }
            }
        }
        return $settings;
    }

    protected function getConfigArray() {
        if (!isset($this->configArray)) {
            $this->configArray = $this->getConfig();
        }
        return $this->configArray;
    }

    protected function getChoicesAsArray(array $settingsArray, $findSetting) {
        $return = null;
        foreach ($settingsArray as $setting => $settingAttrs) {
            if (is_array($settingAttrs) && array_key_exists('key', $settingAttrs) && $settingAttrs['key']==$findSetting) {
                if (array_key_exists('choices', $settingAttrs)) {
                    $return = array();
                    foreach ($settingAttrs['choices'] as $choice) {
                        $return[$choice['value']] = $choice['name'];
                    }
                }
                break;
            } else if (is_array($settingAttrs)) {
                $return = $this->getChoicesAsArray($settingAttrs, $findSetting);
            }
            if (is_array($return)) break;
        }
        return $return;
    }

    protected function getLabelForConfigChoice($setting, $value) {
        $label = $value;
        $choices = $this->getChoicesAsArray($this->getConfigArray(), $setting);
        if (is_array($choices) && array_key_exists($value, $choices)) {
            $label = $choices[$value];
        }
        return $label;
    }

    /**
     * summaryPage()
     * Content for summary page showing table of copy instruction configurations
     */
    public function summaryPage() {
        global $project_id;

        // make the dropdown list of version history for export
        $msm = new ModuleSettingsManager($this);
        $instructionKeys = $this->getInstructionSettingKeys();
        try {
            $history = $msm->getFilteredSettingsHistory($instructionKeys);
        } catch (\Throwable $th) {
            //$this->log('Failed to read module settings history: '.$th->getMessage());
            $history = array();
        }
        $versions = array();
        if (empty($history)) {
            $versions[0] = 'Current';
            $cc = $this->getProjectSetting('copy-config');
            if (!is_null($cc)) $msm->saveCurrentSettingsToHistory(); // log current when this is the first time viewed after upgrade to module v1.6.0+
        } else {
            foreach ($history as $version) {
                $id = (empty($versions)) ? 0 : $version['log_id']; // use id=0 for current version of settings
                $lbl = substr($version['timestamp'], 0, 16).' ('.$version['username'].')';
                $versions[$id] = $lbl;
            }
        }
        $versionDropdown = \RCView::select(array('id'=>'cdos-version','name'=>'cdos-version'), $versions);

        $instructions = $this->getSubSettings('copy-config');
        $columns = array(
            array('title'=>'#','tdclass'=>'text-center','getter'=>function(array $instruction){ return '<span class="cdos-seq"></span>'; }),
            array('title'=>'Description','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $desc = (isset($instruction['section-description'])) ? $instruction['section-description'] : ''; // old configs may be missing section-description
                if (is_null($desc) || trim($desc=='')) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                } else {
                    $desc = $this->escape(trim($desc));
                    $descDisplay = '<span class="m-0 text-left cdos-two-line-text" style="font-size:75%; max-width: 20ch;">'.str_replace('\n',' ',$desc).'</span>';
                    return '<span class="cdos-hidden">'.$desc.'</span><button class="cdos-btn-show btn btn-xs btn-outline-primary" title="View full description">'.$descDisplay.'</button>';
//                    return $descDisplay.'<span class="cdos-hidden">'.$desc.'</span><button class="cdos-btn-show btn btn-xs btn-outline-primary" title="View Description"><i class="fa-solid fa-comment-dots mx-2"></i></button>';
                }
            }),
            array('title'=>'Enabled','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $enabledDesc = '<i class="fa-solid '.(($instruction['copy-enabled']) ? 'fa-check text-success' : 'fa-times text-danger').'"></i>';
                $copyInstruction = new CopyInstruction($instruction);
                $configErrors = $copyInstruction->getConfigErrors();
                if (count($configErrors)) {
                    $errMsg = '<ul><li>'.implode('</li><li>', $configErrors).'</li></ul>';
                    $enabledDesc .= '<span class="cdos-hidden">'.$this->escape($errMsg).'</span><button class="cdos-btn-show btn btn-xs btn-outline-danger ml-2" title="Copy Instruction Configuration Errors"><i class="fa-solid fa-exclamation-triangle mx-2"></i></button>';
                }
                $configWarnings = $copyInstruction->getConfigWarnings();
                if (count($configWarnings)) {
                    $warnMsg = '<ul><li>'.implode('</li><li>', $configWarnings).'</li></ul>';
                    $enabledDesc .= '<span class="cdos-hidden">'.$this->escape($warnMsg).'</span><button class="cdos-btn-show btn btn-xs btn-outline-warning ml-2" title="Copy Instruction Configuration Warnings"><i class="fa-solid fa-exclamation-triangle mx-2"></i></button>';
                }
                return $enabledDesc;
            }),
            array('title'=>'Trigger Form(s)','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $formList = array();
                foreach ($instruction['trigger-form'] as $form) {
                    $formList[] = "<span class='badge bg-primary'>$form</span>";
                }
                return implode('<br>', $formList); 
            }),
            array('title'=>'Trigger Logic','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $logic = $instruction['trigger-logic'];
                if (is_null($logic) || trim($logic=='')) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                } else {
                    return '<span class="cdos-hidden"><pre>'.\htmlspecialchars($logic,ENT_QUOTES).'</pre></span><button class="cdos-btn-show btn btn-xs btn-outline-primary" title="View Trigger Logic"><i class="fa-solid fa-bolt mx-2"></i></button>';
                }
            }),
            array('title'=>'Destination Project','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $destPid = $instruction['dest-project'];
                if (empty($destPid)) {
                    return '<i class="fa-solid fa-minus text-danger"></i>';
                } else {
                    $destProj = new \Project($destPid);
                    $title = $this->escape($destProj->project['app_title']);
                    return "<span class='badge bg-secondary' title='$title'>$destPid</span>";
                }
            }),
            array('title'=>'Destination Event','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                if (empty($instruction['dest-event'])) {
                    return '<i class="fa-solid fa-minus text-muted"></i>';
                } else {
                    $copyInstruction = new CopyInstruction($instruction);
                    $badge = ($copyInstruction->dest_event_source==CopyInstruction::evtSourceField) ? 'bg-primary' : 'bg-secondary';
                    return "<span class='badge $badge'>".$instruction['dest-event'].'</span>';
                }
            }),
            array('title'=>'Record ID Field','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                return (empty($instruction['record-id-field'])) ? '<i class="fa-solid fa-minus text-danger"></i>' : '<span class="badge bg-primary">'.$instruction['record-id-field'].'</span>';
            }),
            array('title'=>'Record Match Option','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $val = $instruction['record-create'] ?? '0';
                return '<span class="badge bg-secondary">'.$this->getLabelForConfigChoice('record-create', $val).'</span>';
            }),
            array('title'=>'DAG Option','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $val = $instruction['dag-option'] ?? '0';
                return '<span class="badge bg-primary">'.$this->getLabelForConfigChoice('dag-option', $val).'</span>';
            }),
            array('title'=>'Field Mappings (Can overwrite?)','tdclass'=>'cdos-field-map-col','getter'=>function(array $instruction){ 
                $return = '';
                $fieldList = array();
                foreach ($instruction['copy-fields'] as $cf) {
                    if (is_array($cf)) {
                        $s = "<span class='badge bg-primary'>{$cf['source-field']}</span>";
                        $d = "<span class='badge bg-secondary'>{$cf['dest-field']}</span>";
                        $o = ($cf['only-if-empty']) ? '<i class="fa-solid fa-times-circle text-secondary" title="Only if empty"></i>' : '<i class="fa-solid fa-circle-check text-primary" title="Can overwrite"></i>';
                        $n = ($cf['rtr-new-instance']) ? '<i class="fa-solid fa-square-plus text-success" title="Repeating to Repeating: Create new instance"></i>' : '';
                        $fieldList[] = "<div class='nowrap my-1' style='display:flex;align-items:center;gap:2px;'>$s<i class='fa-solid fa-arrow-right-long text-muted'></i>$d $o $n</div>";
                    }
                }
                if (count($fieldList) > self::DISPLAY_MAX_FIELD_MAP) {
                    $firstN = array_slice($fieldList, 0, self::DISPLAY_MAX_FIELD_MAP);
                    $return = '<span class="cdos-hidden"><div class="cdos-field-map-dialog-content">';
                    for ($i=0; $i < count($fieldList); $i++) { 
                        $return .= '<div class="my-1" style="display:flex;align-items:center;"><span class="cdos-field-map-index">'.($i+1).'.</span>'.$fieldList[$i].'</div>';
                    }
                    $return .= '</div></span>';
                    $return .= implode('', $firstN);
                    $return .= '<button type="button" class="cdos-btn-show btn btn-xs btn-outline-success mt-1" title="View All Field Mappings"><i class="fa-solid fa-eye mr-1"></i>+'.(count($fieldList)-self::DISPLAY_MAX_FIELD_MAP).'</button>';
                } else {
                    $return = implode('', $fieldList);
                }
                return $return; 
            })
        );

        $lastExportType = \UIState::getUIStateValue($project_id, 'cdos-export', 'repeat-separator') ?? 'line';
        switch ($lastExportType) {
            case 'space': $spaceActive = 'active" aria-current="true"'; $lineActive  = $pipeActive  = ''; break;
            case 'pipe':  $pipeActive  = 'active" aria-current="true"'; $lineActive  = $spaceActive = ''; break;
            default:      $lineActive  = 'active" aria-current="true"'; $spaceActive = $pipeActive  = ''; break;
        }
        ?>
        <div class="projhdr"><i class="fa-solid fa-file-export mr-1"></i>Copy Data on Save: Summary of Copy Instructions</div>
        <div style="max-width: 1000px;">
            <div id="cdos-intro-info">
                <h6>Table of Copy Instructions</h6>
                <p>The table below shows the current configuration settings of copy instructions set up in this project.</p>
                <p>You may download or upload instructions in CSV format using the "Export" and "Import" buttons on this page. A new version is included in the dropdown list each time the module settings are saved or altered settings or imported.</p>
            </div>
            <div id="cdos-import-export">
                <h6>Export & Import <button id="cdos-btn-import-info" class="btn btn-xs btn-default fs18" type="button"><i class="fa-solid fa-circle-info"></i></button></h6>
                <div id="cdos-import-export-controls">
                    <?= $versionDropdown ?>
                    <div class="btn-group">
                        <button name="cdos-btn-export-<?= $lastExportType ?>" class="cdos-btn-export btn btn-xs btn-primaryrc" type="button">
                            <i class="fa-solid fa-file-export"></i> <?= \RCView::tt('global_71') ?>
                        </button>
                        <button type="button" class="btn btn-xs btn-primaryrc dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="visually-hidden">Toggle Dropdown</span>
                        </button>
                        <ul class="dropdown-menu">
                            <li><span class="dropdown-item-text">Repeating value separator</span></li>
                            <li><button name="cdos-btn-export-line"  class="cdos-btn-export dropdown-item <?= $lineActive  ?>" type="button"><i class="fa-solid fa-file-export"></i> <?= \RCView::tt('global_71') ?> (line)</button></li>
                            <li><button name="cdos-btn-export-space" class="cdos-btn-export dropdown-item <?= $spaceActive ?>" type="button"><i class="fa-solid fa-file-export"></i> <?= \RCView::tt('global_71') ?> (space)</button></li>
                            <li><button name="cdos-btn-export-pipe"  class="cdos-btn-export dropdown-item <?= $pipeActive  ?>" type="button"><i class="fa-solid fa-file-export"></i> <?= \RCView::tt('global_71') ?> (pipe)</button></li>
                        </ul>
                    </div>
                    <button id="cdos-btn-import" class="btn btn-xs btn-success mx-2"><i class="fa-solid fa-file-import"></i> <?= \RCView::tt('global_72') ?></button> 
                    <button id="cdos-btn-edit" class="btn btn-xs btn-dark"><i class="fa-solid fa-pencil"></i> <?= \RCView::tt('econsent_43') //"Edit settings"?></button> 
                </div>
                
            </div>
        </div>
        <div id="cdos-summary-table-container">
        <table id="cdos-summary-table"><thead><tr>
        <?php 

        foreach ($columns as $col) {
            $class = (empty($col['tdclass'])) ? '' : ' class="'.$this->escape($col['tdclass']).'"';
            echo "<th$class>".\REDCap::filterHtml($col['title']).'</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($instructions as $instruction) {
            echo '<tr>';
            foreach ($columns as $col) {
                $class = (empty($col['tdclass'])) ? '' : ' class="'.$col['tdclass'].'"';
                $contentGetterFunction = $col['getter'];
                $cellContent = call_user_func($contentGetterFunction, $instruction);
                echo "<td $class>".\REDCap::filterHtml($cellContent).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        $url = $this->getUrl('export_import.php', false, false);

        $this->initializeJavascriptModuleObject();
        ?>
        <div id="cdos-import-dialog-text" class="cdos-hidden">You may import Copy Data on Save instructions using a CSV file formatted congruently with how the instructions are exported from this page.</div>
        <div id="cdos-import-file-container" class="cdos-hidden"></div>
        <div id="cdos-import-info" class="cdos-hidden">
            <p class="mt-0">Copy instructions may be exported and imported. Use the CSV delimiter character from your REDCap profile when importing.</p>
            <p>Select the timestamp of the settings version to export. The first entry (default) is the latest version; the current settings.</p>
            <p>Import files require the following <strong>twelve columns</strong> to be present (although you may alter the title text). Values are required for some columns and option for others, as indicated:</p>
            <ol>
                <li><strong>Description (optional)</strong></li>
                <li><strong>Enabled</strong> (required): Integer from the following list:
                    <ol start="0">
                        <li>Disabled</li>
                        <li>Enabled</li>
                    </ol>
                </li>
                <li><strong>Trigger form(s)</strong> (optional): A separated<sup>*</sup> list of form names</li>
                <li><strong>Trigger condition</strong> (optional): A REDCap logic expression</li>
                <li><strong>Destination project id</strong> (required): You must have access to the project to <em>alter</em> this value</li>
                <li><strong>Destination event</strong> (optional): Can be an event name in the destination proejct or a field name in the source project</li>
                <li><strong>Record id source</strong> (required): Field in soure project containing record id for destination</li>
                <li><strong>Record matching option</strong> (required): Integer from the following list:</li>
                    <ol start="0">
                        <li>Match record id (do not create)</li>
                        <li>Match record id (create matching)</li>
                        <li>Match record id (create auto-numbered)</li>
                        <li>Look up via secondary unique field</li>
                    </ol>
                <li><strong>DAG option</strong> (required): Integer from the following list:
                    <ol start="0">
                        <li>Ignore or N/A</li>
                        <li>Include DAG in copy</li>
                    </ol>
                </li>
                <li><strong>Field mapping - source</strong> (repeating<sup>+</sup> - required): A separated<sup>*</sup> list of source project fields</li>
                <li><strong>Field mapping - destination</strong> (repeating<sup>+</sup> - required): A separated<sup>*</sup> list of destination project fields</li>
                <li><strong>Field mapping - only if empty</strong> (repeating<sup>+</sup> - optional): A separated<sup>*</sup> list of integer values from the following list:
                    <ol start="0">
                        <li>[Default when empty] Always copy (can overwrite)</li>
                        <li>Copy only if destination field is empty</li>
                    </ol>
                </li>
            </ol>
            <p><sup>*</sup> "Separated list": repeating values can separated with any non-word character e.g. space, pipe, line-break. The import process will attempt to detect the separator character automatically.</p>
            <p class="mb-0"><sup>+</sup> "Repeating": each of these mapping columns must have the same number of list entries.</p>
        </div>
        <style type="text/css">
            h6 { 
                font-weight: bold; 
            }
            #cdos-summary-table-container { max-width: 800px; }
            #cdos-intro-info { margin: 1rem 0 1rem 0; }
            #cdos-import-export { 
                max-width: 500px;
                margin: 1rem 0 1rem 0;
            }
            #cdos-version { font-size: 85%; }
            .cdos-hidden { display: none; }
            .cdos-field-map-dialog-content { max-height: 500px; overflow-y: scroll; }
            .cdos-field-map-index { display: inline-block; width: 35px; }
            #cdos-summary-table .badge { font-weight: normal; padding: 3px 5px; }
            .cdos-two-line-text {
                overflow: hidden;
                text-overflow: ellipsis;
                display: -webkit-box;
                line-clamp: 2;
                box-orient: vertical;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                height: 2.1em;
                line-height: 1em;
            }
        </style>
        <script type="text/javascript">
            let module = <?=$this->getJavascriptModuleObjectName()?>;
            module.exportUrl = '<?=$url?>&action=export';
            module.importUrl = '<?=$url?>&action=import';
            module.show = function() {
                let title = $(this).attr('title');
                let content = $(this).siblings('span:first').html();
                simpleDialog(content, title);
            };

            module.exportOk = function() {
                window.location.href = module.exportUrl+'&sep='+module.export_sep+'&ver='+module.export_ver;
            };

            module.export = function() {
                module.export_sep='line';
                switch ($(this).attr('name')) {
                    case 'cdos-btn-export-space': module.export_sep='space'; break;
                    case 'cdos-btn-export-pipe': module.export_sep='pipe'; break;
                    default: break;
                }
                $('button.cdos-btn-export').removeClass('active');
                $('button.cdos-btn-export').removeAttr('aria-current');
                $(this).addClass('active');
                $(this).attr('aria-current', 'true');

                module.export_ver = $('select[name=cdos-version]').val();
                let verLbl = $('select[name=cdos-version] option:selected').text();
                let confText = 'Export copy instructions:<ul><li>Version: '+verLbl+'</li><li>Repeat value separator: '+module.export_sep+'</li></ul>';
                simpleDialog(confText,'Copy Data on Save: Export CSV','cdos-export-dialog',350,null,'<?= \RCView::tt('global_53', false) ?>',
                    module.exportOk,
                    '<?= \RCView::tt('design_401', false) ?>'
                );
            };

            // region import code inspired by method in FormDisplayLogicSetup.js
            module.import_elements = {};
            module.import_container = document.querySelector('#cdos-import-file-container');

            module.import = function() {
                simpleDialog($('#cdos-import-dialog-text').html(),'Copy Data on Save: Import CSV','cdos-import-dialog',650,null,'<?= \RCView::tt('global_53', false) ?>',
                    module.importFile,'<?= \RCView::tt('asi_006', false) ?>');
                fitDialog($('#cdos-import-dialog'));
                $('#cdos-import-dialog').dialog().next().find('button:last').addClass('ui-priority-primary').prepend('<img src="'+app_path_images+'xls.gif"> ');
            };

            module.submitImportFile = function() {
                var data = new FormData(module.import_elements.uploadForm);

                // can't use module.ajax() with file upload because payload gets put through JSON.stringify()
                // module.ajax('< ?=static::IMPORT_ACTION?>', [data]).then(function(response) {

                module.sendAjaxRequest('POST', data, { processData: false, contentType: false, })
                    .done(function(response){
                        if (response && response.result==1) {
                            simpleDialog('The CSV file was successfully imported. This page will now reload to reflect the changes.', 'Copy Data on Save: Instruction Import Successful', null, 700, 'window.location.reload();');
                        } else if (response && response.result==0) {
                            const errorList = '<ul>' + response.errors.map(item => `<li>${item}</li>`).join('') + '</ul>'
                            simpleDialog('<p class="my-0">The CSV file could not be imported. The following errors were encountered:</p>'+errorList, 'Copy Data on Save: Instruction Import Failed', null, 700);
                        } else {
                            console.log('response:');
                            console.log(response);
                            simpleDialog(woops);
                        }
                    }).fail(function(response){
                        console.log(response);
                        if (response.hasOwnProperty('message') && response.message == 'JSON.parse: unexpected character at line 1 column 1 of the JSON data') {
                            simpleDialog('File upload could not be processed. Refresh this page and retry.'); // usually csrf expired
                        } else {
                            simpleDialog(woops);
                        }
                });
            };
            
            module.sendAjaxRequest = function(method, data, options = {}) {
                data.redcap_csrf_token = get_csrf_token(); // add the csrf token
                var dfd = $.Deferred();
                var base_params = {
                    url: module.importUrl,
                    type: method,
                    data: data,
                    dataType: 'json',
                };
                var params = $.extend(base_params, options);
                $.ajax(params)
                    .done( function( response, textStatus, jqXHR ) {
                        dfd.resolve(response);
                    }).fail( function( jqXHR, textStatus, errorThrown ) {
                    var response = {
                        status: "error",
                        //message: jqXHR.message
                        message: errorThrown
                    };
                    dfd.reject(response);
                });
                return dfd;
            }

            module.handleFileSelected = function(element) {
                // upload the selected file
                if(!!!element) return;
                var self = this; // to maintain the scope inside the event listeners
                // submit the upload form as a file is selected
                element.addEventListener('change', function(e) {
                    e.preventDefault();
                    self.submitImportFile();
                });
            };

            module.getFileInput = function() {
                // create a file input element and register it's event handler
                var fileInput = document.createElement('input');
                fileInput.setAttribute('type', 'file');
                fileInput.setAttribute('name', 'files');
                module.handleFileSelected(fileInput);
                return fileInput;
            };

            module.createUploadForm = function() {
                // create the upload form and add it to the container
                var uploadForm = createUploadForm('cdos-import-form', module.getFileInput()); // function createUploadForm() in base.js
                var action_input = document.createElement('input');
                action_input.setAttribute('type', 'hidden');
                action_input.setAttribute('name', 'ajax-action');
                action_input.setAttribute('value', '<?= static::IMPORT_ACTION ?>');
                uploadForm.appendChild(action_input);
                module.import_elements.uploadForm = uploadForm;
                module.import_container.appendChild(uploadForm);
            };

            module.importFile = function() {
                // open the "select file" dialog box
                if(!module.import_elements.uploadForm) module.createUploadForm();
                var fileInput = module.import_elements.uploadForm.querySelector('input[type="file"]');
                fileInput.click();
            };
            // end region import code

            module.importInfo = function() {
                simpleDialog(
                    $('#cdos-import-info').html(),
                    'Copy Data on Save: Instruction Import Information',
                    'cdos-import-info-dialog',850
                );
            };

            module.editModuleSettings = function() {
                window.location.href = app_path_webroot + 'ExternalModules/manager/project.php?pid='+pid+'&cdos_config=1';
            };

            module.init = function() {
                $('span.cdos-seq').each(function(i,e){ $(e).html(i+1) });
                $('button.cdos-btn-show').on('click', module.show);
                $('#cdos-summary-table').DataTable({ paging: false });
                $('button.cdos-btn-export').on('click', module.export);
                $('#cdos-btn-import').on('click', module.import);
                $('#cdos-btn-import-info').on('click', module.importInfo);
                $('#cdos-btn-edit').on('click', module.editModuleSettings);
            };
            $(document).ready(function(){
                module.init();
            });
        </script>
        <?php
    }

    /**
     * redcap_every_page_top()
     * EM Manager page in project: 
     * - include a link to the summary page
     * - auto-open config settings when entering from summary page button click
     */
    public function redcap_every_page_top($project_id) {
        if (!defined('PAGE')) return;
        if (empty($project_id) || PAGE!=='manager/project.php') return;

        $summaryPageUrl = $this->getUrl('summary.php',false,false);
        ?>
        <script type="text/javascript">
            /*CDoS summary page link*/
            $(document).ready(function(){
                let url = '<?=$summaryPageUrl?>';
                let loc = $('tr[data-module="copy_data_on_save"] div.external-modules-description');
                $(loc).append('<div class="mt-1"><a href="'+url+'"><i class="fa-solid fa-list-ol" style="margin-right: 5px;"></i> View Summary of Copy Instructions</a>')
            });
        </script>
        <?php
        if (isset($_GET['cdos_config'])) {
            ?>
            <script type="text/javascript">
                /*CDoS auto-config*/
                $(window).on('load', function() {
                    history.pushState({}, null, location.href.split("&cdos_config")[0]);
                    setTimeout(function() {
                        $('tr[data-module="copy_data_on_save"] button.external-modules-configure-button').trigger('click');
                    }, 1000);
                });
            </script>
            <?php
        }
    }

    public function userHasPermission(): bool {
        global $user_rights;
        $user_rights = (isset($user_rights) && is_array($user_rights)) ? $user_rights : array();
        $modulePermission = $this->getSystemSetting('config-require-user-permission');
        if ($modulePermission) {
            $userHasPermission = (is_array($user_rights['external_module_config']) && in_array('copy_data_on_save', $user_rights['external_module_config']) || (defined('SUPER_USER') && SUPER_USER && !\UserRights::isImpersonatingUser()));
        } else {
            $userHasPermission = ($user_rights['design'] || (defined('SUPER_USER') && SUPER_USER && !\UserRights::isImpersonatingUser()));
        }
        return $userHasPermission;
    }

    public function export(?string $separatorOption=null, ?int $version=null): void {
        global $project_id;
        $instructions = array();

        switch ($separatorOption) {
            case 'space': $repeatValueSeparator = ' '; break;
            case 'pipe': $repeatValueSeparator = '|'; break;
            default: $separatorOption = 'line'; $repeatValueSeparator = PHP_EOL; break;
        }
        \UIState::saveUIStateValue($project_id, 'cdos-export', 'repeat-separator', $separatorOption);
        
        $delimiter = \User::getCsvDelimiter();
        if ($delimiter == 'tab' || $delimiter == 'TAB') $delimiter = "\t";

        if ($version === 0) {
            // just read current settings
            $instructions = $this->getSubSettings('copy-config');
        } else {
            // get settings from logged history
            $msm = new ModuleSettingsManager($this);
            $loggedSettings = $msm->getSettingsHistoryByLogId($version);
            $moduleSettings = $loggedSettings[0]['module_settings'];
            $settingConfig = $this->getSettingConfig('copy-config');
            $instructions = $msm->getSubSettingsFromSettingsArray($moduleSettings, $settingConfig);
        }           

        // make export file contents 
        $filename = "Copy_Data_on_Save_Export_pid".$project_id."_".date("Y-m-d_Hi");
        $titles = array('Description','Enabled','Trigger form(s)','Trigger condition','Destination project','Destination event','Record id source','Record matching option','DAG option','Field map - source','Field map - destination','Field map - only if empty');

        $fp = fopen(APP_PATH_TEMP.$filename, 'w');
        fputcsv($fp, $titles, $delimiter, '"', '');

        foreach ($instructions as $instruction) {
            $instructionRow = array_fill(0, 12, null);
            foreach ($instruction as $key => $value) {
                switch ($key) {
                    case 'section-description':
                        $instructionRow[0] = $value ?? ''; // (str_contains($value, $delimiter)) ? '"'.str_replace('"','""',$value).'"' : $value;
                        break;
                    case 'copy-enabled':
                        $instructionRow[1] = ($value==1) ? 1 : 0;
                        break;
                    case 'trigger-form':
                        $instructionRow[2] = implode($repeatValueSeparator, $value); // array of form names
                        break;
                    case 'trigger-logic':
                        $instructionRow[3] = $value ?? ''; // (str_contains($value, $delimiter)) ? '"'.str_replace('"','""',$value).'"' : $value;
                        break;
                    case 'dest-project':
                        $instructionRow[4] = $value ?? '';
                        break;
                    case 'dest-event':
                        $instructionRow[5] = $value ?? '';
                        break;
                    case 'record-id-field':
                        $instructionRow[6] = $value ?? '';
                        break;
                    case 'record-create':
                        $instructionRow[7] = $value ?? '';
                        break;
                    case 'dag-option':
                        $instructionRow[8] = $value ?? '';
                        break;
                    case 'copy-fields':
                        $sfArray = $dfArray = $oeArray = array();
                        foreach ($value as $cfValue) {
                            $sfArray[] = $cfValue['source-field'] ?? '';
                            $dfArray[] = $cfValue['dest-field'] ?? '';
                            $oeArray[] = ($cfValue['only-if-empty']==1) ? 1 : 0;
                        }
                        $instructionRow[9] = implode($repeatValueSeparator, $sfArray);
                        $instructionRow[10] = implode($repeatValueSeparator, $dfArray);
                        if (array_sum($oeArray)===0) {
                            $instructionRow[11] = ''; // if all fields can overwrite then leave last col empty for ease of reading
                        } else {
                            $instructionRow[11] = implode($repeatValueSeparator, $oeArray);
                        }
                        break;
                    default: break;
                }
            }
            fputcsv($fp, $instructionRow, $delimiter, '"', '');
        }
    	fclose($fp);

        // Output to file
        header('Pragma: anytextexeptno-cache', true);
        header("Content-type: application/csv");
        header("Content-Disposition: attachment; filename=$filename.csv");

        $fp = fopen(APP_PATH_TEMP.$filename, 'rb');
        print \addBOMtoUTF8(fread($fp, filesize(APP_PATH_TEMP.$filename)));

        // Close file and delete it from temp directory
        fclose($fp);
        unlink(APP_PATH_TEMP.$filename);

        $this->log('Export instruction list', array( 'user' => USERID ));
    }

    /**
     * import()
     * Nb. can't use redcap_module_ajax() for file submit
     * @param string ajax-action
     * @return array
     */
    public function import($action): array {
        global $project_id;
        $configArray = $this->getConfigArray();
        if (!is_array($configArray['auth-ajax-actions']) || !in_array($action, $configArray['auth-ajax-actions'])) return array('Invalid action submitted');
        if (empty($project_id)) return array('Could not detect project');

        $files = \FileManager::getUploadedFiles();

        if(count($files)>1) return array('Multiple files uploaded');
        if (count($files)==0) return array('No file uploaded');

        $file = array_pop($files);

        if (!ends_with($file['name'], '.csv') || !in_array($file['type'], array('text/csv', 'text/plain','application/vnd.ms-excel','application/csv'))) {
            return array('File "'.$file['name'].'" is not a CSV file');
        }

        $csvArray = \FileManager::readCSV($file['tmp_name'], 0, \User::getCsvDelimiter());

        if (!is_array($csvArray)) return array('Could not read CSV file rows');
        if (count($csvArray) === 0) return array('File contains no rows');
        if (count($csvArray) === 1) return array('File contains header row but no copy instructions');
        
        $currentSettings = $this->getProjectSettings();

        $errors = array();
        $instructions = array();
        $instructionSettings = $this->getInstructionSettingKeys();
        $instructionSettings = array_map(function() { return array(); }, array_flip($instructionSettings));
        foreach ($csvArray as $rowIndex => $row) {
            $importColumns = array(
                'section-description' => null,
                'copy-enabled' => null,
                'trigger-form' => null,
                'trigger-logic' => null,
                'dest-project' => null,
                'dest-event' => null,
                'record-id-field' => null,
                'record-create' => null,
                'dag-option' => null,
                'copy-fields-source' => null,
                'copy-fields-dest' => null,
                'copy-fields-ifempty' => null
            );
            
            if (count($row) < count($importColumns)) {
                $errors[] = "File row $rowIndex: expected ".count($importColumns)." values, found only ".count($row);
                continue;
            }
            if ($rowIndex===0) continue; // don't need to validate column heading text
            
            $importColumnsKeys = array_keys($importColumns);

            for ($i=0; $i < count($importColumns); $i++) { 
                $importColumns[$importColumnsKeys[$i]] = $row[$i];
            }

            // if changing the destination pid for an existing instruction, validate that user has design rights in destination project
            if (array_key_exists($rowIndex-1, $currentSettings['dest-project'])) {
                $currentPid = $currentSettings['dest-project'][$rowIndex-1];
                if ($currentPid != $importColumns['dest-project'] && !$this->userPidDesign($importColumns['dest-project'])) {
                    $errors[] = "File row $rowIndex: you cannot update the destination project id for instruction #$rowIndex from $currentPid to ".$importColumns['dest-project']." because you do not have \"Design & Setup\" rights in project ".$importColumns['dest-project'];
                    continue;
                }
            }

            // prepare and validate repeating settings: trigger form(s), source/destination field pairs and "only-if-empty"
            $importColumns['trigger-form'] = $this->makeRepeatingSetting($importColumns['trigger-form'] ?? '');
            $importColumns['copy-fields-source'] = $this->makeRepeatingSetting($importColumns['copy-fields-source'] ?? '');
            $importColumns['copy-fields-dest'] = $this->makeRepeatingSetting($importColumns['copy-fields-dest'] ?? '');
            $importColumns['copy-fields-ifempty'] = $this->makeRepeatingSetting($importColumns['copy-fields-ifempty'] ?? '');
            
            $nSource = count($importColumns['copy-fields-source']);
            $nDest = count($importColumns['copy-fields-dest']);
            $nIfEmpty = count($importColumns['copy-fields-ifempty']);

            if ($nSource !== $nDest) {
                $errors[] = "File row $rowIndex: count of destination fields ($nDest) does not match count of source fields ($nSource) ";
            }
            if ($nIfEmpty === 1 && $importColumns['copy-fields-ifempty'][0]==='') {
                $importColumns['copy-fields-ifempty'] = array_fill(0, $nSource, false);
            } else if ($nSource !== $nIfEmpty) {
                $errors[] = "File row $rowIndex: count of values for \"only if empty\" ($nDest) does not match count of source fields ($nSource) (or can be empty)";
            }

            $copyFields = array();
            foreach ($importColumns['copy-fields-source'] as $idx => $value) {
                $copyFields[] = array(
                    'source-field' => $value,
                    'dest-field' => $importColumns['copy-fields-dest'][$idx],
                    'only-if-empty' => $importColumns['copy-fields-ifempty'][$idx]
                );
            }
            $importColumns['copy-fields'] = $copyFields;
            unset($importColumns['copy-fields-source']);
            unset($importColumns['copy-fields-dest']);
            unset($importColumns['copy-fields-ifempty']);

            try {
                $instructions[] = $instruction = new CopyInstruction($importColumns, $rowIndex+1);

                foreach ($instruction->getConfigErrors() as $ce) {
                    $errors[] = "File row $rowIndex: ".$ce;
                }
            } catch (\Throwable $th) {
                $errors[] = "File row $rowIndex: ".$th->getMessage();
            }
        }

        if (count($errors)) return $errors;

        // merge uploaded copy-fields instructions into project settings, check for changes, then save
        $newSettings = $currentSettings; // php arrays copy by val not ref
        $newSettings['copy-config'] = array_fill(0, $rowIndex, 'true'); // copy-config has one element per instruction
        foreach ($instructions as $idx => $instruction) {
            $instructionSettings = $instruction->getAsModuleSettings();
            foreach ($instructionSettings as $key => $value) {
                $newSettings[$key][$idx] = $value; // e.g. $newSettings['section-description'][0] = 'this is the desc for the first instruction'
            }
        }

        $checkKeys = array('section-description','copy-enabled','trigger-form','trigger-logic','dest-project','dest-event','record-id-field','record-create','dag-option','copy-fields','source-field','dest-field','only-if-empty');
        if (
            ModuleSettingsManager::are_equal(
                ModuleSettingsManager::keep_keys($newSettings, $checkKeys), 
                ModuleSettingsManager::keep_keys($currentSettings, $checkKeys)
            ) ) 
        {
            return array('No changes to copy instructions detected');
        }

        try {
            $msm = new ModuleSettingsManager($this);
            $msm->saveSettingsToHistory($newSettings);
        } catch (\Throwable $th) {
            $errors[] = 'Could not save results: '.$th->getMessage();
        }

        return $errors;
    }

    /**
     * userPidDesign()
     * Does current user have design rights in project?
     * @param mixed project id
     * @return bool
     */
    protected function userPidDesign($pid): bool {
        if ($this->isSuperUser()) return true;
        $design = 0;
        $sql = "SELECT COALESCE(r.design, u.design, 0) as design 
                FROM redcap_projects p
                INNER JOIN redcap_user_rights u ON p.project_id = u.project_id
                LEFT OUTER JOIN redcap_user_roles r ON u.role_id = r.role_id
                WHERE p.project_id = ? 
                AND u.username = ? 
                AND p.date_deleted IS NULL
                AND p.status IN (0,1) 
                AND p.completed_time IS NULL";

        $q = $this->query($sql, [$pid, $this->getUser()->getUsername()]);
        
        while ($row = $q->fetch_assoc($q)) {
            $design = $row['design'];
        }
        return (bool)$design;
    }

    /**
     * makeRepeatingSetting()
     * @param string setting value as string 
     * @return array setting value separated into array by line, space, or pipe, whichever produces most elements
     */
    protected function makeRepeatingSetting(string $settingString): array {
        $separators = array(PHP_EOL, ' ', '|');
        $settingArray = array();
        foreach ($separators as $sep) {
            $split = explode($sep, trim($settingString));
            if (count($split) > count($settingArray)) $settingArray = $split;
        }
        return $settingArray;
    }

    protected function getInstructionSettingKeys(): array {
        $keys = array();
        $config = $this->getConfig();
        foreach ($config as $key => $settings) {
            if ($key !== 'project-settings') continue;
            foreach ($settings as $setting) {
                if ($setting['key'] !== 'copy-config') continue;
                    foreach ($setting['sub_settings'] as $ss) {
                        if ($ss['type'] !== 'descriptive') $keys[] = $ss['key'];
                    }
                break;
            }
            break;
        }
        return $keys;
    }

    /**
     * redcap_module_save_configuration()
     * Triggered after a module configuration is saved.
     * Capture history when instructions updated.
     */
    public function redcap_module_save_configuration($project_id) {
        if (empty($project_id)) return;
        $msm = new ModuleSettingsManager($this);
        $msm->saveCurrentSettingsToHistory();
    }
}
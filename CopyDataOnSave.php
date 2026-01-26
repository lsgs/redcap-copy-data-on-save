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

class CopyDataOnSave extends AbstractExternalModule {
    protected const DISPLAY_MAX_FIELD_MAP = 5;
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

            $destProjectId = $copyInstruction->destination_project->project_id;
            $destEventName = $copyInstruction->destination_event;
            $recIdField = $copyInstruction->record_id_field;
            $recMatchOpt = $copyInstruction->record_match_option;
            $dagOption = $copyInstruction->dag_option;
            $dagMap = $instruction['dag-map']; // deprecated
            $copyFields = $copyInstruction->copy_fields;

            $readSourceFields[] = $recIdField;
            if ($copyInstruction->destination_event_source == CopyInstruction::evtSourceField) $readSourceFields[] = $copyInstruction->destination_event;

            $sourceInstanceField = null;
            foreach ($copyFields as $cf) {
                $readSourceFields[] = $cf['source-field'];
                $readDestFields[] = $cf['dest-field'];
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

            if ($copyInstruction->destination_event_source == CopyInstruction::evtSourceField) {
                // if using source field for destination event name then reaad the value and validate
                $sourceEventFieldForm = $this->sourceProj->metadata[$copyInstruction->destination_event]['form_name'];
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
                    $destEventName = $this->sourceProjectData[$record]['repeat_instances'][$event_id][$sourceEventFieldRptFormKey][$repeat_instance][$copyInstruction->destination_event];
                } else {
                    $destEventName = $this->sourceProjectData[$record][$event_id][$copyInstruction->destination_event];
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
                    if ($destInstance == 'new') {
                        $valueInDest = '';
                    } else if (!(isset($destProjectData[$destRecord]['repeat_instances'][$destEventId][$rptInstrumentKeyDest][$destInstance][$df]))) {
                        $valueInDest = '';
                    } else {
                        $valueInDest = $destProjectData[$destRecord]['repeat_instances'][$destEventId][$rptInstrumentKeyDest][$destInstance][$df];
                    }

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
                $this->setProjectSettings($project_settings, $project_id);
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
     * 
     */
    public function summaryPage() {
        $instructions = $this->getSubSettings('copy-config');
        $columns = array(
            array('title'=>'#','tdclass'=>'text-center','getter'=>function(array $instruction){ return '<span class="cdos-seq"></span>'; }),
            array('title'=>'Description','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $desc = $instruction['section-description'];
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
                    $badge = ($copyInstruction->destination_event_source==CopyInstruction::evtSourceField) ? 'bg-primary' : 'bg-secondary';
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

        echo '<div class="projhdr"><i class="fa-solid fa-file-export mr-1"></i>Copy Data on Save: Summary of Copy Instructions</div>';
        echo '<p>The table below shows the configuration settings for the copy instructions set up in this project.</p>';
        echo '<div id="cdos-summary-table-container">';
        echo '<table id="cdos-summary-table"><thead><tr>';

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
        $this->initializeJavascriptModuleObject();
        ?>
        <style type="text/css">
            #cdos-summary-table-container { max-width: 800px; }
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
                max-height: 2em; /* Optional: sets a max height if the above isn't supported */
                line-height: 1em; /* Ensure line-height is set correctly */
            }
        </style>
        <script type="text/javascript">
            let module = <?=$this->getJavascriptModuleObjectName()?>;
            module.show = function() {
                let title = $(this).attr('title');
                let content = $(this).siblings('span:first').html();
                simpleDialog(content, title);
            };

            module.init = function() {
                $('span.cdos-seq').each(function(i,e){ $(e).html(i+1) });
                $('button.cdos-btn-show').on('click', module.show);
                $('#cdos-summary-table').DataTable({
                    paging: false
                });
            };
            $(document).ready(function(){
                module.init();
            });
        </script>
        <?php
    }

    public function redcap_every_page_top($project_id) {
        if (!defined('PAGE')) return;
        if (!empty($project_id) && PAGE==='manager/project.php') {
            $url = $this->getUrl('summary.php',false,false);
            ?>
            <script type="text/javascript">
                $(document).ready(function(){
                    let url = '<?=$url?>';
                    let loc = $('tr[data-module="copy_data_on_save"] div.external-modules-description');
                    $(loc).append('<div class="mt-1"><a href="'+url+'"><i class="fa-solid fa-list-ol" style="margin-right: 5px;"></i> View Summary of Copy Instructions</a>')
                });
            </script>
            <?php
        }
    }
}
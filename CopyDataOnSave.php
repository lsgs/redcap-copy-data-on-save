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

class CopyDataOnSave extends AbstractExternalModule {
    protected const DISPLAY_MAX_FIELD_MAP = 5;
    protected $sourceProj;
    protected $sourceProjectData;
    protected $destProj;
    protected $configArray;

	/** @var int Match record id (do not create) = 0 */
	const rmoDoNotCreate = 0;
	/** @var int Match record id (create matching) = 1 */
	const rmoCreateMatching = 1;
	/** @var int Match record id (create auto-numbered) = 2 */
	const rmoCreateAutoNumbered = 2;
	/** @var int Look up via secondary unique field = 3 */
	const rmoLookupSUF = 3;

    public function redcap_save_record($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
        global $Proj;

        if ($this->getProjectSetting('delay') && $this->delayModuleExecution()) return; 

        $this->sourceProj = $Proj;
        $settings = $this->getSubSettings('copy-config');

        foreach($settings as $instructionNum => $instruction) {
            if (!$instruction['copy-enabled']) continue; 
            if (array_search($instrument, $instruction['trigger-form'])===false) continue;
            $repeat_instrument = $Proj->isRepeatingForm($event_id, $instrument) ? $instrument : "";
            if (!empty($instruction['trigger-logic']) && true!==\REDCap::evaluateLogic($instruction['trigger-logic'], $project_id, $record, $event_id, $repeat_instance, $repeat_instrument)) continue;

            $destProjectId = $instruction['dest-project'];
            $destEventName = $instruction['dest-event'];
            $recIdField = $instruction['record-id-field'];
            $recMatchOpt = intval($instruction['record-create']);
            $dagOption = $instruction['dag-option'];
            $dagMap = $instruction['dag-map'];
            $copyFields = $instruction['copy-fields'];

            $readSourceFields[] = $recIdField;

            foreach ($copyFields as $cf) {
                $readSourceFields[] = $cf['source-field'];
                $readDestFields[] = $cf['dest-field'];
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
            
            if ($recMatchOpt==self::rmoDoNotCreate || $recMatchOpt==self::rmoLookupSUF) { 
                // Matching destination record not found, do not create
                if ($destRecord=='') continue; 
            } else if ($recMatchOpt==self::rmoCreateAutoNumbered) { 
                // To copy, either lookup field is empty and destination is next autonumbered, or lookup and dest match
                if (!(($matchValue=='' && $destRecord!='') || $matchValue!='' && $matchValue==$destRecord)) continue;

            } else if ($recMatchOpt==self::rmoCreateMatching) { 
                // If have no id for destination then something amiss
                if ($destRecord=='') continue;
            } else {
                // Other options not implemented
                continue;
            }

            if (empty($destEventName)) {
                $destEventId = $this->destProj->firstEventId;
            } else {
                $destEventId = $this->destProj->getEventIdUsingUniqueEventName($destEventName);
                if (!$destEventId) {
                    $this->log("Error in Copy Data on Save instruction #$instructionNum configration: invalid destination event '$destEventName'");
                    continue;
                }
            }

            $readDestFields[] = $this->destProj->table_pk;

            $destProjectData = \REDCap::getData(array(
                'return_format' => 'array', 
                'project_id' => $destProjectId,
                'records' => $destRecord, 
                'fields' => $readDestFields,
                'exportDataAccessGroups' => true
            ));

            if (($recMatchOpt==self::rmoDoNotCreate || $recMatchOpt==self::rmoLookupSUF) && !array_key_exists($destRecord, $destProjectData)) continue; // do not create new record 

            $saveArray = array();
            $fileCopies = array();
            $fileDeletes = array();
            $overwriteBlocked = array();
            foreach ($copyFields as $cf) {
                $sf = $cf['source-field'];
                $df = $cf['dest-field'];
                $noOverwrite = $cf['only-if-empty'];

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
                    if ($rptFrmInSource || $rptEvtInSource) {
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
                    $valueInDest = $destProjectData[$destRecord]['repeat_instances'][$destEventId][$rptInstrumentKeyDest][$destInstance][$df];
                } else {
                    $destInstance = null;
                    $valueInDest = $destProjectData[$destRecord][$destEventId][$df];
                }

                if ($valueInDest!='' && $noOverwrite) {
                    $overwriteBlocked[] = "$sf=>$df"; // update only if destination empty
                } else {
                    if ($this->sourceProj->metadata[$df]['element_type'] == 'file') {
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
                                    'doc_id' => \REDCap::copyFile($valueToCopy, $project_id), 
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

            if ($dagOption > 0) {
                if ($Proj->isRepeatingEvent($event_id)) {
                    $sdag = $this->sourceProjectData[$record]['repeat_instances'][$event_id][''][$repeat_instance]['redcap_data_access_group'] ?? '';
                } else if ($Proj->isRepeatingForm($event_id, $instrument)) {
                    $sdag = $this->sourceProjectData[$record]['repeat_instances'][$event_id][$instrument][$repeat_instance]['redcap_data_access_group'] ?? '';
                } else {
                    $sdag = $this->sourceProjectData[$record][$event_id]['redcap_data_access_group'] ?? '';
                }

                switch ("$dagOption") {
                    case "1": // dest same as source
                        $saveArray[$destRecord][$destEventId]['redcap_data_access_group'] = $sdag;
                        break;
                    case "2": // map
                        $ddag = '';
                        if ($sdag!='') {
                            foreach ($dagMap as $dm) {
                                if ($sdag==\REDCap::getGroupNames(true, $dm['source-dag'])) {
                                    $ddag = $dm['dest-dag'];
                                    // break; // don't break so last wins, not first, if source dag specified more than once (same behaviour as for source fields)
                                }
                            }
                        }
                        $saveArray[$destRecord][$destEventId]['redcap_data_access_group'] = $ddag;
                        break;
                    default: // ignore or n/a
                        break;
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
                if ($recMatchOpt==self::rmoCreateAutoNumbered && $destRecord!='') {
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
        if ($this->sourceProj->project_id===$this->destProj->project_id && $recIdField === $this->sourceProj->table_pk) return $record; // copying within the same project & record
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

            if ($recMatchOpt==self::rmoLookupSUF) {
                $params['filterLogic'] = '[first-event-name]['.$this->destProj->project['secondary_pk']."]='$lookupRecordId'"; // assume 2nd id is in first event for now
            } else {
                $params['records'] = $lookupRecordId;
            }

            $destData = \REDCap::getData($params);
        }

        switch ($recMatchOpt) {
            case self::rmoDoNotCreate: // Match record id (do not create)
                $destRecordId = (count($destData)) ? $lookupRecordId : ''; // return matched record id if already exists, empty if not
                break;
            case self::rmoCreateMatching: // Match record id (create matching)
                $destRecordId = $lookupRecordId; // use lookup value and create if not existing - match not required
                break;
            case self::rmoCreateAutoNumbered: // Match record id (create auto-numbered)
                $destRecordId = (count($destData)) ? $lookupRecordId : \REDCap::reserveNewRecordId($this->destProj->project_id); // return matched record id if already exists, reserve new if not
                break;
            case self::rmoLookupSUF: // Look up via secondary unique field
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
                    return '<i class="fas fa-minus text-muted"></i>';
                } else {
                    return '<span class="cdos-hidden">'.$this->escape($desc).'</span><button class="cdos-btn-show btn btn-xs btn-outline-primary" title="View Description"><i class="fas fa-comment-dots mx-2"></i></button>';
                }
            }),
            array('title'=>'Enabled','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                return '<i class="fas '.(($instruction['copy-enabled']) ? 'fa-check text-success' : 'fa-times text-danger').'"></i>';
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
                    return '<i class="fas fa-minus text-muted"></i>';
                } else {
                    return '<span class="cdos-hidden"><pre>'.\htmlspecialchars($logic,ENT_QUOTES).'</pre></span><button class="cdos-btn-show btn btn-xs btn-outline-primary" title="View Trigger Logic"><i class="fas fa-bolt mx-2"></i></button>';
                }
            }),
            array('title'=>'Destination Project','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                $destPid = $instruction['dest-project'];
                if (empty($destPid)) {
                    return '<i class="fas fa-minus text-danger"></i>';
                } else {
                    $destProj = new \Project($destPid);
                    $title = $this->escape($destProj->project['app_title']);
                    return "<span class='badge bg-secondary' title='$title'>$destPid</span>";
                }
            }),
            array('title'=>'Destination Event','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                return (empty($instruction['dest-event'])) ? '<i class="fas fa-minus text-muted"></i>' : '<span class="badge bg-secondary">'.$instruction['dest-event'].'</span>';
            }),
            array('title'=>'Record ID Field','tdclass'=>'text-center','getter'=>function(array $instruction){ 
                return (empty($instruction['record-id-field'])) ? '<i class="fas fa-minus text-danger"></i>' : '<span class="badge bg-primary">'.$instruction['record-id-field'].'</span>';
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
                        $o = ($cf['only-if-empty']) ? '<i class="fas fa-times-circle text-secondary" title="Only if empty"></i>' : '<i class="fas fa-circle-check text-primary" title="Can overwrite"></i>';
                        $fieldList[] = "<span class='nowrap'>$s<i class='fas fa-chevron-right text-muted mx-1'></i>$d $o</span>";
                    }
                }
                if (count($fieldList) > self::DISPLAY_MAX_FIELD_MAP) {
                    $firstN = array_slice($fieldList, 0, self::DISPLAY_MAX_FIELD_MAP);
                    $return = '<span class="cdos-hidden"><div class="cdos-field-map-dialog-content">';
                    for ($i=0; $i < count($fieldList); $i++) { 
                        $return .= '<div class="my-2"><span class="cdos-field-map-index">'.($i+1).'.</span>'.$fieldList[$i].'</div>';
                    }
                    $return .= '</div></span>';
                    $return .= implode('<br>', $firstN);
                    $return .= '<br><button class="cdos-btn-show btn btn-xs btn-outline-success mt-2" title="View All Field Mappings"><i class="fas fa-copy mr-1"></i>+'.(count($fieldList)-self::DISPLAY_MAX_FIELD_MAP).'</button>';
                } else {
                    $return = implode('<br>', $fieldList);
                }
                return $return; 
            })
        );

        echo '<div class="projhdr"><i class="fas fa-file-export mr-1"></i>Copy Data on Save: Summary of Copy Instructions</div>';
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
}
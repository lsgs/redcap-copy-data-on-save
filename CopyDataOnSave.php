<?php

namespace MCRI\CopyDataOnSave;

use ExternalModules\AbstractExternalModule;

class CopyDataOnSave extends AbstractExternalModule {

    public function redcap_save_record($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
        global $Proj;
        $settings = $this->getSubSettings('copy-config');

        foreach($settings as $instructionNum => $instruction) {
            if (!$instruction['copy-enabled']) continue; 
            if (array_search($instrument, $instruction['trigger-form'])===false) continue;
            if (!empty($instruction['trigger-logic']) && true!==\REDCap::evaluateLogic($instruction['trigger-logic'], $project_id, $record, $event_id, $repeat_instance)) continue;

            $destProjectId = $instruction['dest-project'];
            $destEventName = $instruction['dest-event'];
            $recIdField = $instruction['record-id-field'];
            $recCreate = $instruction['record-create'];
            $dagOption = $instruction['dag-option'];
            $dagMap = $instruction['dag-map'];
            $copyFields = $instruction['copy-fields'];

            $readSourceFields[] = $recIdField;

            foreach ($copyFields as $cf) {
                $readSourceFields[] = $cf['source-field'];
                $readDestFields[] = $cf['dest-field'];
            }

            $sourceProjectData = \REDCap::getData(array(
                'return_format' => 'array', 
                'records' => $record, 
                'fields' => $readSourceFields,
                'exportDataAccessGroups' => true
            ));

            $destProj = new \Project($destProjectId);
            $destRecord = $this->getDestinationRecordId($record, $event_id, $repeat_instance, $recIdField, $sourceProjectData);
            if (empty($destEventName)) {
                $destEventId = $destProj->firstEventId;
            } else {
                $destEventId = $destProj->getEventIdUsingUniqueEventName($destEventName);
                if (!$destEventId) {
                    $this->log("Error in Copy Data on Save instruction #$instructionNum configration: invalid destination event '$destEventName'");
                    continue;
                }
            }

            $readDestFields[] = $destProj->table_pk;

            $destProjectData = \REDCap::getData(array(
                'return_format' => 'array', 
                'project_id' => $destProjectId,
                'records' => $destRecord, 
                'fields' => $readDestFields,
                'exportDataAccessGroups' => true
            ));

            if (!$recCreate && !array_key_exists($destRecord, $destProjectData)) continue; // create new record disabled

            $saveArray = array();
            $fileCopies = array();
            $fileDeletes = array();
            $overwriteBlocked = array();
            foreach ($copyFields as $cf) {
                $sf = $cf['source-field'];
                $df = $cf['dest-field'];
                $noOverwrite = $cf['only-if-empty'];

                $rptEvtInSource = $Proj->isRepeatingEvent($event_id);
                $rptEvtInDest = $destProj->isRepeatingEvent($destEventId);
                $rptFrmInSource = $Proj->isRepeatingForm($event_id, $Proj->metadata[$sf]['form_name']);
                $rptFrmInDest = $destProj->isRepeatingForm($destEventId, $destProj->metadata[$df]['form_name']);
                
                if ($rptFrmInSource) {
                    $rptInstrumentKeySrc = $Proj->metadata[$sf]['form_name'];
                } else {
                    $rptInstrumentKeySrc = ($rptEvtInSource) ? '' : null;
                }
                if ($rptFrmInDest) {
                    $rptInstrumentKeyDest = $destProj->metadata[$df]['form_name'];
                } else {
                    $rptInstrumentKeyDest = ($rptEvtInDest) ? '' : null;
                }

                /* behaviour with repeating data
                    Src  Dest Copy to
                    N    N    Non-rpt
                    Y    N    Non-rpt
                    N    Y    New instance*  only-if-empty when ticked means new instance only if value not same as last instance
                    Y    Y    Same instance
                */
                if ($rptFrmInSource || $rptEvtInSource) {
                    $valueToCopy = $sourceProjectData[$record]['repeat_instances'][$event_id][$rptInstrumentKeySrc][$repeat_instance][$sf];
                } else {
                    $valueToCopy = $sourceProjectData[$record][$event_id][$sf];
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
                    if ($Proj->metadata[$df]['element_type'] == 'file') {
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

            switch ("$dagOption") {
                case "1": // dest same as source
                    $saveArray[$destRecord][$destEventId]['redcap_data_access_group'] = $sourceProjectData[$record][$event_id]['redcap_data_access_group'];
                    break;
                case "2": // map
                    $sdag = $sourceProjectData[$record][$event_id]['redcap_data_access_group'];
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
                foreach ($fileDeletes as $deletedFile) { 
                    // No developer method for removing a file: DataEntry.php L5668 FILE UPLOAD FIELD: Set the file as "deleted" in redcap_edocs_metadata table
                    $instance = ($deletedFile['instance'] > 1) ? "instance = ".$this->escape($deletedFile['instance']) : "instance is null";
                    $sql_all[] = $sql = "update redcap_edocs_metadata e, redcap_data d left join redcap_data d2 
                            on d2.project_id = d.project_id and d2.value = d.value and d2.field_name = d.field_name and d2.record != d.record
                            set e.delete_date = ?
                            where e.project_id = ? and e.project_id = d.project_id
                            and d.field_name = ? and d.value = e.doc_id and d.record = ?
                            and d.$instance 
                            and e.delete_date is null and d2.project_id is null and e.doc_id = ?";
                    $this->query($sql, [NOW, $deletedFile['project_id'],$deletedFile['field_name'],$deletedFile['record'],$deletedFile['doc_id']]);
                    
                    $sql_all[] = $sql = "DELETE FROM redcap_data WHERE project_id = ? AND record = ? AND event_id = ? AND field_name = ? AND $instance ";
                    $this->query($sql, [$deletedFile['project_id'],$deletedFile['record'],$deletedFile['event_id'],$deletedFile['field_name']]);

                    \Logging::logEvent(implode('\n',$sql_all), 'redcap_data', 'UPDATE', $deletedFile['record'], $deletedFile['field_name']." = ''", 'Update record');
                }
            } catch (\Throwable $e) {
                $saveResult = array('errors'=>$e->getMessage());
            }

            $title = "CopyDataOnSave module";
            $detail = "Instruction #".($instructionNum+1);
            $detail .= " \nCopy from: record=$record, event=$event_id, instrument=$instrument, instance=$repeat_instance";
            $detail .= " \nCopy to: project_id=$destProjectId, record=$destRecord, event=$destEventId, instance=$destInstance";
    
            if ((is_array($saveResult['errors']) && count($saveResult['errors'])>0) || 
                (!is_array($saveResult['errors']) && !empty($saveResult['errors'])) ) {
                $title .= ": COPY FAILED ";
                $detail .= " \n".print_r($saveResult['errors'], true);
                \REDCap::logEvent($title, $detail, '', $record, $event_id);
            } else {
                if (count($overwriteBlocked)) $detail .= " \nCopy to non-empty fields skipped: ".implode(',', $overwriteBlocked);
                \REDCap::logEvent($title, $detail, '', $record, $event_id);
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
     * setDestinationRecordId
     * Get the record id to use in the destination from the source data - supports using field from repeating form/event
     * @param string $record
     * @param string $event_id
     * @param string $instance
     * @param string $recIdField
     * @param string $sourceProjectData
     * @return string
     * @since 1.1.0
     */
    protected function getDestinationRecordId($record, $event_id, $instance, $recIdField, $sourceProjectData) {
        global $Proj;
        if ($Proj->isRepeatingEvent($event_id)) {
            $destRecordId = $sourceProjectData[$record]['repeat_instances'][$event_id][''][$instance][$recIdField];
        } else if ($Proj->isRepeatingForm($event_id, $Proj->metadata[$recIdField]['form_name'])) {
            $destRecordId = $sourceProjectData[$record]['repeat_instances'][$event_id][$Proj->metadata[$recIdField]['form_name']][$instance][$recIdField];
        } else {
            $destRecordId = $sourceProjectData[$record][$event_id][$recIdField];
        }
        return $destRecordId;
    }
}
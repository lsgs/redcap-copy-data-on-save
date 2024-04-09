<?php

namespace MCRI\CopyDataOnSave;

use ExternalModules\AbstractExternalModule;

class CopyDataOnSave extends AbstractExternalModule {

function redcap_every_page_top($project_id)
    {
        if (PAGE == 'ProjectSetup/index.php') {
            if (isset($_GET['msg']) && $_GET['msg'] == 'copiedproject')
                $this->redcap_module_save_configuration($project_id);
        }
    }

	function redcap_save_record($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
        global $Proj;
		$settings = $this->getSubSettings('copy-config');

        foreach($settings as $instruction) {
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
            $saveValues = array();
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
                    if ($rptFrmInDest || $rptEvtInDest) {
                        $saveArray[$destRecord]['repeat_instances'][$destEventId][$rptInstrumentKeyDest][$destInstance][$df] = $valueToCopy;
                    } else {
                        $saveArray[$destRecord][$destEventId][$df] = $valueToCopy;
                    }
                }
            }

            switch ("$dagOption") {
                case "1": // dest same as source
                    $saveArray[$destRecord][$destProj->firstEventId]['redcap_data_access_group'] = $sourceProjectData[$record][$event_id]['redcap_data_access_group'];
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
                    $saveArray[$destRecord][$destProj->firstEventId]['redcap_data_access_group'] = $ddag;
                    break;
                default: // ignore or n/a
                    break;
            }

            try {
                $saveResult = \REDCap::saveData($destProjectId, 'array', $saveArray, 'overwrite');
            } catch (\Throwable $e) {
                $saveResult = array('errors'=>$e->getMessage());
            }

            $title = "CopyDataOnSave module";
            $detail = "Copy from: record=$record, event=$event_id, instrument=$instrument, instance=$repeat_instance";
            $detail .= " \nCopy to: project_id=$destProjectId, record=$destRecord";
    
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

	/**
     * redcap_module_save_configuration
     * Look up report ids and populate report-title settings
     * Look up user/profile and populate message-from-address settings
     * @param string $project_id
     */
    public function redcap_module_save_configuration($project_id)
    {
        if (is_null($project_id) || !is_numeric($project_id)) {
            return;
        } // only continue for project-level config changes
        $project_settings = $this->getProjectSettings($project_id);

        $update = false;
        if (!$project_settings['copy-enabled']) {
            return;
        } else {
            $project_settings['copy-enabled'] = [false];
            $update = true;
        }
        if ($update) {
            $this->setProjectSettings($project_settings, $project_id);
        }
        return;
    }
}

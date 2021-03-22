<?php

namespace MCRI\CopyDataOnSave;

use ExternalModules\AbstractExternalModule;

class CopyDataOnSave extends AbstractExternalModule {

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
            $destRecord = $sourceProjectData[$record][$event_id][$recIdField];
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

            if (!$recCreate && !array_key_exists($destRecord, $destProjectData)) continue; // can't create a new record

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
                        if (array_key_exists($rptInstrumentKeyDest, $destProjectData[$record]['repeat_instances'][$destEventId])
                                && is_array($destProjectData[$record]['repeat_instances'][$destEventId][$rptInstrumentKeyDest])) {
                            $destInstances = $destProjectData[$record]['repeat_instances'][$destEventId][$rptInstrumentKeyDest];
                            ksort($destInstances, SORT_NUMERIC);
                            $maxInstance = key(array_slice($destInstances, -1, 1, true));
                            $valInMax = $destProjectData[$record]['repeat_instances'][$destEventId][$rptInstrumentKeyDest][$maxInstance][$df];

                            if ($valueToCopy==$valInMax && $noOverwrite) continue; // skip creating a new instance as no new value

                            $destInstance = 1 + $maxInstance;
                        } else {
                            $destInstance = 1;
                        }
                    }
                    $valueInDest = $destProjectData[$record]['repeat_instances'][$destEventId][$rptInstrumentKeyDest][$destInstance][$df];
                } else {
                    $destInstance = null;
                    $valueInDest = $destProjectData[$record][$destEventId][$df];
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

            if ($dagOption > 0) {
                $saveArray[$destRecord][$destProj->firstEventId]['redcap_data_access_group'] = $sourceProjectData[$record][$event_id]['redcap_data_access_group'];
            }

            $saveResult = \REDCap::saveData($destProjectId, 'array', $saveArray, 'overwrite');

            $title = "CopyDataOnSave module";
            $detail = "Copy from: record=$record, event=$event_id, instrument=$instrument, instance=$repeat_instance";
            $detail .= " \nCopy to: project_id=$destProjectId, record=$destRecord";
    
            if (count($saveResult['errors'])>0) {
                $title .= ": COPY FAILED ";
                $detail .= " \n".print_r($saveResult['errors'], true);
                \REDCap::logEvent($title, $detail, '', $record, $event_id);
            } else {
                if (count($overwriteBlocked)) $detail .= " \nCopy to non-empty fields skipped: ".implode(',', $overwriteBlocked);
                \REDCap::logEvent($title, $detail, '', $record, $event_id);
            }
        }
	}
}
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
			$destKeyField = $instruction['destination-key-field'];
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
            $destRecord = $this->getDestinationRecordId($record, $event_id, $repeat_instance, $recIdField, $sourceProjectData, $destKeyField, $destProjectId, $recCreate);
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
    protected function getDestinationRecordId($record, $event_id, $instance, $recIdField, $sourceProjectData, $destKeyField, $destProjectId, $recCreate) {
        global $Proj;
        if ($Proj->isRepeatingEvent($event_id)) {
            $destRecordId = $sourceProjectData[$record]['repeat_instances'][$event_id][''][$instance][$recIdField];
        } else if ($Proj->isRepeatingForm($event_id, $Proj->metadata[$recIdField]['form_name'])) {
            $destRecordId = $sourceProjectData[$record]['repeat_instances'][$event_id][$Proj->metadata[$recIdField]['form_name']][$instance][$recIdField];
        } else if (!empty($destKeyField)) {
			
            $dest_value_to_match = $sourceProjectData[$record][$event_id][$recIdField];
			$params = array('return_format' => 'json', 'project_id' => $destProjectId, 'records' => NULL, 'fields' => NULL, 'filterLogic' => "[$destKeyField] = $dest_value_to_match");
            $result = \REDCap::getData($params);
			
			//check how many records are returned with the used filter.
			//if there are more than one record it means that the key_destination was not a unique value within the destination project and that it cannot be used as such.
			//in this case the EM should not run
			//If there is 1 or 0 record returned, the EM can run normally.
			
			// Decode the JSON into an associative array
			$resultArray = json_decode($result, true);
			// get the varname of the record_id
			$dest_record_id_name = key($resultArray[0]);

			// Count the number of objects/records returned
			$num_records = count($resultArray);
			
			if ($num_records > 1) {
				// Stop the rest of the code
				exit; // There are more than one record in the result
				
			} else if ($num_records==1) {
				$destRecordId = $resultArray[0][$dest_record_id_name]; // get/define the value of the record_id

			} else if ($num_records==0 && $recCreate) {
				//since this original code from this EM assume that a record_id value for the destination project is findable in the source project (and if not, no data are copied...
				//... and this branch looks for a record_id in the destination project and aims to create a new one if not found
				//a record_id is necessary to be put in the variable $destRecordId
				// the method "reserveNewRecordId" help us:
				//Reserve/Identify the new record ID in a project prior to creating the record:
				$destRecordId = \REDCap::reserveNewRecordId($destProjectId);
			} else {
			exit;
            }
              
	   } else {
		   $destRecordId = $sourceProjectData[$record][$event_id][$recIdField];
       }
        return $destRecordId;
    
}
}
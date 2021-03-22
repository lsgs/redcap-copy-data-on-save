<?php

namespace MCRI\CopyDataOnSave;

use ExternalModules\AbstractExternalModule;

class CopyDataOnSave extends AbstractExternalModule {

	function redcap_save_record($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
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

            $fieldMap = array();
            foreach ($copyFields as $cf) {
                $fieldMap[$cf['source-field']] = $cf['dest-field'];
                $noOverwriteMap[$cf['source-field']] = $cf['only-if-empty'];
            }

            $readSourceFields = array_keys($fieldMap);
            $readSourceFields[] = $recIdField;

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

            $readDestFields = array_values($fieldMap);
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
            $noOverwrite = array();
            foreach ($fieldMap as $sf => $df) {
                $valueToCopy = $sourceProjectData[$record][$event_id][$sf];
                $valueInDest = $destProjectData[$record][$destEventId][$df];
                if ($valueInDest!='' && $noOverwriteMap[$sf]) {
                    $noOverwrite[] = "$sf=>$df"; // update only if destination empty
                } else {
                    $saveValues[$df] = $valueToCopy;
                }
            }

            $saveArray[$destRecord][$destProj->firstEventId] = $saveValues;

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
                //$this->notify($title, $detail);
            } else {
                if (count($noOverwrite)) $detail .= " \nCopy to non-empty fields skipped: ".implode(',', $noOverwrite);
                \REDCap::logEvent($title, $detail, '', $record, $event_id);
            }
        }
	}
}
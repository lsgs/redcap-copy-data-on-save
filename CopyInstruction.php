<?php
/**
 * REDCap External Module: Copy Data on Save
 * Copy data from one place to another when you save a form.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\CopyDataOnSave;

class CopyInstruction {
    public $sequence;
    public $copy_enabled;
    public $trigger_form;
    public $trigger_logic;
    public $source_project;
    public $destination_project;
    public $destination_event;
    public $destination_event_source;
    public $record_id_field;
    public $record_match_option;
    public $dag_option;
    public $copy_fields;
    public $config_errors;

    /** @var int Event source specify unique name = 0 */
	const evtSourceName = 0;
    /** @var int Event source specify field = 1 */
	const evtSourceField = 1;

    public function __construct(array $instruction, ?int $instruction_index=null) {
        global $Proj;
        $this->sequence = ($instruction_index??0)+1;
        $this->source_project = $Proj;
        $this->config_errors = array();

        $simple_settings = array(
            'copy-enabled','trigger-form','trigger-logic','record-id-field','record-create','dag-option','copy-fields'
        );
        try {
            foreach ($simple_settings as $expected_setting) {
                if (array_key_exists($expected_setting, $instruction)) {
                    if ($expected_setting=='record-create') {
                        $this->record_match_option = intval($instruction[$expected_setting]);
                    } else if ($expected_setting=='dag-option') {
                        $this->dag_option = intval($instruction[$expected_setting]);
                    } else {
                        $prop = str_replace('-','_',$expected_setting);
                        $this->$prop = $instruction[$expected_setting];
                    }
                } else {
                    $this->config_errors[] = "missing expected instruction property: $expected_setting";
                }
            }
        } catch (\Throwable $th) {
            $this->config_errors[] = "error in module settings: ".$th->getMessage();
        }

        if (!empty($this->trigger_logic) && !\LogicTester::isValid($this->trigger_logic)) {
            $this->config_errors[] = "invalid trigger logic";
        }

        try {
            if (!isset($instruction['dest-project']) || empty(intval($instruction['dest-project']))) throw new \Exception('missing project id');
            $this->destination_project = new \Project($instruction['dest-project']);
        } catch (\Throwable $th) {
            $this->config_errors[] = "destination project not set: ".$th->getMessage();
        }

        if (isset($instruction['dest-event']) && !empty($instruction['dest-event'])) {
            $this->destination_event = $destEvent = $instruction['dest-event'];
            if (preg_match('/[^a-z0-9_]/', $destEvent)) {
                $this->config_errors[] = "destination event \"".htmlspecialchars($destEvent,ENT_QUOTES)."\" contains invalid characters";
            } else if (preg_match('/^[a-z0-9_]+_arm_\d+$/', $destEvent)) {
                // validate that this is a valid unique event name in the destination project
                if (!is_null($this->destination_project)) {
                    $destEventId = $this->destination_project->getEventIdUsingUniqueEventName($destEvent);
                    if (!$destEventId) {
                        $this->config_errors[] = "destination event name \"".htmlspecialchars($destEvent,ENT_QUOTES)."\" is not valid for project ".intval($this->destination_project->project_id);
                    }
                }
                $this->destination_event_source = self::evtSourceName;
            } else {
                // validate that this is a valid field name in the source project
                if (!is_null($this->source_project) ) {
                    if (!array_key_exists($destEvent, $this->source_project->metadata)) {
                        $this->config_errors[] = "destination event setting \"".htmlspecialchars($destEvent,ENT_QUOTES)."\" is not a valid field in this project, nor a valid event name in the destination project";
                    }
                }
                $this->destination_event_source = self::evtSourceField;
            }
        }

        if ($this->record_match_option == CopyDataOnSave::rmCreateAutoNumbered
                && $this->record_id_field == $this->source_project->table_pk) {
            $this->config_errors[] = "autonumbering option is incompatible with using source project record id field for matching";
        }

        if ($this->dag_option == CopyDataOnSave::dagMap) {
            $this->config_errors[] = "do not use \"dag mapping\" option - see documentation";
        }

        if (empty($this->copy_fields) || !is_array($this->copy_fields)) {
            $this->config_errors[] = "no fields to copy specified";
        } else {
            foreach ($this->copy_fields as $idx => $pair) {
                $source_field = $pair['source-field'];
                $dest_field = $pair['dest-field'];

                if (!is_null($this->source_project) ) {
                    if (empty($source_field) || !array_key_exists($source_field, $this->source_project->metadata)) {
                    $this->config_errors[] = "missing source field in copy fields pair #".($idx+1);
                    }
                }
                if (empty($dest_field)) {
                    $this->config_errors[] = "missing destination field in copy fields pair #".($idx+1);
                } else if (preg_match('/[^a-z0-9_]/', $dest_field)) {
                    $this->config_errors[] = "destination field \"".htmlspecialchars($dest_field,ENT_QUOTES)."\" has invalid characters in copy fields pair #".($idx+1);
                }
                if (!is_null($this->destination_project)) {
                    if (empty($dest_field) || ($dest_field!=='redcap_data_access_group' && $dest_field!=='redcap_repeat_instance' && !array_key_exists($dest_field, $this->destination_project->metadata))) {
                        $this->config_errors[] = "destination field \"".htmlspecialchars($dest_field,ENT_QUOTES)."\" not in destination project in copy fields pair #".($idx+1);
                    }
                }
            }
        }
    }

    public function getConfigErrors(): array {
        return $this->config_errors;
    }
}
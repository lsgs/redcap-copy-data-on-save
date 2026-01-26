<?php
/**
 * REDCap External Module: Copy Data on Save
 * Copy data from one place to another when you save a form.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\CopyDataOnSave;

class CopyInstruction {
    public $sequence;
    public $section_description;
    public $copy_enabled;
    public $trigger_form;
    public $trigger_logic;
    public $source_project;
    public $dest_project;
    public $dest_event;
    public $dest_event_source;
    public $record_id_field;
    public $record_match_option;
    public $dag_option;
    public $copy_fields;
    public $config_errors;
    public $config_warnings;

    /** @var int Event source specify unique name = 0 */
	const evtSourceName = 0;
    /** @var int Event source specify field = 1 */
	const evtSourceField = 1;

    public function __construct(array $instruction, ?int $instruction_index=null) {
        global $Proj;
        $this->sequence = ($instruction_index??0)+1;
        $this->source_project = $Proj;
        $this->config_errors = array();
        $this->config_warnings = array();
        if (!array_key_exists('section-description',$instruction)) $instruction['section-description'] = null;

        $simple_settings = array(
            'section-description','copy-enabled','trigger-form','trigger-logic','record-id-field','record-create','dag-option','copy-fields'
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

        if (!($this->copy_enabled == 0 || $this->copy_enabled == 1)) {
            $this->config_errors[] = "invalid value for copy enabled: 0 or 1 expected";
        } else {
            $this->copy_enabled = (bool)$this->copy_enabled;
        }

        if (!empty($this->trigger_form)) {
            $badFormNames = array_diff($this->trigger_form, array_keys($this->source_project->forms));
            if (!empty($badFormNames)) $this->config_errors[] = "invalid trigger form(s): ".implode(', ', $badFormNames);
        }

        if (!empty($this->trigger_logic) && !\LogicTester::isValid($this->trigger_logic)) {
            $this->config_errors[] = "invalid trigger logic";
        }

        try {
            if (!isset($instruction['dest-project']) || empty(intval($instruction['dest-project']))) throw new \Exception('missing project id');
            $this->dest_project = new \Project($instruction['dest-project']);
        } catch (\Throwable $th) {
            $this->config_errors[] = "destination project not set: ".$th->getMessage();
        }

        if (isset($instruction['dest-event']) && !empty($instruction['dest-event'])) {
            $this->dest_event = $destEvent = $instruction['dest-event'];
            if (preg_match('/[^a-z0-9_]/', $destEvent)) {
                $this->config_errors[] = "destination event \"".htmlspecialchars($destEvent,ENT_QUOTES)."\" contains invalid characters";
            } else if (preg_match('/^[a-z0-9_]+_arm_\d+$/', $destEvent)) {
                // validate that this is a valid unique event name in the destination project
                if (!is_null($this->dest_project)) {
                    $destEventId = $this->dest_project->getEventIdUsingUniqueEventName($destEvent);
                    if (!$destEventId) {
                        $this->config_errors[] = "destination event name \"".htmlspecialchars($destEvent,ENT_QUOTES)."\" is not valid for project ".intval($this->dest_project->project_id);
                    }
                }
                $this->dest_event_source = self::evtSourceName;
            } else {
                // validate that this is a valid field name in the source project
                if (!is_null($this->source_project) ) {
                    if (!array_key_exists($destEvent, $this->source_project->metadata)) {
                        $this->config_errors[] = "destination event setting \"".htmlspecialchars($destEvent,ENT_QUOTES)."\" is not a valid field in this project, nor a valid event name in the destination project";
                    }
                }
                $this->dest_event_source = self::evtSourceField;
            }
        }

        if (!array_key_exists($this->record_id_field, $this->source_project->metadata)) {
            $this->config_errors[] = "invalid source record id field \"{$this->record_id_field}\"";
        }

        if ($this->record_match_option == CopyDataOnSave::rmCreateAutoNumbered
                && $this->record_id_field == $this->source_project->table_pk) {
            $this->config_errors[] = "autonumbering option is incompatible with using source project record id field for matching";
        }

        if ($this->dag_option == CopyDataOnSave::dagMap) {
            $this->config_warnings[] = "do not use \"dag mapping\" option - see documentation";
        }

        if (empty($this->copy_fields) || !is_array($this->copy_fields)) {
            $this->config_errors[] = "no fields to copy specified";
        } else {
            foreach ($this->copy_fields as $idx => $pair) {
                $source_field = trim($pair['source-field']);
                $dest_field = trim($pair['dest-field']); // #25 @bigdanfoley
                $if_empty = $pair['only-if-empty'];

                if (!is_null($this->source_project) ) {
                    if (empty($source_field)) {
                        $this->config_errors[] = "missing source field in copy fields pair #".($idx+1);
                    } else if (empty($source_field) || !array_key_exists($source_field, $this->source_project->metadata)) {
                        $this->config_errors[] = "source field \"".htmlspecialchars($source_field,ENT_QUOTES)."\" not found in source project in copy fields pair #".($idx+1);
                    }
                }
                if (empty($dest_field)) {
                    $this->config_errors[] = "missing destination field in copy fields pair #".($idx+1);
                } else if (preg_match('/[^a-z0-9_]/', $dest_field)) {
                    $this->config_errors[] = "destination field \"".htmlspecialchars($dest_field,ENT_QUOTES)."\" has invalid characters in copy fields pair #".($idx+1);
                } else if (!is_null($this->dest_project)) {
                    if (empty($dest_field) || ($dest_field!=='redcap_data_access_group' && $dest_field!=='redcap_repeat_instance' && !array_key_exists($dest_field, $this->dest_project->metadata))) {
                        $this->config_errors[] = "destination field \"".htmlspecialchars($dest_field,ENT_QUOTES)."\" not found in destination project in copy fields pair #".($idx+1);
                    }
                }

                if (!($if_empty == '0' || $if_empty == '1')) {
                    $this->config_errors[] = "invalid value for \"only if empty\" (".htmlspecialchars($pair['only-if-empty'],ENT_QUOTES)."): 0 or 1 expected";
                } else {
                    $this->copy_fields[$idx]['only-if-empty'] = (bool)$if_empty;
                }
            }
        }
    }

    /**
     * getAsModuleSettings()
     * @return array array of settings as per module project settings from $module->getProjectSettings()
     */
    public function getAsModuleSettings(): array {
        $instructionSettings = array();
        $instructionSettings['section-description'] = $this->section_description;
        $instructionSettings['copy-enabled'] = $this->copy_enabled;
        $instructionSettings['trigger-form'] = $this->trigger_form;
        $instructionSettings['trigger-logic'] = $this->trigger_logic;
        $instructionSettings['dest-project'] = $this->dest_project->project_id;
        $instructionSettings['dest-event'] = $this->dest_event;
        $instructionSettings['record-id-field'] = $this->record_id_field;
        $instructionSettings['record-create'] = "{$this->record_match_option}";
        $instructionSettings['dag-option'] = "{$this->dag_option}";

        foreach ($this->copy_fields as $fieldPair) {
            $instructionSettings['copy-fields'][] = 'true';
            $instructionSettings['source-field'][] = $fieldPair['source-field'];
            $instructionSettings['dest-field'][] = $fieldPair['dest-field'];
            $instructionSettings['only-if-empty'][] = $fieldPair['only-if-empty'];
        }
        return $instructionSettings;
    }

    /**
     * getProperty()
     * Return the property value matching the string key (if not matched try replacing - with _ in key)
     * @param string property 
     * @return mixed
     */
    public function getProperty(string $property_name): mixed {
        if (property_exists($this, $property_name)) {
            return $this->$property_name;
        } else if (str_contains($property_name, '-')) {
            return $this->getProperty(str_replace('-','_',$property_name));
        }
        return null;
    }

    public function getConfigErrors(): array {
        return $this->config_errors;
    }
    public function getConfigWarnings(): array {
        return $this->config_warnings;
    }
}
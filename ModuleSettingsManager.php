<?php
/**
 * REDCap External Module: Copy Data on Save
 * Copy data from one place to another when you save a form.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\CopyDataOnSave;

class ModuleSettingsManager {
    const EM_LOG_MESSAGE = "capture module settings";
    protected $module;
    protected $project_id;

    public function __construct(\ExternalModules\AbstractExternalModule $module) {
        $this->module = $module;
        $this->project_id = $this->module->getProjectId();
    }

    /**
     * saveCurrentSettingsToHistory()
     * Call from module redcap_module_save_configuration() i.e. after a module configuration is saved.
     * Capture history when instructions updated.
     */
    public function saveCurrentSettingsToHistory() {
        if (empty($this->project_id)) {
            $settings = $this->module->getSystemSettings();
        } else {
            $settings = $this->module->getProjectSettings();
        }
        $this->updateSettingsHistory($settings);
    }

    public function saveSettingsToHistory(array $settings) {
        if (empty($this->project_id)) {
            $this->module->setSystemSettings($settings);
        } else {
            $this->module->setProjectSettings($settings);
        }
        $this->updateSettingsHistory($settings);
    }

    protected function updateSettingsHistory(array $settings) {
        $lastSaved = $this->getSettingsHistory(1); // limit 1 = most recent logged save only

        if (isset($lastSaved[0]) && array_key_exists('module_settings', $lastSaved[0]) && static::are_equal($settings, $lastSaved[0]['module_settings'])) {
            // no changes made -> nothing to log
        } else {
            $logId = $this->module->log(
                static::EM_LOG_MESSAGE,
                array('module_settings' => \json_encode_rc($settings))
            );
        }
    }

    public function getSettingsHistory(?int $limit=0): array {
        $history = array();
        $limit = ($limit > 0) ? ' limit '.intval($limit) : '';

        $pseudoSql = "
            select log_id, timestamp, message, username, module_settings
            where project_id = ? and message = ? and module_settings is not null
            order by timestamp desc $limit
        ";

        $result = $this->module->queryLogs($pseudoSql, [$this->project_id, static::EM_LOG_MESSAGE]);
        while($row = $result->fetch_assoc()) {
            $row['module_settings'] = \json_decode($row['module_settings'], true);
            $history[] = $row;
        }
        return $history;
    }

    public function getSettingsHistoryByLogId(int $id): array {
        $history = array();

        $pseudoSql = "
            select log_id, timestamp, message, username, module_settings
            where project_id = ? and message = ? and module_settings is not null and log_id = ?
        ";

        $result = $this->module->queryLogs($pseudoSql, [$this->project_id, static::EM_LOG_MESSAGE, $id]);
        while($row = $result->fetch_assoc()) {
            $row['module_settings'] = \json_decode($row['module_settings'], true);
            $history[] = $row;
        }
        return $history;
    }

    /**
     * getFilteredSettingsHistory()
     * Filter the settings change history to include only those entries where settings in the specified list have been altered
     * @param array setting names to detect changes in
     * @return array history entries
     */
    public function getFilteredSettingsHistory(array $changedSettings): array {
        $filteredHistory = array();
        $history = $this->getSettingsHistory();
        
        // remove any invalid saves and reindex
        $history = array_filter($history, function($value){
            return (is_array($value) && array_key_exists('module_settings',$value) && is_array($value['module_settings']));
        });
        $history = array_values($history);
        if (empty($history)) return array();

        $missing = array_diff($changedSettings, array_keys($history[0]['module_settings']));
        if (!empty($missing)) {
            // Cannot detect changes for settings that are not present in what is saved so don't check (e.g. section-description in old configs)
            $changedSettings = array_diff($changedSettings, $missing);
        }

        $filteredHistory[] = $previousEntry = array_pop($history); // always the first save (last/oldest in history)

        while (!empty($history)) {
            $entry = array_pop($history);
            $entrySettings = static::keep_keys($entry['module_settings'], $changedSettings);
            $previousEntrySettings = static::keep_keys($previousEntry['module_settings'], $changedSettings);

            if (!static::are_equal($entrySettings, $previousEntrySettings)) {
                $filteredHistory[] = $entry;
            }

            $previousEntry = $entry;
        }

        return array_reverse($filteredHistory);
    }

    /**
     * keep_keys()
     * Unset keys in the first array provided that are not in the second array
     */
    public static function keep_keys(array $array, array $keys): array {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * are_equal()
     * Check two values for equality (==) nb. not identity (===)
     * Objects are serialised for comparison.
     * Arrays are iterated, keys matched, and values compared recursively.
     * @param mixed value1
     * @param mixed value2
     * @return bool
     */
    public static function are_equal(mixed $value1, mixed $value2): bool {
        if (is_array($value1) && is_array($value2)) {

            foreach ($value1 as $v1k => $v1v) {
                if (!array_key_exists($v1k, $value2)) {
                    return false;
                }
                if (!static::are_equal($v1v, $value2[$v1k])) {
                    return false;
                }
            }
            return true;
            /*
            array_multisort($value1);
            array_multisort($value2);
            $sv1 = serialize($value1);
            $sv2 = serialize($value2);
            return ( $sv1 == $sv2 );
            */
        } else if (is_object($value1) && is_object($value2)) {
            return ( serialize($value1) == serialize($value2) );
        }

        return $value1 == $value2;
    }


    /**
     * getSubSettingsFromSettingsArray
     * Similar to Framework::getSubSettings_internal()
	 * @param array $settingsAsArray
	 * @param array $settingConfig
	 * @return array
	 */
	public function getSubSettingsFromSettingsArray(array $settingsAsArray, array $settingConfig): array {
		$subSettings = [];
		foreach ($settingConfig['sub_settings'] as $subSettingConfig) {
			$subSettingKey = $subSettingConfig['key'];

			if (($subSettingConfig['type'] ?? null) === 'sub_settings') {
				// Handle nested sub_settings recursively
				$values = $this->getSubSettingsFromSettingsArray($settingsAsArray, $subSettingConfig);

				$recursionCheck = function ($value): bool {
					// We already know the value must be an array.
					// Recurse until we're two levels away from the leaves, then wrap in $subSettingKey.
					// If index '0' is not defined, we know it's a leaf since only setting key names will be used as array keys (not numeric indexes).
					return isset($value[0][0]);
				};
			} else {
				$values = $settingsAsArray[$subSettingKey] ?? null; // $settingsAsArray[$this->module->getModuleInstance()->prefixSettingKey($subSettingKey)]['value'] ?? null;
				if ($values === null) {
					continue;
				} elseif (!is_array($values)) {
					/**
					 * This setting was likely moved from a plain old setting into sub-settings.
					 * Preserve the existing value as if it was saved under the current setting configuration.
					 */
					$values = [$values];
				}

				$recursionCheck = function ($value) use ($subSettingConfig): bool {
					// Only recurse if this is an array, and not a leaf.
					// If index '0' is not defined, we know it's a leaf since only setting key names will be used as array keys (not numeric indexes).
					// Using array_key_exists() instead of isset() is important since there could be a null value set.
					return
						is_array($value)
						&&
						array_key_exists(0, (array) $value)
						&&
						!(
							($subSettingConfig['repeatable'] ?? null)
							&&
							(
								$this->module->VERSION < 9 // static::NESTED_REPEATABLE_SETTING_FIX_FRAMEWORK_VERSION
								||
								!is_array($value[0])
							)
						);
				};
			}

			$formatValues = function ($values) use ($subSettingKey, $recursionCheck, &$formatValues) {
				for ($i = 0; $i < count($values); $i++) {
					$value = $values[$i];

					if ($recursionCheck($value)) {
						$values[$i] = $formatValues($value);
					} else {
						$values[$i] = [
							$subSettingKey => $value
						];
					}
				}

				return $values;
			};

			$values = $formatValues($values);

			$subSettings = static::array_merge_recursive_distinct($subSettings, $values);
		}

		return $subSettings;
	}

	/**
     * From ExternalModules::array_merge_recursive_distinct()
	 * @return array
	 */
	static function array_merge_recursive_distinct ( array &$array1, array &$array2 )
	{
	  $merged = $array1;

	  foreach ( $array2 as $key => &$value )
	  {
	    if ( is_array ( $value ) && isset ( $merged [$key] ) && is_array ( $merged [$key] ) )
	    {
	      $merged [$key] = self::array_merge_recursive_distinct ( $merged [$key], $value );
	    }
	    else
	    {
	      $merged [$key] = $value;
	    }
	  }

	  return $merged;
	}
}
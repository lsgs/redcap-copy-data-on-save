<?php
/**
 * REDCap External Module: Copy Data on Save
 * Copy data from one place to another when you save a form.
 * Example URL: 
 * /ExternalModules/?prefix=copy_data_on_save&page=export_import&pid=45&action=export
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\CopyDataOnSave\CopyDataOnSave)) { exit(); }
if (!$module->userHasPermission()) exit(0);
switch ($_GET['action']) {
    case 'export': 
        $sep = (isset($_GET['sep'])) ? $module->escape($_GET['sep']) : null;
        $ver = (isset($_GET['ver'])) ? intval($_GET['ver']) : null;
        $result = $module->export($sep, $ver); 
        break;
    case 'import': 
        $errors = $module->import((isset($_POST['ajax-action'])) ? $module->escape($_POST['ajax-action']) : null); 
        $result = array(
            'result' => (count($errors)) ? 0 : 1,
            'errors' => $errors
        );
        $result = \json_encode($result); 
        break;
    default: $result = null;
}
echo $result;
<?php
/**
 * REDCap External Module: Copy Data on Save
 * Copy data from one place to another when you save a form.
 * Example URL: 
 * /redcap_v13.10.6/ExternalModules/?prefix=copy_data_on_save&page=summary&pid=45
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\CopyDataOnSave\CopyDataOnSave)) { exit(); }

global $user_rights;

$modulePermission = $module->getSystemSetting('config-require-user-permission');
if ($modulePermission) {
    $userHasPermission = (is_array($user_rights['external_module_config']) && in_array('copy_data_on_save', $user_rights['external_module_config']) || (defined('SUPER_USER') && SUPER_USER && !\UserRights::isImpersonatingUser()));
} else {
    $userHasPermission = ($user_rights['design'] || (defined('SUPER_USER') && SUPER_USER && !\UserRights::isImpersonatingUser()));
}
if (!$userHasPermission) { 
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
    echo '<div class="red">'.\RCView::tt('pub_001').'</div>'; 
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
    exit;
}
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$module->summaryPage();
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
<?php
/**
 * REDCap External Module: Copy Data on Save
 * Copy data from one place to another when you save a form.
 * Example URL: 
 * /ExternalModules/?prefix=copy_data_on_save&page=summary&pid=45
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\CopyDataOnSave\CopyDataOnSave)) { exit(); }

if (!$module->userHasPermission()) { 
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
    echo '<div class="red">'.\RCView::tt('pub_001').'</div>'; 
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
    exit;
}
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$module->summaryPage();
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
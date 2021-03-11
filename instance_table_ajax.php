<?php
/**
 * Instance Table External Module
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof Stanford\iPSCTissueBankWu\iPSCTissueBankWu)) { exit(); }
header("Content-Type: application/json");
//{ "data": [...] }
if ($_GET['form_name'] =='vial') {
    echo json_encode(array('data' => $module->getSelectableInstanceData($_GET['record'], $_GET['event_id'], $_GET['form_name'], $_GET['filter'])));
} else {
    echo json_encode(array('data' => $module->getInstanceData($_GET['record'], $_GET['event_id'], $_GET['form_name'], $_GET['filter'])));
}

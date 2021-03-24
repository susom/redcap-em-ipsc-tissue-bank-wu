<?php

namespace Stanford\iPSCTissueBankWu;



use LogicException;
use REDCap;
/** @var \Stanford\iPSCTissueBankWu\iPSCTissueBankWu $module */


error_reporting(E_ALL ^ (E_NOTICE | E_WARNING | E_DEPRECATED));

try {
    if (!isset($_POST)) {
        throw new LogicException('You cant be here');
    }
    $module->emDebug('$_POST: ' . print_r($_POST, true));

    if (!isset($_POST['updateType'])) {
        throw new LogicException('An update type is required');
    }

    /**
     * Run the SQL corresponding to the user supplied spec
     */

    function getRecordIds(): array
    {
        global $module;
        $matches = [];
        $module->emDebug('matches: ' . $_POST['tablehtml']);

        preg_match_all(
            "/editInstance\((?P<record>.*?),(?P<event_id>.*?),(?P<instrument>.*?),(?P<instance>.*?)\).*?>(?P<vialid>.*?)<\/a>.*?<\/td><td>(?P<box>.*?)<\/td><td>(?P<slot>.*?)<\/td><\/tr>/",
            $_POST['tablehtml'], $matches);
        $module->emDebug('matches: ' . print_r($matches, true));
        return $matches;
    }

    function printLabels($record, $instances) {
        global $module;
        $return = [];
        $sql="select distinct COALESCE (sample.red_rec_number,'') `record`,
            COALESCE (sample.sample_instance,'') `instance`,
            COALESCE (sample.smp_date_deposited,'') `sample_date`,
            COALESCE (sample.smp_line_id,'') `line`,
            COALESCE (sample.smp_type,'') `type`,
            COALESCE (sample.smp_passage_number,'') `passage`,
            COALESCE (vial.vial_id,'') `vial_id`,
            COALESCE (vial.vial_freezer_box,'') `freezer_box`,
            COALESCE (vial.vial_freezer_slot,'') `freezer_slot`
        FROM ((select * from (select rd.record  red_rec_number ,
            COALESCE(rd.`instance`, 1) as sample_instance,
            group_concat(distinct case when rd.field_name = 'smp_type' then
                substring(md.element_enum, instr(md.element_enum, rd.value) + length(rd.value) + 2,
            locate('\\\\n',
                md.element_enum,
                instr(md.element_enum, rd.value) + length(rd.value))-(instr(md.element_enum, rd.value) + length(rd.value) + 2)) end separator '\\n') as `smp_type`,
            group_concat(distinct case when rd.field_name = 'smp_date_deposited'
                then rd.value end separator '\\n') `smp_date_deposited`,
            group_concat(distinct case when rd.field_name = 'smp_passage_number'
                then rd.value end separator '\\n') `smp_passage_number`,
            group_concat(distinct case when rd.field_name = 'smp_line_id'
                then rd.value end separator '\\n') `smp_line_id`
      FROM redcap_data rd JOIN redcap_metadata md
          ON md.project_id = rd.project_id
             AND md.field_name = rd.field_name AND md.form_name='sample'
      WHERE rd.project_id = ".PROJECT_ID.
            " AND rd.record = " . $record .
            " GROUP BY rd.record, sample_instance) t   ) sample INNER JOIN
        (select * from (select rd.record  red_rec_number ,
        COALESCE(rd.`instance`, 1) as vial_instance,
        group_concat(distinct case when rd.field_name = 'vial_sample_ref'
            then rd.value end separator '\\n') `vial_sample_ref`,
        group_concat(distinct case when rd.field_name = 'vial_id'
            then rd.value end separator '\\n') `vial_id`,
        group_concat(distinct case when rd.field_name = 'vial_freezer_box'
            then rd.value end separator '\\n') `vial_freezer_box`,
        group_concat(distinct case when rd.field_name = 'vial_freezer_slot'
            then rd.value end separator '\\n') `vial_freezer_slot`
      FROM redcap_data rd
      WHERE rd.project_id = ".PROJECT_ID.
            " AND rd.record = " . $record .
            " AND rd.instance in (" . implode(",", $instances).")".
            " GROUP BY rd.record, vial_instance) t ) vial
        ON sample.red_rec_number=vial.red_rec_number AND vial_sample_ref = sample_instance )";
        $module->emDebug("print query sql: " .$sql);

        $result = db_query($sql);
        if (!$result || $result->num_rows === 0) {
            $return['success'] = false;
            $return['errors'] = 'Unable to retrieve sample data for print request';
            return $return;
        }
        $module->emDebug("print query results: " . print_r($result, true));

        $print_data='';
        while ($row = db_fetch_assoc($result)) {
            $module->emDebug('row ' . print_r($row, true));
            $sample_date = date_create($row['sample_date']);
            $print_data.="^XA^FO10,15^ADN,18,10^FD".date_format($sample_date, 'm/d/Y')."^FS";
            $print_data.="^FO10,34^ADN,18,10^FDExternal ID ".$row['record']."^FS";
            $print_data.="^FO10,54^ADN,18,10^FD".$row['freezer_box']."-".$row['freezer_slot']."^FS";
            $desc ='';
            if (strpos($row['type'],'Fibroblast') !== false) {
                $desc='fb';
            } else if (stripos($row['type'],'ipsc') !== false) {
                $desc='ip';
            } else {
                $desc=substr($row['type'],0,3);
            }
            if (!empty($row['line'])) {
                $desc .=" ".$row['line'];
            }
            if (!empty($row['passage'])) {
                $desc .=" P".$row['passage'];
            }
            $print_data.="^FO10,73^ADN,18,10^FD".$desc."^FS";
            $print_data.="^FO10,92^ADN,18,10^FD".$row['vial_id']."^FS";
            // Code 128 Bar code
            $print_data.="^FO5,111^BCN,35,N,N,N,A^FD".$row['vial_id']."^FS";
            $print_data.="^XZ";
        }
        $module->emDebug("ZPL:" . $print_data);
        $fp=pfsockopen($module->getProjectSetting('printer-ip'),9100,
            $errno, $errstr);
        $return = [];
        if (!$fp) {
            $return['success']=false;
            $return['errors']="Caught printing error: $errstr ($errno)";
        } else {
            $ret = fputs($fp,$print_data);
            fclose($fp);
            if ($ret) {
                $return['success'] = true;
            } else {
                $return['success']=false;
                $return['errors']="Printing error: unable to write to port.";
            }
        }
        return $return;
    }

    function cancelPlanned($record, $instances) {
        global $module;
        if (strpos($record, "'") === -1 && strpos($record, '"') === -1) {
            $record = "'".$record."'";
        }
        $sql = "select instance from redcap_data where project_id=" . PROJECT_ID .
            " and record=" . $record .
            " and instance in (" . implode(',', $instances) . ")" .
            " and field_name ='vial_dist_status'" .
            " and value ='2'";
        $module->emDebug('cancel query sql: ' . $sql);

        $result1 = db_query($sql);
        if ($result1->num_rows > 0) {
            $return=[];
            $return['success'] = false;
            $return['errors'] = "Vials that have already been shipped can not be cancelled.";
            return $return;
            //echo '{"success":false,"error":{"message": "Distribution can only be cancelled for vials in \"planned\"
            // status."}}';
        } else {
            $jsonData = [];
            foreach ($instances as $instance) {
                $json = '{"red_rec_number":' . str_replace('\'', '', $record) . ',';
                $json .= '"redcap_repeat_instrument":"vial",';
                $json .= '"redcap_repeat_instance":' . $instance . ',';
                $json .= '"vial_dist_by":"",';
                $json .= '"vial_dist_to":"",';
                $json .= '"vial_dist_date":"",';
                $json .= '"vial_dist_irb":"",';
                $json .= '"vial_dist_irb_exp":"",';
                $json .= '"vial_dist_status":"0"}';

                $jsonData[] = $json;
            }
            $module->emDebug('$jsonData values: ' . '[' . implode(',', $jsonData) . ']');
            $result = REDCap::saveData(PROJECT_ID, 'json', '[' . implode(',', $jsonData) . ']', 'overwrite');
            $module->emDebug('saveData result ' . print_r($result, true));
            if (count($result['errors'])) {
                $result['success']=false;
                return $result;
            } else {
                $result['success']=true;

                return $result;
            }
        }
    }

    function getPlanned() {
        global $module;

        $sql="select distinct COALESCE (sample.red_rec_number,'') `record`,
            COALESCE (vial.vial_instance,'') `instance`,
            COALESCE (sample.smp_date_deposited,'') `sample_date`,
            COALESCE (sample.smp_line_id,'') `line`,

            COALESCE (sample.smp_type,'') `type`,
            COALESCE (sample.smp_passage_number,'') `passage`,
            COALESCE (vial.vial_id,'') `vial_id`,
            COALESCE (vial.vial_freezer_box,'') `freezer_box`,
            COALESCE (vial.vial_freezer_slot,'') `freezer_slot`,
            COALESCE (vial.vial_dist_by,'') `dist_by`,
            COALESCE (vial.vial_dist_to,'') `dist_to`,
            COALESCE (vial.vial_dist_date,'') `dist_date`
        FROM ((select * from (select rd.record  red_rec_number ,
            COALESCE(rd.`instance`, 1) as sample_instance,
            group_concat(distinct case when rd.field_name = 'smp_type' then
                substring(md.element_enum, instr(md.element_enum, rd.value) + length(rd.value) + 2,
            locate('\\\\n',
                md.element_enum,
                instr(md.element_enum, rd.value) + length(rd.value))-(instr(md.element_enum, rd.value) + length(rd.value) + 2)) end separator '\\n') as `smp_type`,

            /*group_concat(distinct case when rd.field_name = 'smp_type'
                then rd.value end separator '\\n')`smp_type`,*/
            group_concat(distinct case when rd.field_name = 'smp_date_deposited'
                then rd.value end separator '\\n') `smp_date_deposited`,
            group_concat(distinct case when rd.field_name = 'smp_passage_number'
                then rd.value end separator '\\n') `smp_passage_number`,
            group_concat(distinct case when rd.field_name = 'smp_line_id'
                then rd.value end separator '\\n') `smp_line_id`
      FROM redcap_data rd JOIN redcap_metadata md
          ON md.project_id = rd.project_id
             AND md.field_name = rd.field_name AND md.form_name='sample'
      WHERE rd.project_id = ".PROJECT_ID.
      " GROUP BY rd.record, sample_instance) t   ) sample INNER JOIN
        (select * from (select rd.record  red_rec_number ,
        COALESCE(rd.`instance`, 1) as vial_instance,
        group_concat(distinct case when rd.field_name = 'vial_sample_ref'
            then rd.value end separator '\\n') `vial_sample_ref`,
        group_concat(distinct case when rd.field_name = 'vial_id'
            then rd.value end separator '\\n') `vial_id`,
        group_concat(distinct case when rd.field_name = 'vial_freezer_box'
            then rd.value end separator '\\n') `vial_freezer_box`,
        group_concat(distinct case when rd.field_name = 'vial_freezer_slot'
            then rd.value end separator '\\n') `vial_freezer_slot`,
        group_concat(distinct case when rd.field_name = 'vial_dist_by'
            then rd.value end separator '\\n') `vial_dist_by`,
        group_concat(distinct case when rd.field_name = 'vial_dist_to'
            then rd.value end separator '\\n') `vial_dist_to`,
        group_concat(distinct case when rd.field_name = 'vial_dist_status'
            then rd.value end separator '\\n') `vial_dist_status`,
        group_concat(distinct case when rd.field_name = 'vial_dist_date'
            then rd.value end separator '\\n') `vial_dist_date`
      FROM redcap_data rd
      WHERE rd.project_id = ".PROJECT_ID.
       " GROUP BY rd.record, vial_instance) t
        WHERE vial_dist_status = '1' ) vial
        ON sample.red_rec_number=vial.red_rec_number AND vial_sample_ref = sample_instance )";

        $result = db_query($sql);
        $returnData = [];
        while ($row = db_fetch_assoc($result)) {
            //$module->emDebug(print_r($row, true));
            $returnData[] =$row;
        }
        $module->emDebug('return: '.json_encode($returnData));
        $return['success'] = true;
        $return['data'] = $returnData;
        return $return;
        //return '{"success": true, "data":' . json_encode($returnData) . '}';
    }


    if ($_POST['updateType'] == 'delete') {
        $dataDictionary = REDCap::getDataDictionary(PROJECT_ID, 'array', false, null, 'vial');
        $matches = getRecordIds();
        $sql = "delete from redcap_data where project_id=" . PROJECT_ID .
            " and event_id =" . $matches['event_id'][0] .
            " and field_name in ('".implode("','",array_keys($dataDictionary))."','vial_complete')".
            " and record=" . $matches['record'][0] .
            " and instance in (" . implode(',', $matches['instance']) . ")";
        $module->emDebug('delete sql: ' . $sql);
        if (db_query($sql)) {
            echo '{"success":true, "data":[]}';
        }
    }
    else if ($_POST['updateType'] === 'cancel') {
        $matches = getRecordIds();
        $return = cancelPlanned($matches['record'][0], $matches['instance']);
        if (count($return['errors'])) {
            echo json_encode($return);
        } else {
            echo '{"success":true, "data":[]}';
        }
    }
    else if ($_POST['updateType'] == 'cancelPlannedReport') {
        $recordsToSave = json_decode($_POST['recordsToSave'], true);
        $module->emDebug('recordsToSave: '. print_r($recordsToSave, true));
        $return = [];
        foreach($recordsToSave as $record) {
            $instances = [];
            $instances[]=$record['instance'];
            $module->emDebug('record: '.$record['record'] . ' instances: '.  print_r($instances, true));

            $return = cancelPlanned($record['record'], $instances);
            if (!$return['success']) {
                echo json_encode($return);
                break;
            }
        }
        if ($return['success']) {
            $return = getPlanned();
            //$module->emDebug('cancelPlannedReport return: '.$temp);
            echo json_encode($return);
        }
    }
    else if ($_POST['updateType'] == 'distribute') {
        $matches = getRecordIds();
        $updateValues = json_decode($_POST['updateData'], true);
        $module->emDebug('distribute values: ' . print_r($updateValues, true));

        $jsonData = [];
        foreach($matches['instance'] as $instance) {
            $json ='{"red_rec_number":'.str_replace('\'','',$matches['record'][0]).',';
            $json .='"redcap_repeat_instrument":"vial",';
            $json .='"redcap_repeat_instance":'.$instance.',';
            $json .='"vial_dist_by":"'. $updateValues['vial_dist_by'].'",';
            $json .='"vial_dist_to":"'. $updateValues['vial_dist_to'].'",';
            $json .='"vial_dist_date":"'. date('Y-m-d').'",';
            $json .='"vial_dist_irb":"'. $updateValues['vial_dist_irb'].'",';
            $json .='"vial_dist_irb_exp":"'. $updateValues['vial_dist_irb_exp'].'",';
            $json .='"vial_dist_status":"'. $updateValues['vial_dist_status'].'"}';
            $jsonData[] = $json;
        }
        $module->emDebug('$jsonData values: ' . '['.implode(',',$jsonData).']');
        $result = REDCap::saveData(PROJECT_ID, 'json','['.implode(',',$jsonData).']','overwrite');
        $module->emDebug('$result values: ' . print_r($result, true));

        if (count($result['errors'])) {
            $result['success']=false;
            echo json_encode($result);
        } else {
            echo '{"success":true}';
        }
    }
    else if ($_POST['updateType'] == 'move') {
        $matches = getRecordIds();
        $module->emDebug('matches: ' . print_r($matches, true));
        $sql = "select instance from redcap_data where project_id=" . PROJECT_ID .
            " and record=" . $matches['record'][0] .
            " and instance in (" . implode(',', $matches['instance']) . ")" .
            " and field_name ='vial_dist_status'" .
            " and value ='2'";
        $module->emDebug('cancel query sql: ' . $sql);

        $result1 = db_query($sql);
        if ($result1->num_rows > 0) {
            echo '{"success":false,"error":{"message": "Can not move already shipped vials"}}';
        }
        $freezerId = json_decode($_POST['updateData']);
        $numSlots = count($matches['instance']);
        $freezerSpace = $module->getFreezerSpace($freezerId, $numSlots);

        $jsonData = [];
        for ($i = 0; $i < $numSlots; $i++) {
            $json ='{"red_rec_number":'.str_replace('\'','',$matches['record'][0]).',';
            $json .='"redcap_repeat_instrument":"vial",';
            $json .='"redcap_repeat_instance":'.$matches['instance'][$i].',';
            $json .='"vial_freezer_box":"'. $freezerSpace['box'].'",';
            $json .='"vial_freezer_slot":"'. $freezerSpace['slots'][$i].'",';
            $json .='"vial_move_date":"'. date('Y-m-d').'",';
            $json .='"vial_prev_freezer_box":"'. $matches['box'][$i].'",';
            $json .='"vial_prev_freezer_slot":"'. $matches['slot'][$i].'"}';
            $jsonData[] = $json;
        }
        $module->emDebug('$jsonData values: ' . '['.implode(',',$jsonData).']');
        $result = REDCap::saveData(PROJECT_ID, 'json','['.implode(',',$jsonData).']','overwrite');
        if (count($result['errors'])) {
        $result['success']=false;
            echo json_encode($result);
        } else {
            echo '{"success":true}';
        }
    }
    else if ($_POST['updateType'] == 'printMoved') {
        $recordsToSave = json_decode($_POST['recordsToSave'], true);
        //$module->emDebug('recordsToSave: '. print_r($recordsToSave, true));
        foreach($recordsToSave as $record) {
            $instances = [];
            $instances[]=$record['instance'];
            $returnData = printLabels($record['record'], $instances);
            if ($returnData['success']==false) {
                break;
            }
        }
        $module->emDebug('move print labels: '.json_encode($returnData));
        echo json_encode($returnData);
    }
    else if ($_POST['updateType'] == 'print') {
        $matches = getRecordIds();
        $returnData = printLabels($matches['record'][0], $matches['instance']);
        echo json_encode($returnData);
    }
    else if ($_POST['updateType'] == 'emptySlotReport') {
        $freezer=$_POST['freezer'];
        $module->emDebug('emptySlotReport');
        $sql = "select box, count(slot) as num_slots, group_concat(slot order by slot) as empty_slots " .
            "from ipsc_wu_all_slots where box like '$freezer%' AND (box, slot) not in (".
            "select distinct COALESCE (vial.box,'') `box`, "
            ."COALESCE (vial.slot,'') `slot` FROM ".
            "(select * from ".
            "(select rd.record  red_rec_number ,".
        "COALESCE(rd.`instance`, 1) as vial_instance,".
            "group_concat(distinct case when rd.field_name = 'vial_freezer_box'  ".
            "then rd.value end separator '\\n') `box`, ".
            "group_concat(distinct case when rd.field_name = 'vial_freezer_slot'  ".
            "then rd.value end separator '\\n') `slot`, ".
            "group_concat(distinct case when rd.field_name = 'vial_dist_status'  ".
            "then rd.value end separator '\\n') `status` FROM redcap_data rd ".
            "WHERE  rd.project_id = ".PROJECT_ID." GROUP BY rd.record, vial_instance) t    ".
            "WHERE status <> '2'  and box like '$freezer%') vial )".
            " group by box order by box";
        $module->emDebug("empty slot sql=$sql ");
        $result = db_query($sql);

        $returnData = [];
        while ($row = db_fetch_assoc($result)) {
            $module->emDebug('row ' . print_r($row, true));
            $returnData[] = $row;
        }
        $module->emDebug('$returnData ' . json_encode($returnData));

        echo '{"success":true, "tableValues":' . json_encode($returnData) . '}';
    }
    else if ($_POST['updateType'] == 'plannedReport') {
        echo json_encode(getPlanned());
    }
    else if ($_POST['updateType']=="saveShipped") {
        $module->emDebug('saveShipped');
        $recordsToSave = json_decode($_POST['recordsToSave'], true);
        $module->emDebug('recordsToSave: '. print_r($recordsToSave, true));
        $jsonData = [];
        foreach($recordsToSave as $record) {
            $json ='{"red_rec_number":"'.$record['record'].'",';
            $json .='"redcap_repeat_instrument":"vial",';
            $json .='"redcap_repeat_instance":'.$record['instance'].',';
            $json .='"vial_dist_status":"2"}';
            $jsonData[] = $json;
        }
        $result= REDCap::saveData(PROJECT_ID, 'json','['.implode(',',$jsonData).']');
        if (count($result['errors'])) {
            $result['success']=false;
            echo json_encode($result);
        } else {
            echo getPlanned();
        }

    }
    else if ($_POST['updateType']=='moveReport') {
        $module->emDebug('moveReport');

        $sql="select distinct COALESCE (sample.red_rec_number,'') `record`,
            COALESCE (vial.vial_instance,'') `instance`,
            COALESCE (sample.smp_date_deposited,'') `deposit_date`,
            COALESCE (sample.smp_line_id,'') `line`,
            COALESCE (vial.vial_id,'') `vial_id`,
            COALESCE (vial.vial_prev_freezer_box,'') `prev_box`,
            COALESCE (vial.vial_prev_freezer_slot,'') `prev_slot`,
            COALESCE (vial.vial_freezer_box,'') `box`,
            COALESCE (vial.vial_freezer_slot,'') `slot`,
            COALESCE (vial.vial_move_date,'') `move_date`

        FROM ((select * from (select rd.record  red_rec_number ,
            COALESCE(rd.`instance`, 1) as sample_instance,
            group_concat(distinct case when rd.field_name = 'smp_date_deposited'
                then rd.value end separator '\\n') `smp_date_deposited`,
            group_concat(distinct case when rd.field_name = 'smp_line_id'
                then rd.value end separator '\\n') `smp_line_id`
      FROM redcap_data rd JOIN redcap_metadata md
          ON md.project_id = rd.project_id
             AND md.field_name = rd.field_name AND md.form_name='sample'
      WHERE rd.project_id = ".PROJECT_ID.
            " GROUP BY rd.record, sample_instance) t   ) sample INNER JOIN
        (select * from (select rd.record  red_rec_number ,
        COALESCE(rd.`instance`, 1) as vial_instance,
        group_concat(distinct case when rd.field_name = 'vial_sample_ref'
            then rd.value end separator '\\n') `vial_sample_ref`,
        group_concat(distinct case when rd.field_name = 'vial_id'
            then rd.value end separator '\\n') `vial_id`,
        group_concat(distinct case when rd.field_name = 'vial_freezer_box'
            then rd.value end separator '\\n') `vial_freezer_box`,
        group_concat(distinct case when rd.field_name = 'vial_freezer_slot'
            then rd.value end separator '\\n') `vial_freezer_slot`,
        group_concat(distinct case when rd.field_name = 'vial_prev_freezer_box'
            then rd.value end separator '\\n') `vial_prev_freezer_box`,
        group_concat(distinct case when rd.field_name = 'vial_prev_freezer_slot'
            then rd.value end separator '\\n') `vial_prev_freezer_slot`,
        group_concat(distinct case when rd.field_name = 'vial_dist_status'
            then rd.value end separator '\\n') `vial_dist_status`,
        group_concat(distinct case when rd.field_name = 'vial_move_date'
            then rd.value end separator '\\n') `vial_move_date`
      FROM redcap_data rd
      WHERE rd.project_id = ".PROJECT_ID.
            " GROUP BY rd.record, vial_instance) t
        WHERE vial_dist_status <> '2' AND vial_prev_freezer_box <> '' ) vial
        ON sample.red_rec_number=vial.red_rec_number AND vial_sample_ref = sample_instance )";

        $result = db_query($sql);
        $module->emDebug('$sql ' . $sql);

        $module->emDebug('$result ' . print_r($result, true));

        $returnData = [];
        while ($row = db_fetch_assoc($result)) {
            $module->emDebug('row ' . print_r($row, true));
            $returnData[] = $row;
        }
        $module->emDebug('$returnData ' . json_encode($returnData));
        echo '{"success":true, "data":' . json_encode($returnData) . '}';

    }

} catch (LogicException $e) {
    echo $e->getMessage();
}


?>

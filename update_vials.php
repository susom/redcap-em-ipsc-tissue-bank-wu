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

    if (!isset($_POST['updateType'])) {
        throw new LogicException('An update type is required');
    }

    /**
     * Run the SQL corresponding to the user supplied spec
     */

    function getRecordIds() {
        global $module;
        $matches = [];
        preg_match_all(
            "/editInstance\((?P<record>.*?),(?P<event_id>.*?),(?P<instrument>.*?),(?P<instance>.*?)\)/",
        $_POST['tablehtml'], $matches);
        $module->emDebug('matches: ' . print_r($matches, true));
        return $matches;
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
            echo '{"success":false,"error":{"message": "Distribution can only be cancelled for vials in \"planned\" status."}}';
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
                return $result;
            } else {
                return 1;
            }
        }
    }

    function getPlanned() {
        global $module;

        $sql="select distinct COALESCE (sample.red_rec_number,'') `record`, 
            COALESCE (vial.vial_id,'') `instance`, 
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



        /*$sql = "SELECT DISTINCT dist_status.record AS record, dist_status.instance as instance,".
            "sample_date.value AS sample_date, sample_line.value AS line,".
            "substring(md.element_enum, instr(md.element_enum, smp_type.value) + length(smp_type.value) + 2,".
         "locate('\\\\n',md.element_enum,instr(md.element_enum, smp_type.value) + length(smp_type.value))".
          "-(instr(md.element_enum, smp_type.value) + length(smp_type.value) + 2)) as type,".
            " passage.value AS passage, vialid.value AS vial_id,".
            "box.value AS freezer_box, slot.value AS freezer_slot, dist_by.value AS dist_by,".
            "dist_to.value AS dist_to, dist_date.value AS dist_date ".
            "FROM redcap_data dist_status ".
            "JOIN redcap_data vialid ON dist_status.project_id = vialid.project_id ".
            "AND dist_status.record = vialid.record AND dist_status.event_id = vialid.event_id ".
            "AND vialid.field_name = 'vial_id' ".
            "AND COALESCE(dist_status.instance, 1) = COALESCE(vialid.instance, 1) ".
            "JOIN redcap_data box ON dist_status.project_id = box.project_id ".
            "AND dist_status.event_id = box.event_id AND dist_status.record = box.record ".
            "AND COALESCE(dist_status.instance, 1) = COALESCE(box.instance, 1) ".
            "AND box.field_name = 'vial_freezer_box' ".
            "JOIN redcap_data slot ON dist_status.project_id = slot.project_id ".
            "AND dist_status.event_id = slot.event_id AND dist_status.record = slot.record ".
            "AND COALESCE(dist_status.instance, 1) = COALESCE(slot.instance, 1) ".
            "AND slot.field_name = 'vial_freezer_slot' ".
            "JOIN redcap_data dist_to ON dist_status.project_id = dist_to.project_id ".
            "AND dist_status.event_id = dist_to.event_id AND dist_status.record = dist_to.record ".
            "AND COALESCE(dist_status.instance, 1) = COALESCE(dist_to.instance, 1) ".
            "AND dist_to.field_name = 'vial_dist_to' ".
            "JOIN redcap_data dist_by ON dist_status.project_id = dist_by.project_id ".
            "AND dist_status.event_id = dist_by.event_id AND dist_status.record = dist_by.record ".
            "AND COALESCE(dist_status.instance, 1) = COALESCE(dist_by.instance, 1) ".
            "AND dist_by.field_name = 'vial_dist_by' ".
            "JOIN redcap_data dist_date ON dist_status.project_id = dist_date.project_id ".
            "AND dist_status.event_id = dist_date.event_id AND dist_status.record = dist_date.record ".
            "AND COALESCE(dist_status.instance, 1) = COALESCE(dist_date.instance, 1) ".
            "AND dist_date.field_name = 'vial_dist_date' ".
            "JOIN redcap_data smp_ref ON dist_status.project_id = smp_ref.project_id ".
            "AND dist_status.event_id = smp_ref.event_id AND dist_status.record = smp_ref.record ".
            "AND COALESCE(dist_status.instance, 1) = COALESCE(smp_ref.instance, 1) ".
            "AND smp_ref.field_name = 'vial_sample_ref' ".
            "JOIN redcap_data sample_date ON dist_status.project_id = sample_date.project_id ".
            "AND dist_status.record = sample_date.record AND dist_status.event_id = sample_date.event_id ".
            "AND COALESCE(sample_date.instance, 1) = COALESCE(smp_ref.value, 1) ".
            "AND sample_date.field_name = 'smp_date_deposited' ".
            "JOIN redcap_data sample_line ON dist_status.project_id = sample_line.project_id ".
            "AND dist_status.record = sample_line.record AND dist_status.event_id = sample_line.event_id " .
            "AND COALESCE(sample_line.instance, 1) = COALESCE(smp_ref.value, 1) ".
            "AND sample_line.field_name = 'smp_line_id' ".
            "JOIN redcap_data smp_type ON dist_status.project_id = smp_type.project_id ".
            "AND dist_status.record = smp_type.record AND dist_status.event_id = smp_type.event_id ".
            "AND COALESCE(smp_type.instance, 1) = COALESCE(smp_ref.value, 1) ".
            "AND smp_type.field_name = 'smp_type' ".
            'JOIN redcap_metadata md ON md.project_id=smp_type.project_id '.
           'AND md.field_name=smp_type.field_name '.
            "JOIN redcap_data passage ON dist_status.project_id = passage.project_id ".
            "AND dist_status.record = passage.record AND dist_status.event_id = passage.event_id ".
            "AND COALESCE(passage.instance, 1) = COALESCE(smp_ref.value, 1) ".
            "AND passage.field_name = 'smp_passage_number' ".
            "WHERE dist_status.project_id = ".PROJECT_ID.
            " AND dist_status.field_name = 'vial_dist_status' AND dist_status.value = '1'";*/

        $result = db_query($sql);
        $returnData = [];
        while ($row = db_fetch_assoc($result)) {
            $module->emDebug(print_r($row, true));
            $returnData[] =$row;
        }
        $module->emDebug('return: '.json_encode($returnData));
        return '{"data":' . json_encode($returnData) . '}';
    }

    $module->emDebug('$_POST: ' . print_r($_POST, true));

    if ($_POST['updateType'] == 'delete') {
        $dataDictionary = REDCap::getDataDictionary(PROJECT_ID, 'array', false, null, 'vial');
        $matches = getRecordIds();
        $sql = "delete from redcap_data where project_id=" . PROJECT_ID .
            " and event_id =" . $matches['event_id'][0] .
            " and field_name in ('".implode("','",array_keys($dataDictionary))."','vial_complete')".
            " and record=" . $matches['record'][0] .
            " and instance in (" . implode(',', $matches['instance']) . ")";
        $module->emDebug('delete sql: ' . $sql);
        //if (db_query($sql)) {
        echo '{"success":true}';
        //}
    } else if ($_POST['updateType'] === 'cancel') {
        $matches = getRecordIds();
        $return = cancelPlanned($matches['record'][0], $matches['instance']);
        if (count($return['errors'])) {
            echo '{"success":false, "error":"'.print_r($return['errors'], true).'"}';
        } else {
            echo '{"success":true}';
        }
    } else if ($_POST['updateType'] == 'cancelPlannedReport') {
        $recordsToSave = json_decode($_POST['recordsToSave'], true);
        $module->emDebug('recordsToSave: '. print_r($recordsToSave, true));
        $instances = [];
        foreach($recordsToSave as $record) {
            $instances[]=$record['instance'];
        }
        $return = cancelPlanned($recordsToSave[0]['record'], $instances);
        if (count($return['errors'])) {
            echo '{"success":false, "error":"'.print_r($return['errors'], true).'"}';
        } else {
            $temp = getPlanned();
            $module->emDebug('cancelPlannedReport return: '.$temp);
            echo $temp;
        }
    } else if ($_POST['updateType'] == 'distribute') {
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
            echo '{"success":false, "error":"'.print_r($result['errors'], true).'"}';
        } else {
            echo '{"success":true}';
        }

    } else if ($_POST['updateType'] == 'move') {
        $matches = [];
        preg_match_all(
            "/editInstance\((?P<record>.*?),(?P<event_id>.*?),(?P<instrument>.*?),(?P<instance>.*?)\).*?".
            "<td>.*?<\/td><td>(?P<box>.*?)<\/td><td>(?P<slot>.*?)<\/td><\/tr>/",
            $_POST['tablehtml'], $matches);
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
            echo '{"success":false, "error":"'.print_r($result['errors'], true).'"}';
        } else {
            echo '{"success":true}';
        }
    } else if ($_POST['updateType'] == 'emptySlotReport') {
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
    } else if ($_POST['updateType'] == 'plannedReport') {
        echo getPlanned();
    } else if ($_POST['updateType']=="saveShipped") {
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
            echo '{"success":false, "error":"'.print_r($result['errors'], true).'"}';
        } else {
            echo getPlanned();
        }

    } else if ($_POST['updateType']=='moveReport') {
        $module->emDebug('moveReport');

        $sql="select distinct COALESCE (sample.red_rec_number,'') `record`, 
            /*COALESCE (vial.vial_id,'') `instance`, */
            COALESCE (sample.smp_date_deposited,'') `deposit_date`, 
            COALESCE (sample.smp_line_id,'') `line`, 
            /*COALESCE (sample.smp_type,'') `type`, */
            COALESCE (vial.vial_id,'') `vial_id`, 
            COALESCE (vial.vial_prev_freezer_box,'') `prev_box`, 
            COALESCE (vial.vial_prev_freezer_slot,'') `prev_slot`,
            COALESCE (vial.vial_freezer_box,'') `box`, 
            COALESCE (vial.vial_freezer_slot,'') `slot`,
            COALESCE (vial.vial_move_date,'') `move_date` 

        FROM ((select * from (select rd.record  red_rec_number ,
            COALESCE(rd.`instance`, 1) as sample_instance,
            /*group_concat(distinct case when rd.field_name = 'smp_type' then 
                substring(md.element_enum, instr(md.element_enum, rd.value) + length(rd.value) + 2,
            locate('\\\\n',
                md.element_enum,
                instr(md.element_enum, rd.value) + length(rd.value))-(instr(md.element_enum, rd.value) + length(rd.value) + 2)) end separator '\\n') as `smp_type`,  */                       
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

        /*$sql = "select prevbox.record as record,  sample_date.value as deposit_date, sample_line.value as line, ".
            "vialid.value as vial_id, prevbox.value as prev_box, prevslot.value as prev_slot, ".
            "box.value as box, slot.value as slot, move_date.value as move_date " .
            "from redcap_data prevbox ".
            "join redcap_data prevslot on prevbox.project_id = prevslot.project_id " .
            "and prevbox.event_id = prevslot.event_id ".
            "and prevbox.record = prevslot.record and coalesce(prevbox.instance,1) = coalesce(prevslot.instance,1) " .
            "join redcap_data vialid on prevbox.project_id = vialid.project_id " .
            "and prevbox.event_id = vialid.event_id ".
            "and prevbox.record = vialid.record and coalesce(prevbox.instance,1) = coalesce(vialid.instance,1) ".
            "join redcap_data box on prevbox.project_id = box.project_id " .
            "and prevbox.event_id = box.event_id ".
            "and prevbox.record = box.record and coalesce(prevbox.instance,1) = coalesce(box.instance,1) ".
            "join redcap_data slot on prevbox.project_id = slot.project_id " .
            "and prevbox.event_id = slot.event_id ".
            "and prevbox.record = slot.record and coalesce(prevbox.instance,1) = coalesce(slot.instance,1) " .
            "join redcap_data dist on prevbox.project_id = dist.project_id " .
            "and prevbox.record = dist.record and coalesce(prevbox.instance,1) = coalesce(dist.instance,1) " .

            "JOIN redcap_data smp_ref ON prevbox.project_id = smp_ref.project_id ".
            "AND prevbox.event_id = smp_ref.event_id AND prevbox.record = smp_ref.record ".
            "AND COALESCE(prevbox.instance, 1) = COALESCE(smp_ref.instance, 1) ".
            "AND smp_ref.field_name = 'vial_sample_ref' ".

            "JOIN redcap_data sample_date ON prevbox.project_id = sample_date.project_id ".
            "AND prevbox.record = sample_date.record AND prevbox.event_id = sample_date.event_id ".
            "AND COALESCE(sample_date.instance, 1) = COALESCE(smp_ref.value, 1) ".
            "AND sample_date.field_name = 'smp_date_deposited' ".

            "JOIN redcap_data sample_line ON prevbox.project_id = sample_line.project_id ".
            "AND prevbox.record = sample_line.record AND prevbox.event_id = sample_line.event_id " .
            "AND COALESCE(sample_line.instance, 1) = COALESCE(smp_ref.value, 1) ".
            "AND sample_line.field_name = 'smp_line_id' ".

            "left join redcap_data move_date on prevbox.project_id = move_date.project_id " .
            "and prevbox.event_id = move_date.event_id ".
            "and move_date.field_name = 'vial_move_date' ".
            "and prevbox.record = move_date.record and coalesce(prevbox.instance,1) = coalesce(move_date.instance,1) " .
            " where prevbox.project_id = " . PROJECT_ID .
            " and prevbox.field_name='vial_prev_freezer_box'" .
            " and prevslot.field_name='vial_prev_freezer_slot'" .
            " and vialid.field_name='vial_id'" .
            " and box.field_name='vial_freezer_box'" .
            " and slot.field_name='vial_freezer_slot'" .
            " and dist.field_name='vial_dist_status'" .
            " and dist.value in ('0','1')";*/
        $result = db_query($sql);
        $module->emDebug('$sql ' . $sql);

        $module->emDebug('$result ' . print_r($result, true));

        $returnData = [];
        while ($row = db_fetch_assoc($result)) {
            $module->emDebug('row ' . print_r($row, true));
            $returnData[] = $row;
        }
        $module->emDebug('$returnData ' . json_encode($returnData));
        echo '{"data":' . json_encode($returnData) . '}';

    }

} catch (LogicException $e) {
    echo $e->getMessage();
}


?>

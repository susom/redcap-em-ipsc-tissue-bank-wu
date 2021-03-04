<?php

namespace Stanford\iPSCTissueBankWu;

use MCRI\InstanceTable\InstanceTable;
use REDCap;

require_once "../redcap-instance-table_v3.0.1/InstanceTable.php";
require_once "emLoggerTrait.php";

class iPSCTissueBankWu extends InstanceTable
{
    use emLoggerTrait;

    const ACTION_TAG_FILTER = '@INSTANCETABLE_FILTER';

    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
        //$this->createEmptySlotView();
    }

    protected function setTaggedFields()
    {
        parent::setTaggedFields();
        $instrumentFields = REDCap::getDataDictionary('array', false, true, $this->instrument);
        $matches = array();
        foreach ($this->taggedFields as $taggedIndex => $repeatingFormDetails) {
            $fieldDetails = $instrumentFields[$repeatingFormDetails['field_name']];
            if (preg_match("/" . self::ACTION_TAG_FILTER . "\s*=\s*\"(.*?)\"\s?/",
                $fieldDetails['field_annotation'], $matches)) {
                $this->emDebug('repeatingFormDetails start : ' . print_r($repeatingFormDetails, true));

                $tagFilter = $matches[1];
                $urlPieces = explode("&", $repeatingFormDetails['ajax_url']);
                foreach ($urlPieces as $index => $piece) {
                    if (preg_match("/filter=([^&].*)/", $piece, $matches)) {
                      $this->emDebug('filter matches: '.print_r($matches, true));
                        $filter = trim($matches[1]);
                        if (strlen($filter)) {
                            $filter .= ' and ' . '(' . $tagFilter . ')';
                        } else {
                            $filter = $tagFilter;
                        }
                        $urlPieces[$index]='filter='.$filter;
                        break;
                    }
                }
                $repeatingFormDetails['ajax_url'] = implode('&', $urlPieces);
                $this->taggedFields[$taggedIndex] = $repeatingFormDetails;
            }
            $this->emDebug('repeatingFormDetails end : ' . print_r($repeatingFormDetails, true));
        }
    }

    public function getSelectableInstanceData($record, $event, $form, $filter)
    {
        $this->emLog('form: ' . $form . ' filter: ' . $filter);
        $instanceData = $this->getInstanceData($record, $event, $form, $filter, false);
        foreach ($instanceData as $index => $row) {
            array_unshift($instanceData[$index], '');
        }
        return $instanceData;
    }

    protected function initDb()
    {
        /*"SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.tables where TABLE_SCHEMA='redcap'
and TABLE_TYPE='VIEW'";*/

        $num_array = array();
        for ($i = 1; $i < 780; $i++) {
            $num_array[] = $i;
        }
        $sql = "create table ipsc_wu_numbers as select " . implode($num_array, ' as n union all select ') . ' as n';
        db_query($sql);

        $sql = "create table ipsc_wu_all_slots ";
        $sql .= "select concat('A',lpad(cast(n1.n AS char),4,'0')) as box,lpad(cast(n2.n AS char),3,'0') as slot from ipsc_wu_numbers n1, ipsc_wu_numbers n2 where n1.n <= 200 and n2.n <=100 union ";
        $sql .= "select concat('B',lpad(cast(n1.n AS char),4,'0')) as box,lpad(cast(n2.n AS char),3,'0') as slot from ipsc_wu_numbers n1, ipsc_wu_numbers n2 where n1.n <= 200 and n2.n <=100 union ";
        $sql .= "select concat('D',lpad(cast(n1.n AS char),4,'0')) as box,lpad(cast(n2.n AS char),3,'0') as slot from ipsc_wu_numbers n1, ipsc_wu_numbers n2 where n2.n <=100 ";
        db_query($sql);
        $freezers = ['A', 'B', 'D'];
        foreach ($freezers as $freezer) {
            $sql = "create or replace view IPSC_WU_USED_" . $freezer .
                " as SELECT box.value as box, count(slot.value) as used_slots," .
                "group_concat(slot.value) as used_slots_csv FROM redcap_data dist " .
                "join redcap_data box on dist.record = box.record and COALESCE(dist.instance, 1) = COALESCE(box.instance, 1) " .
                "join redcap_data slot on dist.record = slot.record 
            and COALESCE(dist.instance, 1) = COALESCE(slot.instance, 1) " .
                "where dist.project_id = " . PROJECT_ID .
                " and dist.field_name='vial_dist_status' and dist.value in ['0','1'] " .
                "and box.field_name='vial_freezer_box' and box.value like '" . $freezer . "%' " .
                "and slot.field_name='vial_freezer_slot' group by box.value order by box.value";
            db_query($sql);
        }
    }

    public function getFreezerSpace($freezerId, $numSlots)
    {
        $this->emDebug('numSlots = ' . $numSlots);
        if (!$numSlots) return;
        if ($numSlots > 5) {
            $sql = "select box from ipsc_wu_all_slots where box like '" . $freezerId . "%' and box not in (select box from ipsc_wu_used_" . strtolower($freezerId) . ") limit 1";
            $this->emDebug('getFreezerSpace $sql ' . $sql);
            $result1 = db_query($sql);
            $row = db_fetch_assoc($result1);
            $this->emDebug('getFreezerSpace $row ' . print_r($row, true));
            $emptyBox = $row['box'];
            $prevBoxValue = (int)substr($emptyBox, -4) - 1;
            $this->emDebug('getFreezerSpace $prevBoxValue ' . $prevBoxValue);
            $freezer = false;
            if ($prevBoxValue > 0) {
                $prevBox = $freezerId . str_pad($prevBoxValue, 4,
                        '0', STR_PAD_LEFT);
                $sql = "select box, used_slots, used_csv from ipsc_wu_used_" . strtolower($freezerId) .
                    " where box ='" . $prevBox . "'";
                $freezer = $this->allocateFreezerSpace($sql, $freezerId, $numSlots, true);
            }
            if (!$freezer) {
                $emptySlots = array();
                for ($slot = 1; $slot <= $numSlots; $slot++) {
                    $emptySlots[] = $slot;
                }
                $freezer = array();
                $freezer['box'] = $emptyBox;
                $freezer['slots'] = $emptySlots;
            }
            return $freezer;
        } else {
            $sql = "select box, used_slots, used_csv from ipsc_wu_used_"
                . strtolower($freezerId)
                . " where used_slots <" . (100 - $numSlots) . ' order by box limit 1';
            $this->emDebug('getFreezerSpace $sql ' . $sql);
            return $this->allocateFreezerSpace($sql, $freezerId, $numSlots, false);
        }
    }

    public function getSlotsIfEmpty($freezerId, $numSlots)
    {
        $sql = "select count(1) as num from ipsc_wu_used_" . strtolower($freezerId);
        $result1 = db_query($sql);
        $row = db_fetch_assoc($result1);
        $this->emDebug('getSlotsIfEmpty $freezerId ' . $freezerId . ' $numSlots '
            . $numSlots . ' $row ' . print_r($row, true));
        if ($row['num'] == 0) {
            $freezer = array();
            $freezer['box'] = strtoupper($freezerId) . '0001';
            $slots = array();
            for ($n = 1; $n <= $numSlots; $n++) {
                $slots[] = str_pad($n, 3, '0', STR_PAD_LEFT);
            }
            $freezer['slots'] = $slots;
            $this->emDebug('getSlotsIfEmpty $freezer ' . print_r($freezer, true));
            return $freezer;
        }
        return false;
    }

    public function allocateFreezerSpace($used_sql, $freezerId, $numSlots, $consecutive = false)
    {
        $result1 = db_query($used_sql);
        // if there are no results, this means either all no freezer boxes have space or
        // no freezer boxes have been allocated.
        if ($result1->num_rows === 0) {
            return $this->getSlotsIfEmpty($freezerId, $numSlots);
        }
        $row = db_fetch_assoc($result1);

        $this->emDebug('allocateFreezerSpace $row ' . print_r($row, true));

        $freeSlots = 100 - $row['used_slots'];
        if ($freeSlots >= $numSlots) {
            $usedSlots = explode(",", $row['used_csv'],);
            //$this->emDebug('allocateFreezerSpace $usedSlots '. print_r($usedSlots, true));

            $usedSlots = array_flip($usedSlots);
            $this->emDebug('allocateFreezerSpace $usedSlots ' . print_r($usedSlots, true));

            $emptyCounter = 0;
            $emptySlots = array();
            for ($slot = 1; $slot <= 100; $slot++) {
                $slot_str = str_pad($slot, 3, '0', STR_PAD_LEFT);
                if (isset($usedSlots[$slot_str]) && $consecutive) {
                    $emptyCounter = 0;
                    $emptySlots = array();
                } else if (!isset($usedSlots[$slot_str])) {
                    $emptyCounter++;
                    $emptySlots[] = $slot_str;
                }
                if ($emptyCounter == $numSlots) {
                    $this->emDebug('box: ' . $row['box'] .
                        ' empty_slots: ' . print_r($emptySlots, true));
                    $freezer = array();
                    $freezer['box'] = $row['box'];
                    $freezer['slots'] = $emptySlots;
                    return $freezer;
                }
            }
        }
        return false;
    }

    public function getNewVialId()
    {
        $continue = true;
        while ($continue) {

            for ($i = 0; $i < 11; $i++) {
                $vialId .= rand(0, 9);
            }
            // original code uses an ipsc_vial_number table for this.  necessary?
            $sql = "SELECT count(1) as num from redcap_data where project_id=" . PROJECT_ID .
                " and field_name='vial_id' and value='V" . $vialId . "'";
            $r_result = db_query($sql);
            $row = db_fetch_assoc($r_result);

            $this->emDebug('sql: ' . $sql . ' $row: ' . print_r($row, true));
            if ($row['num'] == 0) $continue = false;
            $this->emDebug('$continue: ' . $continue);

        }
        return 'V' . $vialId;
    }

    protected function saveVial($record, $sample, $instance, $box, $slot)
    {
        $data = array();
        $data["red_rec_number"] = $record;
        $data["redcap_repeat_instrument"] = "vial";
        $data["redcap_repeat_instance"] = $instance;
        $data["vial_freezer_box"] = $box;
        $data["vial_freezer_slot"] = $slot;
        $data["vial_id"] = $this->getNewVialId();
        $data["vial_sample_ref"] = $sample;
        $data["vial_dist_status"] = 0;
        $this->emDebug('$data: ' . json_encode(array($data)));
        REDCap::saveData(PROJECT_ID, 'json', json_encode(array($data)));
    }

    public function saveNewVials($record, $sample, $numA, $numB, $numD)
    {
        // get max vial instance for this record and vial ID
        $sql = "SELECT max(instance) as max_instance from redcap_data where project_id=" . PROJECT_ID
            . " and record='" . $record . "'";
        $this->emDebug('max instance $sql is ' . $sql);
        $result1 = db_query($sql);
        $row = db_fetch_assoc($result1);
        $this->emDebug('max instance $row is ' . print_r($row, true));

        $instance = $row['max_instance']+1;
        $this->emDebug('max instance: ' . $instance);

        $freezerSpec = $this->getFreezerSpace('A', $numA);
        $this->emDebug('freezer space A ' . print_r($freezerSpec, true));

        for ($i = 0; $i < $numA; $i++) {
            $this->saveVial($record, $sample, $instance++, $freezerSpec['box'], $freezerSpec['slots'][$i]);
        }
        $freezerSpec = $this->getFreezerSpace('B', $numB);
        $this->emDebug('freezer space B ' . print_r($freezerSpec, true));

        for ($i = 0; $i < $numB; $i++) {
            $this->saveVial($record, $sample, $instance++, $freezerSpec['box'], $freezerSpec['slots'][$i]);
        }
        $freezerSpec = $this->getFreezerSpace('D', $numD);
        $this->emDebug('freezer space D ' . print_r($freezerSpec, true));

        for ($i = 0; $i < $numD; $i++) {
            $this->saveVial($record, $sample, $instance++, $freezerSpec['box'], $freezerSpec['slots'][$i]);
        }
    }



    public function redcap_save_record($project_id, $record = null, $instrument, $event_id, $group_id = null, $survey_hash = null, $response_id = null, $repeat_instance = 1)
    {
        parent::redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance);
        if ($instrument === 'sample') {
            $this->emDebug('$repeat_instance: ' . $repeat_instance);
            $r_result = REDCap::getData(PROJECT_ID, 'json',
                $record, ['smp_new', 'smp_a', 'smp_b', 'smp_d'], null, null, false, false, false, "['redcap_repeat_instance']=" . $repeat_instance,);
            $this->emDebug('$r_result: ' . $r_result);
            $results = json_decode($r_result, true);
            $instance_results = $results[$repeat_instance - 1];
            if ($instance_results['smp_new']) {
                $this->saveNewVials($record, $repeat_instance, $instance_results['smp_a'],
                    $instance_results['smp_b'], $instance_results['smp_d']);
                $data = array();
                $data["red_rec_number"] = $record;
                $data["redcap_repeat_instrument"] = "sample";
                $data["redcap_repeat_instance"] = $repeat_instance;
                $data["smp_new"] = 0;
                $data["smp_a"] = "";
                $data["smp_b"] = "";
                $data["smp_d"] = "";
                $data["sample_complete"] = 2;
                $this->emDebug('$data: ' . json_encode(array($data)));
                REDCap::saveData(PROJECT_ID, 'json', json_encode(array($data)), 'overwrite');
            }
        }
    }

    protected function insertJS()
    {
        ?>
      <style type="text/css">
          .<?php echo self::MODULE_VARNAME;?> tbody tr {
              font-weight: normal;
          }

          /*.greenhighlight {background-color: inherit !important; }*/
          /*.greenhighlight table td {background-color: inherit !important; }*/
      </style>
      <script type="text/javascript">
        'use strict';
        var <?php echo self::MODULE_VARNAME;?> =
        (function (window, document, $, app_path_webroot, pid, simpleDialog, undefined) { // var MCRI_FormInstanceTable ...
          var isSurvey = <?php echo ($this->isSurvey) ? 'true' : 'false';?>;
          var tableClass = '<?php echo self::MODULE_VARNAME;?>';
          var langYes = '<?php echo js_escape($this->lang['design_100']);?>';
          var langNo = '<?php echo js_escape($this->lang['design_99']);?>';
          var config = <?php echo json_encode($this->taggedFields, JSON_PRETTY_PRINT);?>;
          var taggedFieldNames = [];
          var defaultValueForNewPopup = '<?php echo js_escape($this->defaultValueForNewPopup);?>';
          var update_vials_url = '<?php echo $this->getUrl('update_vials.php') ?>'

        $(document).ready(function () {
            config.forEach(function (taggedField) {
              taggedFieldNames.push(taggedField.field_name);
              $('#' + taggedField.field_name + '-tr td:last')
                .append(taggedField.markup);
              var rows_selected = [];
              $.fn.dataTable.ext.errMode = 'none';

              var thisTbl = $('#' + taggedField.html_table_id)
                .on( 'error.dt', function ( e, settings, techNote, message ) {
                  console.log( 'An error has been reported by DataTables: ', message );
                } )
                .DataTable({
                  "stateSave": true,
                  "stateDuration": 0,
                  'columnDefs': [{
                    'targets': 0,
                    'searchable': false,
                    'orderable': false,
                    'className': 'dt-body-center',
                    'render': function (data, type, full, meta) {
                      return '<input type="checkbox" class="' + taggedField.html_table_id + '_checkbox">';
                    },
                    'rowCallback': function (row, data, dataIndex) {
                      // Get row ID
                      var vialId = data[2];

                      // If row ID is in the list of selected row IDs
                      if ($.inArray(vialId, rows_selected) !== -1) {
                        $(row).find('input[type="checkbox"]').prop('checked', true);
                        $(row).addClass('selected');
                      }
                    }
                  },
                  {
                    'targets': function() {
                      if (taggedField.html_table_id.indexOf('frozen') > -1) {
                        return [5,6,7,8,9,10];
                      }
                    },
                    'visible': false,
                    'searchable':false
                  }]
                });
              /*if (taggedField.html_table_id.indexOf('frozen') > -1) {
                thisTbl.columns([5,6,7,8,9,10]).visible(false);
              }*/
              thisTbl.columns.adjust().draw();

              if (!isSurvey) {
                thisTbl.ajax.url(taggedField.ajax_url).load();
              }
              // Handle click on checkbox
              $('#' + taggedField.html_table_id + ' tbody').on('click', 'input[type="checkbox"]', function (e) {
                var $row = $(this).closest('tr');

                // Get row data
                var data = thisTbl.row($row).data();

                // Get row ID
                var vialId = data[2];

                // Determine whether row ID is in the list of selected row IDs
                var index = $.inArray(vialId, rows_selected);

                // If checkbox is checked and row ID is not in list of selected row IDs
                if (this.checked && index === -1) {
                  rows_selected.push(vialId);

                  // Otherwise, if checkbox is not checked and row ID is in list of selected row IDs
                } else if (!this.checked && index !== -1) {
                  rows_selected.splice(index, 1);
                }

                if (this.checked) {
                  $row.addClass('selected');
                } else {
                  $row.removeClass('selected');
                }

                // Update state of "Select all" control
                updateDataTableSelectAllCtrl(thisTbl);

                // Prevent click event from propagating to parent
                e.stopPropagation();
              });

              // Handle click on table cells with checkboxes
              $('#' + taggedField.html_table_id).on('click', 'tbody td, thead th:first-child', function (e) {
                $(this).parent().find('input[type="checkbox"]').trigger('click');
              });

              // Handle click on "Select all" control
              $('thead input[name="select_all"]', thisTbl.table().container()).on('click', function (e) {
                if (this.checked) {
                  $('#' + taggedField.html_table_id + ' tbody input[type="checkbox"]:not(:checked)').trigger('click');
                } else {
                  $('#' + taggedField.html_table_id + ' tbody input[type="checkbox"]:checked').trigger('click');
                }

                // Prevent click event from propagating to parent
                e.stopPropagation();
              });

              // Handle table draw event
              thisTbl.on('draw', function () {
                // Update state of "Select all" control
                updateDataTableSelectAllCtrl(thisTbl);
              });


            });

            // override global function doGreenHighlight() so we can skip the descriptive text fields with tables
            var globalDoGreenHighlight = doGreenHighlight;
            doGreenHighlight = function (rowob) {
              if ($.inArray(rowob.attr('sq_id'), taggedFieldNames) === -1) {
                globalDoGreenHighlight(rowob);
              }
            };

          });

          function instancePopup(title, record, event, form, instance) {
            var url = app_path_webroot + 'DataEntry/index.php?pid=' + pid + '&id=' + record + '&event_id=' + event + '&page=' + form + '&instance=' + instance + '&extmod_instance_table=1' + defaultValueForNewPopup;
            popupWindow(url, title, window, 700, 950);
            //refreshTableDialog(event, form);
            return false;
          }

          function popupWindow(url, title, win, w, h) {
            var y = win.top.outerHeight / 2 + win.top.screenY - (h / 2);
            var x = win.top.outerWidth / 2 + win.top.screenX - (w / 2);
            return win.open(url, title, 'status,scrollbars,resizable,width=' + w + ',height=' + h + ',top=' + y + ',left=' + x);
          }

          function refreshTableDialog() {
            simpleDialog('Refresh the table contents and display any changes (instances added, updated or deleted).'
              , 'Refresh Table?', null, 500
              , null, langNo
              , function () {
                // refresh all instance tables (e.g. to pick up changes to multiple forms across repeating event
                $('.' + tableClass).each(function () {
                  $(this).DataTable().ajax.reload(null, false); // don't reset user paging on reload
                });
              }, langYes
            );
          }

          //
          // Updates "Select all" control in a data table
          // from https://www.gyrocode.com/articles/jquery-datatables-checkboxes/
          //
          function updateDataTableSelectAllCtrl(table) {
            var $table = table.table().node();
            var tableId = $table.id;
            var $chkbox_all = $('tbody input[type="checkbox"]', $table);
            var $chkbox_checked = $('tbody input[type="checkbox"]:checked', $table);
            var chkbox_select_all = $('thead input[name="select_all"]', $table).get(0);

            // If none of the checkboxes are checked
            if ($chkbox_checked.length === 0) {
              chkbox_select_all.checked = false;
              if ('indeterminate' in chkbox_select_all) {
                chkbox_select_all.indeterminate = false;
              }
              $('.' + tableId + '_btn').prop('disabled', true);
              // If all of the checkboxes are checked
            } else if ($chkbox_checked.length === $chkbox_all.length) {
              chkbox_select_all.checked = true;
              if ('indeterminate' in chkbox_select_all) {
                chkbox_select_all.indeterminate = false;
              }
              $('.' + tableId + '_btn').prop('disabled', false);

              // If some of the checkboxes are checked
            } else {
              chkbox_select_all.checked = true;
              if ('indeterminate' in chkbox_select_all) {
                chkbox_select_all.indeterminate = true;
              }
              $('.' + tableId + '_btn').prop('disabled', false);
            }
          }

          function displaySelected(tableId) {
            let colIndices = [];
            if (tableId.indexOf('frozen') != -1) {
              colIndices = [2, 3, 4, 5];
            } else {
              colIndices = [2, 3, 4, 5, 6, 7, 8, 9];
            }

            let colNames = $('<tr>');
            let origColNames = $('#' + tableId + ' thead tr').clone();
            for (var i = 0; i < colIndices.length; i++) {
              colNames.append(origColNames.find('th:nth-child(' + colIndices[i] + ')').clone());
            }
            var displayTable = $('<table>').append($('<thead></thead>').append(colNames));

            let selected_rows = $('#' + tableId + ' .selected').clone();
            let tbody = $('<tbody>');
            selected_rows.each(function () {
              let row = $('<tr>');
              for (var i = 0; i < colIndices.length; i++) {
                row.append($(this).find(':nth-child(' + colIndices[i] + ')').clone());
              }
              tbody.append(row);
            });
            displayTable.append(tbody);
            return displayTable;
          }

          return {
            addNewInstance: function (record, event, form) {
              instancePopup('Add instance', record, event, form, '1&extmod_instance_table_add_new=1');
              return false;
            },
            editInstance: function (record, event, form, instance) {
              instancePopup('View instance', record, event, form, instance);
              return false;
            },
            deleteInstances: function (record, event, form, tableId) {
              let selectedTable = displaySelected(tableId);
              simpleDialog('<div style=&quot;margin-top:15px;color:#C00000;font-weight:bold;&quot;>' +
                'These vials will be permanently removed from the database.  Delete these vials?' +
                '</div> <table class="table table-striped table-bordered table-condensed">' + selectedTable.html() + '</table>',
                'DELETE VIALS', null, 600, null,
                'Cancel',
                function () {
                  $.ajax({
                    url: update_vials_url,
                    timeout: 60000000,
                    type: 'POST',
                    data: {"updateType":"delete", "tablehtml": '"'+selectedTable.find('tbody').html()+'"'},
                    dataType: 'json',
                    success: function (response) {
                      console.log("Success " + response);
                      refreshTables();
                    },
                    error: function (request, error) {
                      console.log('Request ' + request);
                      console.log('Error ' + error);
                    }
                  });
                },
                'Delete');
              console.log(selectedTable.html());
            },
            distributeInstances: function (record, event, form, tableId) {
              let selectedTable = displaySelected(tableId);
              simpleDialog('<table class="table table-striped table-bordered table-condensed">'
                + selectedTable.html() + '</table>' +
                '  <div class="form-group form-row"> <label for="distBy" class="form-label col-3">'+
                'Distributed by:</label>'+
                '<input type="text" class="form-control col-6" id="distBy"></input></div>'+
                '  <div class="form-group form-row"> <label for="distTo" class="form-label col-3">'+
                'Distribute to:</label>'+
                '<input type="text" class="form-control col-6" id="distTo"></input></div>'+
                '  <div class="form-group form-row"> <label for="distStatus" class="form-label col-3">'+
                'Status:</label>'+
                '<select class="form-control col-6" id="distStatus">'+
                '<option value="1">Planned</option><option value="2">Shipped</option></select></div>'+
                '  <div class="form-group form-row"> <label for="distIrb" class="form-label col-3">'+
                  'IRB Num:</label>'+
                  '<input type="text" class="form-control col-2" id="distIrb"></input>'+
                '  <label for="distIrbExp" class="form-label col-2">'+
                'IRB Exp Date:</label>'+
                '<input type="text" class="form-control col-2" id="distIrbExp"></input></div>',
                'DISTRIBUTE VIALS', null, 600, null,
                'Cancel',
                function () {
                  $.ajax({
                    url: update_vials_url,
                    timeout: 60000000,
                    type: 'POST',
                    data: {"updateType":"distribute", "tablehtml": '"'+selectedTable.find('tbody').html()+ '"',
                      "updateData": '{"vial_dist_by":"'+$('#distBy').val()
                        + '","vial_dist_to":"'+$('#distTo').val()
                        + '","vial_dist_status":"'+$('#distStatus').val()
                        + '","vial_dist_irb":"'+$('#distIrb').val()
                        + '","vial_dist_irb_exp":"'+$('#distIrbExp').val()+'"}'},
                    dataType: 'json',
                    success: function (response) {
                      console.log(response);
                      refreshTables();
                    },
                    error: function (request, error) {
                      console.log(request);
                      console.log(error);
                    }
                  });
                },
                'Distribute');            },
            reprintInstances: function (record, event, form, tableId) {
              let selectedTable = displaySelected(tableId);
            },
            moveInstances: function (record, event, form, tableId) {
              let selectedTable = displaySelected(tableId);
              simpleDialog('<table class="table table-striped table-bordered table-condensed">'
                + selectedTable.html() + '</table>' +
                '<div style=&quot;margin-top:15px;color:#c00000;font-weight:bolder;&quot;>' +
            '  <div class="form-group form-row"> <label for="moveToFreezer" class="form-label col-6">'+
              'Move the selected vials to Freezer Box:</label>'+
              '<select class="form-control col-2" id="moveToFreezer">'+
              '<option>A</option><option>B</option><option>D</option>'+
              '</select></div></div>' ,
                'MOVE VIALS', null, 600, null,
                'Cancel',
                function () {
                  $.ajax({
                    url: update_vials_url,
                    timeout: 60000000,
                    type: 'POST',
                    data: {"updateType":"move", "tablehtml": '"'+selectedTable.find('tbody').html()+'"',
                      "updateData": '"'+$('#moveToFreezer').val() + '"'},
                    dataType: 'json',
                    success: function (response) {
                      console.log(response);
                      refreshTables();
                    },
                    error: function (request, error) {
                      console.log(request);
                      console.log(error);
                    }
                  });
                },
                'Move');
            },
            cancelInstances: function (record, event, form, tableId) {
              let selectedTable = displaySelected(tableId);
              simpleDialog('<div style=&quot;margin-top:15px;color:#c00000;font-weight:bold;&quot;>' +
                'Cancel distribution of these vials?' +
                '</div> <table class="table table-striped table-bordered table-condensed">' + selectedTable.html() + '</table>',
                'CANCEL DISTRIBUTION', null, 600, null,
                'Close',
                function () {
                  $.ajax({
                    url: update_vials_url,
                    timeout: 60000000,
                    type: 'POST',
                    data: {"updateType":"cancel", "tablehtml": '"'+selectedTable.find('tbody').html()+'"'},
                    dataType: 'json',
                    success: function (response) {
                      console.log(response);
                      refreshTables();
                    },
                    error: function (request, error) {
                      console.log(request);
                      console.log(error);
                    }
                  });
                },
                'Cancel Distribution');
              console.log(selectedTable.html());
            },
          }
        })(window, document, jQuery, app_path_webroot, pid, simpleDialog);

        function refreshTables() {
          var tableClass = '<?php echo self::MODULE_VARNAME;?>';
          $('.' + tableClass).each(function () {
            $(this).DataTable().ajax.reload(null, false); // don't reset user paging on reload
          });
        }
      </script>
        <?php
    }

    protected function makeHtmlTable($tableElementId, $tableFormClass, $eventId, $formName, $canEdit, $scrollX = false)
    {

        $scrollStyle = ($scrollX) ? "max-width:790px;" : "";
        $nColumns = 2; // start at 2 for checkbox, # (Instance) column
        $html = '<div class="" style="margin-top:10px; margin-bottom:' . self::ADD_NEW_BTN_YSHIFT . ';">';
        $html .= '<table id="' . $tableElementId . '" class="table table-striped table-bordered table-condensed
        table-responsive ' . self::MODULE_VARNAME . ' ' . $tableFormClass . '" cellspacing="0" style="width:100%;' .
            $scrollStyle . '">';
        $html .= '<thead><tr><th><input type="checkbox" name="select_all" value="1" id="' . $tableElementId . '-select-all"></th>';
        $html .= '<th>#</th>'; // .$this->lang['data_entry_246'].'</th>'; // Instance
        $repeatingFormFields = REDCap::getDataDictionary('array', false, null, $formName);

        foreach ($repeatingFormFields as $repeatingFormFieldDetails) {
            // ignore descriptive text fields and fields tagged @FORMINSTANCETABLE_HIDE
            $matches = array();
            if ($repeatingFormFieldDetails['field_type'] !== 'descriptive') {
                if (!preg_match("/" . self::ACTION_TAG_HIDE_FIELD . "/",
                    $repeatingFormFieldDetails['field_annotation'])) {
                    $matches = array();
                    $relabel = preg_match("/" . self::ACTION_TAG_LABEL . "='(.+)'/", $repeatingFormFieldDetails['field_annotation'], $matches);
                    $colHeader = ($relabel) ? $matches[1] : $repeatingFormFieldDetails['field_label'];
                    $html .= "<th>$colHeader</th>";
                    $nColumns++;
                }
            }
        }

        $html .= '</tr></thead>';

        // if survey form get data now (as have no auth for an ajax call)
        if ($this->isSurvey) {
            $html .= '<tbody>';
            $instanceData = $this->getSelectableInstanceData($this->record, $eventId, $formName, null);
            if (count($instanceData) === 0) {
                $html .= '<tr><td colspan="' . $nColumns . '">No data available in table</td></tr>';
            } else {
                foreach ($instanceData as $row => $rowValues) {
                    $html .= '<tr>';
                    $html .= '<td></td>';
                    foreach ($rowValues as $value) {
                        $html .= "<td>$value</td>";
                    }
                    $html .= '</tr>';
                }
            }
            $html .= '</tbody>';
        }

        $html .= '</table>';

        // if frozen table
        if (strpos($tableElementId, 'frozen')) {
            $html .= '<div style="position:relative;top:' . self::ADD_NEW_BTN_YSHIFT . ';margin-bottom:5px;"><button type="button" class="btn btn-sm btn-success mr-2" onclick="' . self::MODULE_VARNAME . '.addNewInstance(\'' . $this->record . '\',' . $eventId . ',\'' . $formName . '\');"><span class="fas fa-plus-circle" aria-hidden="true"></span>&nbsp;' . $this->lang['data_entry_247'] . '</button>';// Add new

            $html .= '<button type="button" id="frozenDeleteButton" 
            class="btn btn-sm btn-danger mr-2 ' . $tableElementId . '_btn" onclick="' . self::MODULE_VARNAME .
                '.deleteInstances(\'' . $this->record . '\',' . $eventId . ',\'' . $formName . '\',\'' .
                $tableElementId . '\');" disabled><span class="fas fa-times-circle" aria-hidden="true"></span>&nbsp;' . $this->lang['global_19'] . '</button>';// Delete records
            $html .= '<button type="button" id="frozenDistributeButton"
            class="btn btn-sm btn-info mr-2 ' . $tableElementId . '_btn" onclick="' . self::MODULE_VARNAME . '.distributeInstances(\'' .
                $this->record . '\',' . $eventId . ',\'' . $formName . '\',\'' . $tableElementId . '\');" disabled><span class="fas fa-vial" aria-hidden="true"></span>&nbsp;Distribute</button>'; // Distribute

            $html .= '<button type="button"  id="frozenPrintButton" 
            class="btn btn-sm btn-info mr-2 ' . $tableElementId . '_btn" onclick="' .
                self::MODULE_VARNAME . '.reprintInstances(\'' . $this->record . '\',' . $eventId . ',\'' . $formName
                . '\',\'' . $tableElementId . '\');" disabled><span class="fas fa-print" aria-hidden="true"></span>&nbsp;Reprint</button>'; // Print vial labels

            $html .= '<button type="button" class="btn btn-sm btn-info ' . $tableElementId . '_btn" id="frozenMoveButton"
            onclick="' . self::MODULE_VARNAME . '.moveInstances(\'' . $this->record . '\',' . $eventId . ',\'' .
                $formName . '\',\'' . $tableElementId . '\');" disabled><span class="fas fa-arrow-alt-circle-right" aria-hidden="true"></span>&nbsp;Move</button>'; // Move vials
        }

        // if distribute table
        if (strpos($tableElementId, 'distribut')) {
            $html .= '<button type="button" id="distributeDeleteButton" 
            class="btn btn-sm btn-danger mr-2 ' . $tableElementId . '_btn" onclick="' .
                self::MODULE_VARNAME .
                '.deleteInstances(\'' . $this->record . '\',' . $eventId . ',\'' . $formName . '\',\'' .
                $tableElementId . '\');" disabled><span class="fas fa-times-circle " aria-hidden="true"></span>&nbsp;' . $this->lang['global_19'] . '</button>';// Delete records
            // no add button
            $html .= '<button type="button" id="distributeCancelButton"  
            class="btn btn-sm btn-warning ' . $tableElementId . '_btn" onclick="'
                . self::MODULE_VARNAME . '.cancelInstances(\'' . $this->record . '\',' . $eventId . ',\'' . $formName . '\',\''
                . $tableElementId . '\');" disabled><span class="fas fa-minus-circle" aria-hidden="true"></span>&nbsp;Cancel Distribution</button>'; // Cancel vial distribution
            $html .= '</div>';
        }
        return $html;
    }



}

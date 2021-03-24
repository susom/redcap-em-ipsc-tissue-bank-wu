<?php

namespace Stanford\iPSCTissueBankWu;

/** @var \Stanford\iPSCTissueBankWu\iPSCTissueBankWu $module */
error_reporting( E_ALL ^ ( E_NOTICE | E_WARNING | E_DEPRECATED ) );
$url = $module->getUrl("update_vials.php");
$module->emDebug('move report url ' . $url);
?>

<p>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="x-ua-compatible" content="ie=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <script src="https://code.jquery.com/jquery-3.3.1.js"></script>
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"
                integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl"
                crossorigin="anonymous"></script>
        <script src="https://cdn.datatables.net/1.10.23/js/jquery.dataTables.min.js"></script>
        <style>
            body {
                width: 90%;
                height: 100px;
                padding: 5px;

            }
        </style>
        <script type="text/javascript">
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

            try {
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
            } catch (e) {
              console.log( 'Caught exception: ' +  e.message);
            }
          }

          function print() {
            var url = '<?php echo $url; ?>';
            let selected = $('#moved_table .selected');//.clone();
            let recordInstances = '[';
            selected.each(function() {
              recordInstances += '{"record":"'+$(this).find(':nth-child(2)').text()+'",';
              recordInstances += '"instance":'+$(this).find(':first-child input[type="checkbox"]').attr('id')+'},';
            });
            recordInstances = recordInstances.slice(0, -1) + ']';

            $.ajax({
              type: "POST",
              url: url,
              data: {"updateType":"printMoved","recordsToSave":recordInstances},
              dataType: 'json',
              success: function (data) {
                $('#moved_table tbody input[type="checkbox"]:checked').trigger('click');
                if (data['success']) {
                  alert('Print request complete.');
                } else {
                  alert('Print Error:' + data['errors']);
                }
              },
              error: function (request, error) {
                $('#moved_table tbody input[type="checkbox"]:checked').trigger('click');

                alert('Print Error:' + error);
              }
            });
          }

          $(document).ready(function () {
            document.getElementById('messages').style.color = 'black';
            document.getElementById('messages').innerHTML =
              "Please be patient, this report may take several minutes to  load";

            var url = '<?php echo $url; ?>';
            var table_html = '<table id="moved_table" class="table table-bordered table-striped"><thead>'
              +'<tr><th><input type="checkbox" name="select_all"></th><th>Record</th><th>Sample Deposit Date</th><th>Line Id</th><th>Vial Id</th>' +
              '<th>Prev Freezer Box</th><th>Prev Freezer Slot</th><th>Freezer Box</th><th>Freezer ' +
              'Slot</th><th>Move Date</th></tr></thead><tbody></tbody></table>' +
              '<div>'+
              '<button type="button" id="printButton" class="btn btn-sm btn-success" ' +
              'onclick="print();"></span>&nbsp;'
              +'Print</button></div>';
            document.getElementById('tableDiv').innerHTML = table_html;
            var thisTable = $('#moved_table').DataTable({
              "ajax": {
                "url": url,
                "dataType": "json",
                "type": "POST",
                "data": {"updateType":"moveReport"},
                "error":  function (request, error) {
                  console.log('Request ' + request);
                  console.log('Error ' + error);
                }
              },
              "columnDefs":[
                {
                  "targets":0,
                  "searchable":false,
                  "orderable":false,
                  "className":"dt-body-center",
                  "render": function (data, type, row, meta) {
                    return '<input type="checkbox" id="'+data+'"></input>';
                  }
                }
              ],
              "columns": [
                {"data": "instance"},
                { "data": "record" },
                { "data": "deposit_date" },
                { "data": "line" },
                { "data": "vial_id" },
                { "data": "prev_box" },
                { "data": "prev_slot" },
                { "data": "box" },
                { "data": "slot" },
                { "data": "move_date" }
              ]
            });

            // Handle click on checkbox
              $('#moved_table tbody').on('click', 'input[type="checkbox"]', function (e) {
              var $row = $(this).closest('tr');

              if (this.checked) {
                $row.addClass('selected');
              } else {
                $row.removeClass('selected');
              }

              // Update state of "Select all" control
              updateDataTableSelectAllCtrl(thisTable);

              // Prevent click event from propagating to parent
              e.stopPropagation();
            });

            // Handle click on table cells with checkboxes
            $('#moved_table').on('click', 'tbody td, thead th:first-child', function (e) {
              $(this).parent().find('input[type="checkbox"]').trigger('click');
            });

            // Handle click on "Select all" control
            $('thead input[name="select_all"]', thisTable.table().container()).on('click', function (e) {
              if (this.checked) {
                $('#moved_table tbody input[type="checkbox"]:not(:checked)').trigger('click');
              } else {
                $('#moved_table tbody input[type="checkbox"]:checked').trigger('click');
              }

              // Prevent click event from propagating to parent
              e.stopPropagation();
            });

            // Handle table draw event
            thisTable.on('draw', function () {
              // Update state of "Select all" control
              updateDataTableSelectAllCtrl(thisTable);
            });
          });


        </script>

    </head>
<body>
<h3>
    Moved Report
</h3>
<div id="messages">
</div>
<div id="tableDiv">
</div>
</body>




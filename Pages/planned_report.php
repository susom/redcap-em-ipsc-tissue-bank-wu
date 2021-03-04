<?php

namespace Stanford\iPSCTissueBankWu;

/** @var \Stanford\iPSCTissueBankWu\iPSCTissueBankWu $module */
error_reporting( E_ALL ^ ( E_NOTICE | E_WARNING | E_DEPRECATED ) );
$url = $module->getUrl("update_vials.php");
$module->emDebug('empty slot report url ' . $url);
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
            var $chkbox_all = $('tbody input[type="checkbox"]', $table);
            var $chkbox_checked = $('tbody input[type="checkbox"]:checked', $table);
            var chkbox_select_all = $('thead input[name="select_all"]', $table).get(0);

            // If none of the checkboxes are checked
            if ($chkbox_checked.length === 0) {
              chkbox_select_all.checked = false;
              // If all of the checkboxes are checked
            } else if ($chkbox_checked.length === $chkbox_all.length) {
              chkbox_select_all.checked = true;
              // If some of the checkboxes are checked
            } else {
              chkbox_select_all.checked = true;
            }
          }

          function shipInstances() {
            var url = '<?php echo $url; ?>';
            let selected = $('#planned_table .selected').clone();
            let recordInstances = '[';
            selected.each(function() {
              recordInstances += '{"record":"'+$(this).find(':nth-child(2)').text()+'",';
              recordInstances += '"instance":'+$(this).find(':first-child input[type="checkbox"]').attr('id')+'}';
            });
            recordInstances +=']';

            $.ajax({
              type: "POST",
              url: url,
              data: {"updateType":"saveShipped","recordsToSave":recordInstances},
              dataType: 'json',
              success: function (data) {
                var thisTable = $('#planned_table').DataTable();
                thisTable.clear().rows.add(data.data).draw();
              },
              error: function (request, error) {
                console.log('Request ' + request);
                console.log('Error ' + error);
              }
            });
          }

          function cancelPlanned() {
            var url = '<?php echo $url; ?>';
            let selected = $('#planned_table .selected').clone();
            let recordInstances = '[';
            selected.each(function() {
              recordInstances += '{"record":"'+$(this).find(':nth-child(2)').text()+'",';
              recordInstances += '"instance":'+$(this).find(':first-child input[type="checkbox"]').attr('id')+'}';
            });
            recordInstances +=']';

            $.ajax({
              type: "POST",
              url: url,
              data: {"updateType":"cancelPlannedReport","recordsToSave":recordInstances},
              dataType: 'json',
              success: function (data) {
                var thisTable = $('#planned_table').DataTable();
                thisTable.clear().rows.add(data.data).draw();
              },
              error: function (request, error) {
                console.log('Request ' + request);
                console.log('Error ' + error);
              }
            });
          }


          $(document).ready(function () {
            document.getElementById('messages').style.color = 'black';
            document.getElementById('messages').innerHTML =
              "Please be patient, this report may take several minutes to  load";

            var url = '<?php echo $url; ?>';
            var table_html = '<table id="planned_table" class="table table-bordered table-striped"><thead>'
              +'<tr><th><input type="checkbox" name="select_all"></th><th>Record</th><th>Sample ' +
              'Date</th><th>Line</th><th>Type</th><th>Passage</th> ' +
              '<th>Vial Id</th><th>Freezer Box</th><th>Freezer Slot</th><th>Dist By</th><th>Dist To ' +
              '</th><th>Dist Date</th></tr></thead><tbody></tbody></table>'+
              '<div><button type="button" id="shipSelected" class="btn btn-sm btn-success mr-2" ' +
              'onclick="shipInstances();"></span>&nbsp;'
                +'Shipped</button>'+
            '<button type="button" id="shipSelected" class="btn btn-sm btn-danger" ' +
            'onclick="cancelPlanned();"></span>&nbsp;'
            +'Cancel Planned</button></div>';
            document.getElementById('tableDiv').innerHTML = table_html;
            var thisTable = $('#planned_table').DataTable({
              "ajax": {
                "url": url,
                "dataType": "json",
                "type": "POST",
                "data": {"updateType":"plannedReport"},
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
                { "data": "sample_date" },
                { "data": "line" },
                { "data": "type" },
                { "data": "passage" },
                { "data": "vial_id" },
                { "data": "freezer_box" },
                { "data": "freezer_slot" },
                { "data": "dist_by" },
                { "data": "dist_to" },
                { "data": "dist_date" }
              ]
            });

            // Handle click on checkbox
            $('#planned_table tbody').on('click', 'input[type="checkbox"]', function (e) {
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
            $('#planned_table').on('click', 'tbody td, thead th:first-child', function (e) {
              $(this).parent().find('input[type="checkbox"]').trigger('click');
            });

            // Handle click on "Select all" control
            $('thead input[name="select_all"]', thisTable.table().container()).on('click', function (e) {
              if (this.checked) {
                $('#planned_table tbody input[type="checkbox"]:not(:checked)').trigger('click');
              } else {
                $('#planned_table tbody input[type="checkbox"]:checked').trigger('click');
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
    Planned Report
</h3>
<div id="messages">
</div>
<div id="tableDiv">
</div>
</body>




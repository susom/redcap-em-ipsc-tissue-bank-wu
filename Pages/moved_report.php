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

          $(document).ready(function () {
            document.getElementById('messages').style.color = 'black';
            document.getElementById('messages').innerHTML =
              "Please be patient, this report may take several minutes to  load";

            var url = '<?php echo $url; ?>';
            var table_html = '<table id="planned_table" class="table table-bordered table-striped"><thead>'
              +'<tr><th>Record</th><th>Sample Deposit Date</th><th>Line Id</th><th>Vial Id</th>' +
              '<th>Prev Freezer Box</th><th>Prev Freezer Slot</th><th>Freezer Box</th><th>Freezer ' +
              'Slot</th><th>Move Date</th></tr></thead><tbody></tbody></table>';
            document.getElementById('tableDiv').innerHTML = table_html;
            var thisTable = $('#planned_table').DataTable({
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
              "columns": [
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




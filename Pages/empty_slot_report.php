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

        <style>
            body {
                width: 90%;
                height: 100px;
                padding: 5px;

            }
        </style>
      <script type="text/javascript">

        $(document).ready(function () {
          document.getElementById('submit').addEventListener('click',
            function() {
              let selectVal = document.getElementById('tankId').value;
              let boxVal = document.getElementById('box').value;
              if (selectVal) {
                document.getElementById('messages').style.color = 'black';
                document.getElementById('messages').innerHTML =
                  'Please be patient, this report may take several minutes to  load   <i class="fas fa-spinner ' +
                  'fa-spin"></i> <br>';
                var url = '<?php echo $url; ?>';
                $.ajax({
                  type: "POST",
                  url: url,
                  timeout: 0,
                  data: {"updateType": "emptySlotReport", "freezer": selectVal, "box": boxVal},
                  dataType: 'json',
                  success: function (data) {
                    if (data.tableValues.length > 0) {
                      try {
                        var table_html = '<table class="table table-bordered table-striped"><thead>'
                          + '<tr><th>Freezer Box</th><th>Available Slots</th><th>Slots</th></tr></thead><tbody>';
                        for (var i = 0; i < data.tableValues.length; i++) {
                          table_html += "<tr><td>" + data.tableValues[i].box + "</td>";
                          table_html += "<td>" + data.tableValues[i].num_slots + "</td>";
                          table_html += "<td>" + data.tableValues[i].empty_slots + "</td></tr>";
                        }
                        table_html += "</tbody></table>";
                        document.getElementById('messages').innerHTML = table_html;

                      } catch (error) {
                        document.getElementById('messages').style.color = 'red';
                        document.getElementById('messages').innerHTML = data;
                      }
                    } else {
                      document.getElementById('messages').style.color = 'black';
                      document.getElementById('messages').innerHTML =
                        'The selected box does not exist.';
                    }
                  },
                  error: function (request, error) {
                    console.log('Request ' + request);
                    console.log('Error ' + error);
                  }
                });
              } else {
                document.getElementById('messages').style.color = 'red';
                document.getElementById('messages').innerHTML =
                  '<h5>Select a freezer value.</h5>';
              }
            }
          );
        });

      </script>

    </head>
    <body>
    <h3>
        Empty Freezer Slots Report
    </h3>

<form>
  <div class="form-row">
  <label class="col-form-label" for="tankId">Freezer:&nbsp;</label> <select class="form-control col-3" id="tankId">
      <option hidden disabled selected value> -- select an option -- </option>
      <option>A</option>
      <option>B</option>
      <option>D</option>
  </select>
    <label class="col-form-label ml-3" for="box">Box (Optional):&nbsp;</label>
    <input type="text" id="box">
  </div>  <div class="form-row">
  <button type="button" id="submit" class="btn btn-primary mt-3">Submit</button>
  </div>

</form>
    <div id="messages" class="mt-3">
    </div>
</body>



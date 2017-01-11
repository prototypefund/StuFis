<?php

loadForms();

function loadForms() {
  global $formulare;

  $handle = opendir(SYSBASE."/config/formulare");

  while (false !== ($entry = readdir($handle))) {
    if (substr($entry, -4) !== ".php") continue;
    require SYSBASE."/config/formulare/".$entry;
  }

  closedir($handle);

}

function renderForm($meta) {

  foreach ($meta as $item) {
    renderFormItem($item);
  }

}

function renderFormItem($meta,$ctrl = false) {

  if (!isset($meta["id"])) {
    echo "Missing \"id\" in ";
    print_r($meta);
    die();
  }

  if (!isset($meta["opts"]))
   $meta["opts"] = [];

  if ($ctrl === false) $ctrl = [];
  if (!isset($ctrl["wrapper"])) {
    $wrapper = "div";
  } else {
    $wrapper = $ctrl["wrapper"];
    unset($ctrl["wrapper"]);
  }

  if (isset($ctrl["class"]))
    $classes = $ctrl["class"];
  else
    $classes = [];

  if (isset($meta["width"]))
    $classes[] = "col-xs-{$meta["width"]}";

  echo "<$wrapper class=\"".implode(" ", $classes)."\">";

  $ctrl["id"] = $meta["id"];
  $ctrl["name"] = $meta["id"];
  if (isset($ctrl["suffix"])) {
    $ctrl["name"] = $meta["id"]."[]";
  }
  if (isset($ctrl["suffix"]) && $ctrl["suffix"]) {
    $ctrl["id"] = $meta["id"]."-".$ctrl["suffix"];
  }

  echo "<div class=\"form-group\">";

  if (isset($meta["title"]) && isset($meta["id"]))
    echo "<label class=\"control-label\" for=\"{$ctrl["id"]}\">".htmlspecialchars($meta["title"])."</label>";
  elseif (isset($meta["title"]))
    echo "<label class=\"control-label\">".htmlspecialchars($meta["title"])."</label>";

  switch ($meta["type"]) {
    case "group":
      renderFormItemGroup($meta,$ctrl);
      break;
    case "text":
    case "email":
    case "url":
      renderFormItemText($meta,$ctrl);
      break;
    case "money":
      renderFormItemMoney($meta,$ctrl);
      break;
    case "textarea":
      renderFormItemTextarea($meta,$ctrl);
      break;
    case "select":
      renderFormItemSelect($meta,$ctrl);
      break;
    case "date":
      renderFormItemDate($meta,$ctrl);
      break;
    case "table":
      renderFormItemTable($meta,$ctrl);
      break;
    default:
      echo "<pre>"; print_r($meta); echo "</pre>";
      die("Unkown form element meta type: ".$meta["type"]);
  }

  echo "</div>";

  if (isset($meta["width"]))
    echo "</$wrapper>";
  else
    echo "</$wrapper>";

}

function renderFormItemGroup($meta, $ctrl) {
  if (in_array("well", $meta["opts"]))
     echo "<div class=\"well\">";

  foreach ($meta["children"] as $child) {
    renderFormItem($child, $ctrl);
  }
  if (in_array("well", $meta["opts"]))
    echo "<div class=\"clearfix\"></div></div>";
}

function renderFormItemText($meta, $ctrl) {

  echo "<input class=\"form-control\" type=\"{$meta["type"]}\" name=\"".htmlspecialchars($ctrl["name"])."\" id=\"".htmlspecialchars($ctrl["id"])."\"";
  if (isset($meta["placeholder"]))
    echo " placeholder=\"".htmlspecialchars($meta["placeholder"])."\"";
  if (isset($meta["prefill"])) {
    $value = "";
    if ($meta["prefill"] == "user:mail")
      $value = getUserMail();

    echo " value=\"".htmlspecialchars($value)."\"";
  }
  echo "/>";
}

function renderFormItemMoney($meta, $ctrl) {
  echo "<div class=\"input-group\">";
  echo "<input type=\"text\" class=\"form-control\" value=\"0.00\" name=\"".htmlspecialchars($ctrl["name"])."\" id=\"".htmlspecialchars($ctrl["id"])."\">";
  echo "<span class=\"input-group-addon\">€</span>";
  echo "</div>";
}

function renderFormItemTextarea($meta, $ctrl) {
  echo "<textarea class=\"form-control\" name=\"".htmlspecialchars($ctrl["name"])."\" id=\"".htmlspecialchars($ctrl["id"])."\"";
  if (isset($meta["min-rows"]))
    echo " rows=".htmlspecialchars($meta["min-rows"]);
  echo ">";
  echo "</textarea>";
}

function renderFormItemSelect($meta, $ctrl) {
  global $attributes, $GremiumPrefix;
  echo "<select class=\"selectpicker form-control\" data-live-search=\"true\" name=\"".htmlspecialchars($ctrl["name"])."\" id=\"".htmlspecialchars($ctrl["id"])."\"";
  if (isset($meta["placeholder"]))
    echo " title=\"".htmlspecialchars($meta["placeholder"])."\"";
  if (isset($meta["multiple"]))
    echo " multiple";
  echo ">";

  if ($meta["data-source"] == "own-orgs") {
    sort($attributes["gremien"]);
    foreach ($attributes["gremien"] as $gremium) {
      $found = (count($GremiumPrefix) == 0);
      foreach ($GremiumPrefix as $prefix)
        $found |= (substr($gremium, 0, strlen($prefix)) == $prefix);
      if (!$found) continue;

      echo "<option>".htmlspecialchars($gremium)."</option>";
    }
  }

  echo "</select>";
}

function renderFormItemDate($meta, $ctrl) {
  echo "<input type=\"text\" class=\"form-control datepicker\" name=\"".htmlspecialchars($ctrl["name"])."\" id=\"".htmlspecialchars($ctrl["id"])."\" data-date-format=\"yyyy-mm-dd\">";
/*
     [ "id" => "start",       "name" => "Projektbeginn",                      "type" => "date",   "width" => 6,  "opts" => ["not-before-creation"], "not-after" => "field:ende" ],
     [ "id" => "ende",        "name" => "Projektende",                        "type" => "date",   "width" => 6,  "opts" => ["not-before-creation"], "not-before" => "field:start" ],
*/
}

function renderFormItemTable($meta, $ctrl) {
  $withRowNumber = in_array("with-row-number", $meta["opts"]);

?>
  <table class="table table-striped dynamic-table" id="<?php echo htmlspecialchars($ctrl["id"]); ?>">
<?php

  if (in_array("with-headline", $meta["opts"])) {

?>
    <thead>
      <tr>
<?php
        echo "<th></th>";
        if ($withRowNumber) {
          echo "<th></th>";
        }
        foreach ($meta["columns"] as $col) {
          echo "<th>".htmlspecialchars($col["name"])."</th>";
        }
?>
      </tr>
    </thead>

<?php
  }

?>
    <tbody>
       <tr class="new-table-row">
<?php
        if ($withRowNumber) {
          echo "<td>";
          echo "<span class=\"row-number\">1.</span>";
          echo "</td>";
        }
        echo "<td>";
        echo "<a href=\"\" class=\"delete-row\"><i class=\"fa fa-fw fa-trash\"></i> DEL</a>";
        echo "</td>";

        foreach ($meta["columns"] as $i => $col) {
          renderFormItem($col,array_merge($ctrl, ["wrapper"=> "td", "suffix" => false, "class" => [ "{$ctrl["id"]}-col-$i" ] ]));
        }
?>
       </tr>
    </tbody>
<?php
?>
    <tfoot>
      <tr>
        <th colspan="2">
        </th>
<?php
        foreach ($meta["columns"] as $i => $col) {
          if (!isset($col["opts"])) $col["opts"] = [];
?>
        <th>
<?php
          if (in_array("sum-over-table-bottom", $col["opts"])) {
            echo "<div class=\"column-sum\" style=\"text-align:right;\" data-col-id=\"{$ctrl["id"]}-col-$i\">You should not see this o.O</span>";
          }
?>
        </th>
<?php
        }
?>
      </tr>
    </tfoot>
  </table>
<?

}

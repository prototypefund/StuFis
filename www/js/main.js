
$(document).ready(function() {
  $('.selectpicker').selectpicker({
      style: 'btn-default',
      size: false
  });

  $(".single-file-container").on("clonedsource.single-file", function(evt) {
    if ($(this).parents(".new-table-row").length == 0) {
      $(this).off("clonedsource.single-file");
      $(this).find(".single-file-toggle").remove();
      $(this).find(".single-file").show();
      $(this).find(".single-file").fileinput({'showUpload':false, 'showPreview':false, 'language': 'de', 'theme': 'gly'});
    } else if ($(this).find(".single-file-toggle").length == 0) {
      $(this).find(".single-file-toggle").remove();
      $(this).find(".single-file").hide();
      $('<input/>').attr('type','text').attr('placeholder','Klicken für Dateiupload').addClass('form-control').addClass('single-file-toggle').appendTo($(this));
    }
  });
  $(".single-file-container").each(function(i,e) { $(e).triggerHandler("clonedsource"); });
  $(".dynamic-table .single-file").on("name-changed.single-file", function(evt) {
    var d = $(this).data('fileinput');
    if (!d) return;
    d.uploadFileAttr = $(this).attr("name");
  });

  $(".dynamic-table *[name]").each(function(i,e) {
    var $e = $(e);
    var name = $e.attr('name');
    if (name.substr(-2) == '[]') {
      name = name.substr(0, name.length - 2);
    }
    $e.attr('orig-name', name);
  });
  $(".dynamic-table *[name][name^=formdata]").on("name-suffix-changed.dynamic-table", function(evt) {
    var $e = $(this);
    var name = $e.attr('orig-name');
    var suffix = "";
    $e.parents("*[name-suffix]").each(function (i,p) {
      suffix = $(p).attr('name-suffix') + suffix;
    });

    $e.attr('name',name + suffix);
    $e.triggerHandler("name-changed");
  });
  $(".dynamic-table > tbody > tr").on("row-number-changed.dynamic-table", function (evt) {
    var $tr = $(this);
    var rowNumber = $tr.attr('dynamic-table-row-number');
    $tr.attr('name-suffix','['+rowNumber+']');

    $tr.find("*[name]").each(function(i, e) {
       $(e).triggerHandler("name-suffix-changed");
    });

    $tr.children("td.row-number").text(rowNumber+".");
  });

  $("*[id]").each(function(i,e) {
    var $e = $(e);
    var id = $e.attr('id');
    $e.attr('orig-id', id);
  });
  $(".dynamic-table *[id]").on("id-suffix-changed.dynamic-table", function(evt) {
    var $e = $(this);
    var id = $e.attr('orig-id');
    var suffix = "";
    $e.parents("*[id-suffix]").each(function (i,p) {
      suffix = $(p).attr('id-suffix') + suffix;
    });

    $e.attr('id',id + suffix);
  });

  $('.dynamic-table').each(function (i, table) {
    var $table = $(table);
    var $tbody = $table.children("tbody");
    var $tfoot = $table.children("tfoot");
    var tableId = $table.attr('orig-id');
    $table.attr('dynamic-table-id-ctr', 0);

    $tfoot.find('.column-sum').each(function (i, e) {
      var $e = $(e);
      var colId = $e.data('col-id');
      $e.addClass(colId);
      $tbody.children('tr').children('.'+colId).find('input').each(function() {
        $(this).on('change.column-sum', null, colId, function (evt) {
          var val = $(this).val();
          val = parseFloat(val);
          if (isNaN(val)) {
            val = 0;
          }
          $(this).val(val.toFixed(2));
          updateColumnSum(evt.data, $(this).parents(".dynamic-table").first());
        });
        $(this).trigger('change');
      });
      updateColumnSum(colId, $table);
    });

    var $tr = $tbody.children('tr.new-table-row').last();

    $tr.attr('dynamic-table-id', tableId);

    $tr.attr('dynamic-table-row-number', 0);
    $tr.triggerHandler("row-number-changed");

    $tr.attr('id-suffix', '-0');
    $tr.find("*[id]").each(function(i,e) {
      $(this).triggerHandler("id-suffix-changed");
    });
    $tr.find("*[id]").each(function(i,e) {
      if ("defaultValue" in e) {
        var $e = $(e);
        $e.val(e.defaultValue);
        $e.trigger("change");
      }
    });
    $tr.find("*").off('focus.dynamic-table'+tableId);
    $tr.find("*").on('focus.dynamic-table'+tableId, function (evt) {
      var $tr = $(this).parents("tr[dynamic-table-id="+tableId+"]");
      var $table = $(this).parents("table[orig-id="+tableId+"]");
      if ($tr.length != 1 || $table.length != 1) {
        console.log(tableId);
        console.log(this);
        console.log($tr);
        console.log($table);
        alert('error dynamic row handling');
      }
      onClickNewRow($tr, $table, tableId);
    });
    $tr.children("td.delete-row").find('a.delete-row')
      .on('click', function(evt) {
        evt.stopPropagation();
        var $tr = $(this).parents("tr").first();
        var $tbody = $tr.parents("tbody").first();
        $tr.remove();
        $tbody.children("tr").each(function(rowNumber,tr) {
          var $tr = $(tr);
          $tr.attr('dynamic-table-row-number', rowNumber);
          $tr.triggerHandler("row-number-changed");
        });
        $tfoot.find('.column-sum').each(function (i, e) {
          var $e = $(e);
          var colId = $e.data('col-id');
          updateColumnSum(colId, $table);
        });
        return false;
      });
    /*  */
  }); /* each table */

  $( "form.ajax" ).submit(function (ev) {
    return handleSubmitForm($(this));
  });

});

function onClickNewRow($tr, $table, tableId) {

  if (!$tr.is(".new-table-row")) return;

  var $ntr = $tr.clone(true);
  var $tbody = $table.children("tbody");
  var rowNumber = $tbody.children("tr").length;

  $tr.removeClass("new-table-row");

  var ctr = $table.attr('dynamic-table-id-ctr');
  ctr++;
  $table.attr('dynamic-table-id-ctr', ctr);

  $ntr.appendTo($tbody); /* insert first so suffix can be found */
  $ntr.attr('id-suffix', '-' + ctr);
  $ntr.find("*[id]").each(function(i,e) {
    $(this).triggerHandler("id-suffix-changed");
  });

  $ntr.attr('dynamic-table-row-number', rowNumber);
  $ntr.triggerHandler("row-number-changed");

  $ntr.find("*").each(function (i, e) { $(e).triggerHandler("cloned"); });
  $tr.find("*").each(function (i, e) { $(e).triggerHandler("clonedsource"); });
}

function updateColumnSum(colId, $table) {
  var $e = $table.children("tfoot").find('.column-sum.'+colId);
  var sum = 0;
  $table.find('.'+colId+' input').each(function() {
    sum += parseFloat($(this).val());
  });
  $e.text(sum.toFixed(2));
}

//moment.locale('de');

function xpAjaxErrorHandler (jqXHR, textStatus, errorThrown) {
      $("#please-wait-dlg").modal("hide");

      $("#server-message-label").text("Es ist ein Server-Fehler aufgetreten");
      var $smc = $("#server-message-content");
      $smc.empty();
      $("#server-message-content").empty();
      var $smcp = $('<pre>').appendTo( $smc ).text(textStatus + "\n" + errorThrown + "\n" + jqXHR.responseText);
      $("#server-message-dlg").modal("show");
};

function doSubmitForm(formid) {
  handleSubmitForm($("#"+formid));
  return false;
}

function handleSubmitForm($form) {
  var action = $form.attr("action");
  if ($form.find("input[name=action]").length + $form.find("select[name=action]").length == 0) { return true; }
  var data = new FormData($form[0]);
  data.append("ajax", 1);
  $("#please-wait-dlg").modal("show");
  jQuery.ajax({
    url: action,
    data: data,
    cache: false,
    contentType: false,
    processData: false,
    type: "POST"
  })
  .done(function (values, status, req) {
     $("#please-wait-dlg").modal("hide");
     if (typeof(values) == "string") {
       $("#server-message-label").text("Es ist ein Server-Fehler aufgetreten");
       var $smc = $("#server-message-content");
       $smc.empty();
       $("#server-message-content").empty();
       var $smcp = $('<pre>').appendTo( $smc ).text(values);
       $("#server-message-dlg").modal("show");
       return;
     }
     var txt;
     var txtHeadline;
     if (values.ret) {
       txt = "";
       txtHeadline = "Die Daten wurden erfolgreich gespeichert.";
     } else {
       txt = "Die Daten konnten nicht gespeichert werden.";
       txtHeadline = "Die Daten konnten nicht gespeichert werden.";
     }
     if (values.msgs && values.msgs.length > 0) {
         txt = values.msgs.join("\n")+"\n"+txt;
     }
     if (values.ret && txt != "") {
       if (self.opener) {
         self.opener.location.reload();
       }
       $("#server-question-label").text(txtHeadline);
       var $smc = $("#server-question-content");
       $smc.empty();
       $("#server-question-content").empty();
       var $smcu = $('<ul/>').appendTo( $smc );
       for (i = 0; i < values.msgs.length; i++) {
         var msg = (values.msgs[i]);
         $('<li/>').text(msg).appendTo( $smcu );
       }
       $("#server-question-close-window").on("click", function(evt) {
         if (!values.target) {
           if (self.opener) {
             self.opener.focus();
           }
           self.close();
         } else {
           self.location.href = values.target;
         }
       });
       $("#server-question-dlg").on('hidden.bs.modal', function (e) {
         if (values.target) {
           window.open(values.target);
         }
       });
       $("#server-question-dlg").modal("show");

     } else if (values.ret) { // txt is empty
       if (!values.target) {
         if (self.opener) {
           self.opener.focus();
         }
         self.close();
       } else { // values.target
         self.location.href = values.target;
       }
     } else { // !values.ret
      $("#server-message-label").text(txtHeadline);
      var $smc = $("#server-message-content");
      $smc.empty();
      $("#server-message-content").empty();
      var $smcu = $('<ul/>').appendTo( $smc );
      for (i = 0; i < values.msgs.length; i++) {
          var msg = (values.msgs[i]);
          $('<li/>').text(msg).appendTo( $smcu );
      }
      $("#server-message-dlg").modal("show");
     }
   })
  .fail(xpAjaxErrorHandler);
  return false;
}


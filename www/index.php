<?php

global $attributes, $logoutUrl, $ADMINGROUP, $nonce, $URIBASE, $antrag, $STORAGE, $HIBISCUSGROUP;
ob_start('ob_gzhandler');

require_once "../lib/inc.all.php";
requireGroup($AUTHGROUP);
prof_flag("Start");


function writeFillOnCopy($antrag_id, $form) {
    $fillOnCopy = [];
    if (isset($form["config"]["fillOnCopy"]))
        $fillOnCopy = $form["config"]["fillOnCopy"];
    foreach ($fillOnCopy as $rec) {
        $row = Array();
        $row["antrag_id"] = $antrag_id;
        $row["contenttype"] = $rec["type"];
        $row["fieldname"] = $rec["name"];
        $value = "";
        switch ($rec["prefill"]) {
            case "user:mail":
                $value = getUserMail();
                break;
            case "user:fullname":
                $value = getUserFullName();
                break;
            case "otherForm":
                $fieldValue = false;
                $fieldName = false;
                if ($rec["otherForm"][0] == "referenceField" && isset($form["config"]["referenceField"])) {
                    $fieldName = $form["config"]["referenceField"]["name"];
                } elseif (substr($rec["otherForm"][0],0,6) == "field:") {
                    $fieldName = substr($rec["otherForm"][0],6);
                } else {
                    die("Unknown otherForm reference in fillOnCopy: {$rec["otherForm"][0]}");
                }
                if ($fieldValue === false && $fieldName !== false) {
                    $f = dbGet("inhalt", [ "antrag_id" => $antrag_id, "fieldname" => $fieldName, "type" => "otherForm" ] );
                    if ($f !== false)
                        $fieldValue = $f["value"];
                }
                if ($fieldValue === false || $fieldValue == "") {
                    // no other form provided
                    break;
                }
                $otherAntrag = dbGet("antrag", ["id" => (int) $fieldValue]);
                if ($otherAntrag === false) {
                    // invalid value
                    break;
                }

                $otherInhalt = dbFetchAll("inhalt", ["antrag_id" => $otherAntrag["id"]]);
                $otherAntrag["_inhalt"] = $otherInhalt;

                $otherForm = getForm($otherAntrag["type"], $otherAntrag["revision"]);
                $readPermitted = hasPermission($otherForm, $otherAntrag, "canRead");

                if (!$readPermitted) {
                    break;
                }

                $f = dbGet("inhalt", [ "antrag_id" => $otherAntrag["id"], "fieldname" => $rec["otherForm"][1], "type" => "otherForm" ] );
                if ($f === false)
                    // other field not in other form
                    break;

                $value = $f["value"];

                if (isset($rec["otherForm"]["pattern"])) {
                    $m = [];
                    if (!preg_match("/{$rec["otherForm"]["pattern"]}/", $value, $m)) {
                        $value = "";
                    } else {
                        $value = $m[0];
                    }
                }

                break;
            default:
                if (substr($rec["prefill"],0,6) == "value:") {
                    $value = substr($rec["prefill"],6);
                } else {
                    $msgs[] = "FillOnCopy fehlgeschlagen: prefill={$rec["prefill"]} nicht implementiert.";
                    return false;
                    break 2; # abort foreach fillOnCopy
                }
        }
        $row["value"] = $value;
        dbDelete("inhalt", ["antrag_id" => $row["antrag_id"], "fieldname" => $row["fieldname"] ]);
        $ret0 = dbInsert("inhalt", $row);
        if ($ret0 === false)
            return false;
    }
    return true;
}


function writeFormdata($antrag_id, $isPartiell, $form, $antrag) {
    if (!isset($_REQUEST["formdata"]))
        $_REQUEST["formdata"] = [];

    $ret = true;
    foreach($_REQUEST["formdata"] as $fieldname => $value) {
        $fieldtype = $_REQUEST["formtype"][$fieldname];
        if ($fieldtype == "file" || $fieldtype == "multifile") continue;
        if ($isPartiell) {
            $perm = "canEditPartiell.field.{$fieldname}";
            if (!hasPermission($form, $antrag, $perm)) continue;
        }
        $inhalt = [];
        $inhalt["antrag_id"] = $antrag_id;
        $inhalt["contenttype"] = $_REQUEST["formtype"][$fieldname];
        $inhalt["fieldname"] = $fieldname;
        $inhalt["value"] = $value;
        $ret1 = storeInhalt($inhalt, $isPartiell);
        $ret = $ret && $ret1;
    } /* formdata */

    return $ret;
}

function writeFormdataFiles($antrag_id, &$msgs, &$filesRemoved, &$filesCreated, $isPartiell, $form, $antrag) {
    if (!isset($_FILES["formdata"]))
        return true;

    function storeAnhang($anhang, $names, $types, $tmp_names, $errors, $sizes, &$msgs, &$filesRemoved, &$filesCreated) {
        global $STORAGE;
        $ret = true;

        if (is_array($names)) {
            $fieldname = $anhang["fieldname"];
            foreach (array_keys($names) as $key) {
                $anhang["fieldname"] = "${fieldname}[${key}]";
                $ret1 = storeAnhang($anhang, $names[$key], $types[$key], $tmp_names[$key], $errors[$key], $sizes[$key], $msgs, $filesRemoved, $filesCreated);
                $ret = $ret && $ret1;
            }
            return $ret;
        }
        if ($errors == UPLOAD_ERR_NO_FILE || $errors == UPLOAD_ERR_OK) {
            // try to delete file
            // do not try to replace file, as it might be hard-linked to another antrag
            $oldAnhang = dbGet("anhang", [ "antrag_id" => $anhang["antrag_id"], "fieldname" => $names ]);
            if ($oldAnhang !== false) {
                $ret = dbDelete("anhang", [ "antrag_id" => $oldAnhang["antrag_id"], "id" => $oldAnhang["id"] ]);
                $ret = ($ret === 1);
                $filesRemoved[] = $STORAGE."/".$oldAnhang["antrag_id"]."/".$oldAnhang["path"];
            }
        } else {
            $msgs[] = uploadCodeToMessage($errors);
            $ret = false;
        }
        if ($errors != UPLOAD_ERR_OK) {
            return $ret;
        }
        $anhang["size"] = $sizes;
        $anhang["mimetype"] = $types;
        $anhang["md5sum"] = md5_file($tmp_names);
        $anhang["state"] = "active";
        $anhang["filename"] = $names;

        $dbPath = uniqid().".".pathinfo($names, PATHINFO_EXTENSION);
        $path = $STORAGE."/".$anhang["antrag_id"]."/".$dbPath;
        if (!is_dir(dirname($path)))
            mkdir(dirname($path),0777,true);
        $anhang["path"] = $dbPath;

        $ret = move_uploaded_file($tmp_names, $path);
        $filesCreated[] = $path;

        $ret = $ret && dbInsert("anhang", $anhang);
        if (!$ret) {
            $msgs[]="failed $names";
        }

        return $ret;
    }

    $anhang = [];
    $anhang["antrag_id"] = $antrag_id;
    $fd = $_FILES["formdata"];
    $ret = true;
    foreach (array_keys($fd["name"]) as $key) {
        if ($isPartiell) {
            $perm = "canEditPartiell.field.{$key}";
            if (!hasPermission($form, $antrag, $perm)) continue;
        }
        $anhang["fieldname"] = $key;
        $fieldtype = $_REQUEST["formtype"][$key];
        if ($fieldtype != "file" && $fieldtype != "multifile") {
            $msgs[] = "Invalid field type: \"$fieldtype\" for \"$key\"";
            $ret = false;
            continue;
        }
        $ret1 = storeAnhang($anhang, $fd["name"][$key], $fd["type"][$key], $fd["tmp_name"][$key], $fd["error"][$key], $fd["size"][$key], $msgs, $filesRemoved, $filesCreated);
        $ret = $ret && $ret1;
    }

    return $ret;
}

function doNewStateActions(&$form, $transition, &$antrag, $newState, &$msgs, &$filesCreated, &$filesRemoved, &$target) {
    if (!isset($form["_class"]["postNewStateActions"]))
        return true;
    if (!isset($form["_class"]["postNewStateActions"][$transition]))
        return true;

    $actions = $form["_class"]["postNewStateActions"][$transition];
    foreach ($actions as $action) {
        if (isset($action["sendMail"]) && $action["sendMail"]) {
            notifyStateTransition($antrag, $newState, getUsername(), $action);
        }
        if (isset($action["copy"]) && $action["copy"]) {
            $newTarget = "";
            $ret = copyAntrag($antrag["id"], $antrag["version"], false, $action["type"], $action["revision"], $msgs, $filesCreated, $filesRemoved, $newTarget);
            if ($ret === false)
                return false;
            if (isset($action["redirect"]) && $action["redirect"]) {
                $target = $newTarget;
            }
        }
    }
    return true;
}


function copyAntrag($oldAntragId, $oldAntragVersion, $oldAntragNewState, $newType, $newRevision, &$msgs, &$filesCreated, &$filesRemoved, &$target) {
    global $URIBASE,$STORAGE;

    $form = getForm($newType, $newRevision);
    if ($form === false) {
        $msgs[] = "Unbekannte Formularversion: $newType#$newRevision";
        return false;
    }
    if (!hasPermission($form, null, "canCreate")) {
        $msgs[] = "Antrag ist nicht erstellbar";
        return false;
    }

    $oldAntrag = getAntrag($oldAntragId);
    if ($oldAntrag === false) {
        $msgs[] = "Unknown / unreadable source antrag.";
        return false;
    }
    if ($oldAntragVersion !== $oldAntrag["version"]) {
        $msgs[] = "Der Antrag wurde von jemanden anderes bearbeitet und kann daher nicht gespeichert werden. (oldAntrag during copy)";
        return false;
    }

    $oldForm = getForm($oldAntrag["type"], $oldAntrag["revision"]);

    if ($oldAntragNewState !== false && $oldAntragNewState != "") {
        if (false === writeState($oldAntragNewState, $oldAntrag, $oldForm, $msgs, $filesCreated, $filesRemoved, $target))
            return false;
        $oldAntrag = getAntrag($oldAntragId);
        if ($oldAntrag === false) {
            $msgs[] = "Unknown / unreadable source antrag.";
            return false;
        }
    }

    $antrag = [];
    $antrag["type"] = $newType;
    $antrag["revision"] = $newRevision;
    $antrag["creator"] = getUsername();
    $antrag["creatorFullName"] = getUserFullName();
    $antrag["token"] = $token = substr(sha1(sha1(mt_rand())),0,16);
    $antrag["createdat"] = date("Y-m-d H:i:s");
    $antrag["lastupdated"] = date("Y-m-d H:i:s");
    $createState = "draft";
    if (isset($form["_class"]["createState"]))
        $createState = $form["_class"]["createState"];
    $antrag["state"] = $createState;
    $antrag["stateCreator"] = getUsername();
    $antrag_id = dbInsert("antrag", $antrag);
    if ($antrag_id === false)
        return false;

    $target = str_replace("//","/",$URIBASE."/").rawurlencode($token);
    $antrag_id = (int) $antrag_id;
    #$msgs[] = "Antrag wurde erstellt.";

    # füge alle Felder ein, überflüssige Felder werden beim nächsten Speichern entfernt.
    foreach($oldAntrag["_inhalt"] as $row) {
        $row["antrag_id"] = $antrag_id;
        $ret0 = dbInsert("inhalt", $row);
        if ($ret0 === false)
            return false;
    }

    $foundBuildFrom = false;
    if (isset($form["_class"]["buildFrom"])) {
        foreach($form["_class"]["buildFrom"] as $tmp) {
            if (is_array($tmp) && $tmp[0] != $oldForm["type"])
                continue;
            elseif (!is_array($tmp) && $tmp != $oldForm["type"])
                continue;
            $foundBuildFrom = true;
            break;
        }
    }

    if ($oldForm["type"] != $form["type"] &&
        $foundBuildFrom &&
        isset($form["config"]["referenceField"]))
    {
        if (!hasPermission($oldForm, $oldAntrag, "canBeLinked")) {
            $msgs[] = "Dieses Formular darf nicht verlinkt werden.";
            return false;
        }
        $row = Array();
        $row["antrag_id"] = $antrag_id;
        $row["contenttype"] = $form["config"]["referenceField"]["type"];
        $row["fieldname"] = $form["config"]["referenceField"]["name"];
        $row["value"] = $oldAntrag["id"];
        $ret0 = dbInsert("inhalt", $row);
        if ($ret0 === false)
            return false;
    }

    $ret0 = writeFillOnCopy($antrag_id, $form);
    if ($ret0 === false)
        return false;

    # füge alle Felder ein, überflüssige Felder werden beim nächsten Speichern entfernt.
    foreach($oldAntrag["_anhang"] as $row) {

        $dbPath = uniqid().".".pathinfo($row["filename"], PATHINFO_EXTENSION);
        $destPath = $STORAGE."/".$antrag_id."/".$dbPath;
        $srcPath = $STORAGE."/".$row["antrag_id"]."/".$row["path"];
        if (!is_dir(dirname($destPath)))
            mkdir(dirname($destPath),0777,true);

        $row["antrag_id"] = $antrag_id;
        $row["path"] = $dbPath;

        $ret0 = link($srcPath, $destPath);
        if ($ret0 === false)
            $ret0 = copy($srcPath, $destPath);
        $filesCreated[] = $destPath;

        $ret1 = dbInsert("anhang", $row);
        if (($ret0 === false) || ($ret1 === false))
            return false;
    }

    if (!isValid($antrag_id, "postEdit", $msgs))
        return false;

    $newAntrag = getAntrag($antrag_id);
    $ret = doNewStateActions($form, "create.$createState", $newAntrag, $createState, $msgs, $filesCreated, $filesRemoved, $target);
    if ($ret === false)
        return false;

    return $antrag_id;
}

if(isset($_REQUEST['id'])){
    $antrag = dbGet("antrag", ["id" => $_REQUEST['id']]);
    header("Location: ".$antrag['token']);
    exit;
}
if (isset($_REQUEST["action"])) {
    global $msgs;
    $msgs = Array();
    $ret = false;
    $target = false;
    $altTarget = false;
    $forceClose = false;

    if (!isset($_REQUEST["nonce"]) || $_REQUEST["nonce"] !== $nonce) {
        $msgs[] = "Formular veraltet - CSRF Schutz aktiviert.";
        $logId = false;
    } else {
        $logId = logThisAction();
        if (strpos($_POST["action"],"insert") !== false ||
            strpos($_POST["action"],"update") !== false ||
            strpos($_POST["action"],"delete") !== false) {
            foreach ($_REQUEST as $k => $v) {
                $_REQUEST[$k] = trimMe($v);
            }
        }

        switch ($_POST["action"]):
        case "antrag.delete":
        // beginTx
        if (!dbBegin()) {
            $msgs[] = "Cannot start DB transaction";
            $ret = false;
            break;
        }

        $antrag = getAntrag();
        // check antrag type and revision
        if ($_REQUEST["type"] !== $antrag["type"]) die("Unerlaubter Typ");
        if ($_REQUEST["revision"] !== $antrag["revision"]) die("Unerlaubte Version");

        $form = getForm($antrag["type"], $antrag["revision"]);
        if (!hasPermission($form, $antrag, "canDelete")) die("Antrag {$antrag["id"]} ist nicht löschbar.");

        if ($_REQUEST["version"] !== $antrag["version"]) {
            $ret = false;
            $msgs[] = "Der Antrag wurde von jemanden anderes bearbeitet und kann daher nicht gespeichert werden.";
        } else {
            $ret = true;
        }
        $filesCreated = []; $filesRemoved = [];

        $anhaenge = dbFetchAll("anhang", [ "antrag_id" => $antrag["id"] ]);
        foreach($anhaenge as $anhang) {
            $msgs[] = "Lösche Anhang ".$anhang["fieldname"]." / ".$anhang["filename"];
            $ret1 = dbDelete("anhang", [ "antrag_id" => $anhang["antrag_id"], "id" => $anhang["id"] ]);
            $ret = $ret && ($ret1 === 1);
            $filesRemoved[] = $STORAGE."/".$anhang["antrag_id"]."/".$anhang["path"];
        }
        dbDelete("inhalt", [ "antrag_id" => $antrag["id"] ]);
        dbDelete("comments", [ "antrag_id" => $antrag["id"] ]);
        dbDelete("anhang", [ "antrag_id" => $antrag["id"] ]);
        dbDelete("antrag", [ "id" => $antrag["id"] ]);

        if (count($filesCreated) > 0) die("ups files created during antrag.delete");
        // commitTx
        if ($ret)
            $ret = dbCommit();
        if (!$ret) {
            dbRollBack();
            foreach ($filesCreated as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        } else {
            // delete files from disk after successfull commit
            foreach ($filesRemoved as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        }
        if ($ret) {
            if (file_exists($STORAGE."/".$antrag["id"])) {
                if (@rmdir($STORAGE."/".$antrag["id"]) === false)
                    $msgs[] = "Kann Ordner nicht löschen: {$antrag["id"]}";
            }
            $forceClose = true;
            $target = $URIBASE;
        }
        break;
        case "antrag.update":
        case "antrag.updatePartiell":
        $isPartiell = ($_POST["action"] == "antrag.updatePartiell");

        // beginTx
        if (!dbBegin()) {
            $msgs[] = "Cannot start DB transaction";
            $ret = false;
            break;
        }
        $antrag = getAntrag();
        // check antrag type and revision, token cannot be altered
        if ($_REQUEST["type"] !== $antrag["type"]) die("Unerlaubter Typ");
        if ($_REQUEST["revision"] !== $antrag["revision"]) die("Unerlaubte Version");

        $form = getForm($antrag["type"], $antrag["revision"]);
        if ($isPartiell) {
            if (!hasPermission($form, $antrag, "canEditPartiell")) die("Antrag {$antrag["id"]} ist nicht (partiell-)editierbar");
        } else {
            if (!hasPermission($form, $antrag, "canEdit")) die("Antrag {$antrag["id"]} ist nicht editierbar");
        }

        if ($_REQUEST["version"] !== $antrag["version"]) {
            $ret = false;
            $msgs[] = "Der Antrag wurde von jemanden anderes bearbeitet und kann daher nicht gespeichert werden.";
        } else {
            $ret = true;
        }
        $filesCreated = []; $filesRemoved = [];
        // update last-modified timestamp
        dbUpdate("antrag", [ "id" => $antrag["id"] ], ["lastupdated" => date("Y-m-d H:i:s"), "version" => $antrag["version"] + 1 ]);
        // clear all old values (tbl inhalt)
        if (!$isPartiell)
            dbDelete("inhalt", [ "antrag_id" => $antrag["id"] ]);
        // add new values
        $ret1 = writeFormdata($antrag["id"], $isPartiell, $form, $antrag);
        $ret = $ret && $ret1;
        // delete files (tbl anhang) and change fieldname
        function buildAnhangRenameMap($antrag_id, $newFieldName, $formdata, &$fieldNameMap) {
            global $msgs;

            if (is_array($formdata)) {
                $ret = true;
                foreach (array_keys($formdata) as $key) {
                    $ret1 = buildAnhangRenameMap($antrag_id, "${newFieldName}[${key}]", $formdata[$key], $fieldNameMap);
                    $ret = $ret && $ret1;
                }
                return $ret;
            }

            if ($formdata == "") {
                $msgs[] = "old field name empty for $newFieldName";
                return true; // no empty name
            }

            $fieldNameMap[$formdata /* aka oldFieldName */] = $newFieldName;
            return true;
        }

        if (!$isPartiell) { // dynamic tables cannot be edited with editPartiell
            $fieldNameMap = [];
            foreach($_REQUEST["formdata"] as $fieldname => $value) {
                $fieldtype = $_REQUEST["formtype"][$fieldname];
                if ($fieldtype != "file" && $fieldtype != "multifile") continue;
                if (!is_array($value)) continue;
                if (!isset($value["oldFieldName"])) continue;
                $ret1 = buildAnhangRenameMap($antrag["id"], $fieldname, $value["oldFieldName"], $fieldNameMap);
                $ret = $ret && $ret1;
            }

            $anhaenge = dbFetchAll("anhang", [ "antrag_id" => $antrag["id"] ]);
            foreach($anhaenge as $anhang) {
                $oldFieldName = $anhang["fieldname"];
                if (isset($fieldNameMap[$oldFieldName])) {
                    $newFieldName = $fieldNameMap[$oldFieldName];
                    if ($newFieldName != $oldFieldName) {
                        $ret1 = dbUpdate("anhang", [ "antrag_id" => $anhang["antrag_id"], "id" => $anhang["id"] ], [ "fieldname" => $newFieldName ]);
                        $ret = $ret && ($ret1 === 1);
                    }
                } else {
                    $msgs[] = "Lösche Anhang ".$anhang["fieldname"]." / ".$anhang["filename"];
                    $ret1 = dbDelete("anhang", [ "antrag_id" => $anhang["antrag_id"], "id" => $anhang["id"] ]);
                    $ret = $ret && ($ret1 === 1);
                    $filesRemoved[] = $STORAGE."/".$anhang["antrag_id"]."/".$anhang["path"];
                }
            }
        } /* isPartiell */
        // rename files (aka filename) (tbl anhang)
        function renameAnhang($antrag_id, $fieldname, $formdata) {
            global $msgs;

            if (is_array($formdata)) {
                $ret = true;
                foreach (array_keys($formdata) as $key) {
                    $ret1 = renameAnhang($antrag_id, "${fieldname}[${key}]", $formdata[$key]);
                    $ret = $ret && $ret1;
                }
                return $ret;
            }

            if ($formdata == "") return true; // no empty name

            $anhang = dbGet("anhang", [ "antrag_id" => $antrag_id, "fieldname" => $fieldname ]);
            if ($anhang === false) {
                $msgs[] = "Anhang $fieldname not found for rename.";
                return false;
            }

            $ret = dbUpdate("anhang", [ "antrag_id" => $antrag_id, "id" => $anhang["id"] ], [ "filename" => $formdata ] );
            $ret = ($ret === 1);
            if (!$ret) {
                $msgs[]="failed field $fieldname => filename $formdata";
            }

            return $ret;
        }
        foreach($_REQUEST["formdata"] as $fieldname => $value) {
            $fieldtype = $_REQUEST["formtype"][$fieldname];
            if ($fieldtype != "file" && $fieldtype != "multifile") continue;
            if (!is_array($value)) continue;
            if (!isset($value["newFileName"])) continue;
            if ($isPartiell) {
                $perm = "canEditPartiell.field.{$fieldname}";
                if (!hasPermission($form, $antrag, $perm)) continue;
            }
            $ret1 = renameAnhang($antrag["id"], $fieldname, $value["newFileName"]);
            $ret = $ret && $ret1;
        }
        // add or replace (or delete) new files (tbl anhang) and write files to disk
        $ret1 = writeFormdataFiles($antrag["id"], $msgs, $filesRemoved, $filesCreated, $isPartiell, $form, $antrag);
        $ret = $ret && $ret1;
        // post-fill on otherForm change
        if (isset($_REQUEST["triggerFillOnCopy"]) && $_REQUEST["triggerFillOnCopy"]) {
            $ret1 = writeFillOnCopy($antrag["id"], $form);
            $ret = $ret && $ret1;
        }
        // commitTx
        if ($ret && isset($_REQUEST["state"]) && $_REQUEST["state"] != "") {
            $newState = $_REQUEST["state"];
            $antrag = getAntrag(); // report new version to user
            $ret = writeState($newState, $antrag, $form, $msgs, $filesCreated, $filesRemoved, $altTarget);
        }
        if ($ret && !isValid($antrag["id"], "postEdit", $msgs))
            $ret = false;
        if ($ret)
            $ret = dbCommit();
        if (!$ret) {
            dbRollBack();
            foreach ($filesCreated as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        } else {
            // delete files from disk after successfull commit
            foreach ($filesRemoved as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        }
        if ($ret) {
            $forceClose = true;
            $target = str_replace("//","/",$URIBASE."/").rawurlencode($antrag["token"]);
            if (isset($_REQUEST["subaction"]) && $_REQUEST["subaction"] == "resumeEdit") {
                switch ($_POST["action"]) {
                    case "antrag.update":
                        $target .= "/edit";
                        break;
                    case "antrag.updatePartiell":
                        $target .= "/editPartiell";
                        break;
                }
                if (isset($_REQUEST["overrideOnNextEdit"])) {
                    $target .= "?".http_build_query([ "override" => $_REQUEST["overrideOnNextEdit"] ] );
                }
            } elseif ($altTarget !== false) {
                # nothing special here, we can safely redirect to new form
                $target = $altTarget;
                $altTarget = false;
            }
        }
        break;
        case "antrag.create-import":
        if (!isset($_REQUEST["type"]) || !isset($_REQUEST["revision"])) {
            header("Location: $URIBASE");
            exit;
        }
        $doImport = isset($_FILES["importfile"]) && ($_FILES["importfile"]["error"] == UPLOAD_ERR_OK) &&  is_uploaded_file($_FILES["importfile"]["tmp_name"]);
        if (!$doImport) {
            $forceClose = true;
            $target = str_replace("//","/",$URIBASE)."?tab=antrag.create&type=".rawurlencode($_REQUEST["type"])."&revision=".rawurlencode($_REQUEST["revision"]);
            $ret = true;
            break;
        }
        /* fall-through */
        case "antrag.create":
        if (!dbBegin()) {
            $msgs[] = "Cannot start DB transaction";
            $ret = false;
            break;
        }

        $form = getForm($_REQUEST["type"], $_REQUEST["revision"]);
        if ($form === false)
            die("Unbekannte Formularversion");

        if (!hasPermission($form, null, "canCreate")) die("Antrag ist nicht erstellbar");

        $filesCreated = []; $filesRemoved = [];

        $antrag = [];
        $antrag["type"] = $_REQUEST["type"];
        $antrag["revision"] = $_REQUEST["revision"];
        $antrag["creator"] = getUsername();
        $antrag["creatorFullName"] = getUserFullName();
        $antrag["token"] = $token = substr(sha1(sha1(mt_rand())),0,16);
        $antrag["createdat"] = date("Y-m-d H:i:s");
        $antrag["lastupdated"] = date("Y-m-d H:i:s");
        $createState = "draft";
        if (isset($form["_class"]["createState"]))
            $createState = $form["_class"]["createState"];
        $antrag["state"] = $createState; // FIXME custom default state
        $antrag["stateCreator"] = getUsername();
        $ret = dbInsert("antrag", $antrag);
        if ($ret !== false) {
            $target = str_replace("//","/",$URIBASE."/").rawurlencode($token);
            $antrag_id = (int) $ret;
            #$msgs[] = "Antrag wurde erstellt.";
            $ret = true;
        }

        $doImport = isset($_FILES["importfile"]) && ($_FILES["importfile"]["error"] == UPLOAD_ERR_OK) &&  is_uploaded_file($_FILES["importfile"]["tmp_name"]);

        if ($ret && !$doImport) {
            $ret0 = writeFormdata($antrag_id, false, null, null);

            $ret1 = writeFormdataFiles($antrag_id, $msgs, $filesRemoved, $filesCreated, false, null, null);

            $ret = $ret && $ret0 && $ret1;
        } /* dbInsert(antrag) -> $ret !== false */

        if ($ret && $doImport) {
            $zip = new ZipArchive();
            if ($zip->open( $_FILES["importfile"]["tmp_name"] ) === false) {
                $msgs[] = "Invalid Importfile: {$_FILES["importfile"]["tmp_name"]}";
                $ret = false;
            }
        }
        if ($ret && $doImport) {
            $oldAntrag = $zip->getFromName("antrag.json");
            if ($oldAntrag !== false) {
                $oldAntrag = json_decode($oldAntrag,true);
            } else {
                $msgs[] = "Invalid Importfile: missing antrag.json";
                $ret = false;
            }
        }
        if ($ret && $doImport) {
            if (!is_array($oldAntrag) || !is_array($oldAntrag["_anhang"]) || !is_array($oldAntrag["_inhalt"])) {
                $msgs[] = "Invalid Importfile: bad json data";
                $ret = false;
            }
        }
        if ($ret && $doImport) {
            foreach ($oldAntrag["_inhalt"] as $ih) {
                $ih["antrag_id"] = $antrag_id;
                if (isset($ih["id"]))
                    unset($ih["id"]);
                $ret0 = dbInsert("inhalt", $ih);
                if ($ret0 === false) {
                    $msgs[] = "Failed to import field";
                    $ret = false;
                    break;
                }
            }
        }
        if ($ret && $doImport) {
            foreach ($oldAntrag["_anhang"] as $ah) {
                if (strtolower($ah["state"]) != "active") continue;
                $content = $zip->getFromName($ah["id"].".attach");
                if ($content === false) {
                    $msgs[] = "Failed to import file";
                    $ret = false;
                    break;
                }
                $ah["antrag_id"] = $antrag_id;
                if (isset($ah["id"]))
                    unset($ah["id"]);
                $dbPath = uniqid().".".pathinfo($ah["filename"], PATHINFO_EXTENSION);
                $path = $STORAGE."/".$ah["antrag_id"]."/".$dbPath;
                if (!is_dir(dirname($path)))
                    mkdir(dirname($path),0777,true);
                $ah["path"] = $dbPath;

                $filesCreated[] = $path;
                $ret0 = file_put_contents($path, $content);
                if ($ret0 === false) {
                    $msgs[] = "Failed to import file";
                    $ret = false;
                    break;
                }

                $ret0 = dbInsert("anhang", $ah);
                if ($ret0 === false) {
                    $msgs[] = "Failed to import file";
                    $ret = false;
                    break;
                }
            }
        }

        if (count($filesRemoved) > 0) die("ups files removed during antrag.create");
        if ($ret && !isValid($antrag_id, "postEdit", $msgs))
            $ret = false;
        if ($ret) {
            $antrag = getAntrag($antrag_id); // report new version to user
            $ret = doNewStateActions($form, "create.$createState", $antrag, $createState, $msgs, $filesCreated, $filesRemoved, $target);
            if ($ret === false) $msgs[] = "Mit Status verknüpfte Aktion fehlgeschlagen.";
        }
        if (isset($_REQUEST["state"]) && $ret && $_REQUEST["state"] != "") {
            $antrag = getAntrag($antrag_id); // report new version to user
            if ($antrag === false) die("Ups failed to read antrag just created");
            $newState = $_REQUEST["state"];
            $ret = writeState($newState, $antrag, $form, $msgs, $filesCreated, $filesRemoved, $target);
            if ($ret === false) $msgs[] = "Mit Status verknüpfte Aktion fehlgeschlagen.";
        }

        if ($ret)
            $ret = dbCommit();
        if (!$ret) {
            dbRollBack();
            foreach ($filesCreated as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        } else {
            foreach ($filesRemoved as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        }

        if ($ret && isset($_REQUEST["subaction"]) && $_REQUEST["subaction"] == "resumeEdit") {
            $target .= "/edit";
        }
        break;
        case "antrag.copy":
        if (!dbBegin()) {
            $msgs[] = "Cannot start DB transaction";
            $ret = false;
            break;
        }
        $ret = true;

        $filesCreated = []; $filesRemoved = [];
        $oldAntragNewState = false;
        if (isset($_REQUEST["copy_from_state"]))
            $oldAntragNewState = $_REQUEST["copy_from_state"];
        $oldAntrag = getAntrag($_REQUEST["copy_from"]);
        if ($oldAntrag !== false)
            $oldForm = getForm($oldAntrag["type"], $oldAntrag["revision"]);
        if ($ret && $oldAntrag["type"] == $_REQUEST["type"] && !hasPermission($oldForm, $oldAntrag, "canBeCloned")) {
            // cloning
            $msgs[] = "Kopieren nicht erlaubt. Wolltest du vielleicht den zugehörigen Antrag kopieren?";
            $ret = false;
        }
        $antrag_id = copyAntrag($_REQUEST["copy_from"], $_REQUEST["copy_from_version"], $oldAntragNewState, $_REQUEST["type"], $_REQUEST["revision"], $msgs, $filesCreated, $filesRemoved, $target);
        if ($antrag_id === false)
            $ret = false;
        else
            $target .= "/edit";

        if (count($filesRemoved) > 0) die("ups files removed during antrag.copy");
        if ($ret && !isValid($antrag_id, "postEdit", $msgs))
            $ret = false;
        if ($ret)
            $ret = dbCommit();
        if (!$ret) {
            dbRollBack();
            foreach ($filesCreated as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        } else {
            foreach ($filesRemoved as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        }

        break;
        case "antrag.state":
        // beginTx
        if (!dbBegin()) {
            $msgs[] = "Cannot start DB transaction";
            $ret = false;
            break;
        }
        $filesCreated = []; $filesRemoved = [];

        $antrag = getAntrag();
        // check antrag type and revision, token cannot be altered
        if ($_REQUEST["type"] !== $antrag["type"]) die("Unerlaubter Typ");
        if ($_REQUEST["revision"] !== $antrag["revision"]) die("Unerlaubte Version");

        if ($_REQUEST["version"] !== $antrag["version"]) {
            $ret = false;
            $msgs[] = "Der Antrag wurde von jemanden anderes bearbeitet und kann daher nicht gespeichert werden.";
        } else {
            $ret = true;
        }

        $newState = $_REQUEST["state"];
        if ($ret) {
            $form = getForm($antrag["type"], $antrag["revision"]);
            $ret = writeState($newState, $antrag, $form, $msgs, $filesCreated, $filesRemoved, $target);
        }

        // commitTx
        if ($ret && !isValid($antrag["id"], "postEdit", $msgs)) {
            $msgs[] = "Validation failed";
            $ret = false;
        }
        if ($ret)
            $ret = dbCommit();
        if (!$ret) {
            dbRollBack();
            foreach ($filesCreated as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        } else {
            // delete files from disk after successfull commit
            foreach ($filesRemoved as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        }
        if ($ret) {
            $forceClose = true;
            if ($target === false)
                $target = str_replace("//","/",$URIBASE."/").rawurlencode($antrag["token"]);
        }
        break;
        case "hibiscus":
        requireGroup($HIBISCUSGROUP);

        $ret = true;
        if (!dbBegin()) {
            $msgs[] = "Cannot start DB transaction";
            $ret = false;
            break;
        }

        $newFormAnfangsbestand = fetchFromHibiscusAnfangsbestand();
        foreach ($newFormAnfangsbestand as $inhalte) {
            $antrag = [];
            $antrag["type"] = "zahlung";
            $antrag["revision"] = "v1-anfangsbestand";
            $antrag["creator"] = getUsername();
            $antrag["creatorFullName"] = getUserFullName();
            $antrag["token"] = $token = substr(sha1(sha1(mt_rand())),0,16);
            $antrag["createdat"] = date("Y-m-d H:i:s");
            $antrag["lastupdated"] = date("Y-m-d H:i:s");
            $createState = "draft";
            $form = getForm($antrag["type"], $antrag["revision"]);
            if (isset($form["_class"]["createState"]))
                $createState = $form["_class"]["createState"];
            $antrag["state"] = $createState; // FIXME custom default state
            $antrag["stateCreator"] = getUsername();
            $ret0 = dbInsert("antrag", $antrag);
            if ($ret0 === false) {
                $msgs[] = "antrag.create failed";
                $ret = false;
                continue;
            }
            $antrag_id = (int) $ret0;

            foreach ($inhalte as $inhalt) {
                $inhalt["antrag_id"] = $antrag_id;
                $ret0 = dbInsert("inhalt", $inhalt);
                $ret = $ret && $ret0;
            }

            if ($ret && !isValid($antrag_id, "postEdit", $msgs))
                $ret = false;
        }

        $newFormZahlungen = fetchFromHibiscus();

        foreach ($newFormZahlungen as $inhalte) {
            $antrag = [];
            $antrag["type"] = "zahlung";
            $antrag["revision"] = "v1-giro-hibiscus";
            $antrag["creator"] = getUsername();
            $antrag["creatorFullName"] = getUserFullName();
            $antrag["token"] = $token = substr(sha1(sha1(mt_rand())),0,16);
            $antrag["createdat"] = date("Y-m-d H:i:s");
            $antrag["lastupdated"] = date("Y-m-d H:i:s");
            $createState = "draft";
            $form = getForm($antrag["type"], $antrag["revision"]);
            if (isset($form["_class"]["createState"]))
                $createState = $form["_class"]["createState"];
            $antrag["state"] = $createState; // FIXME custom default state
            $antrag["stateCreator"] = getUsername();
            $ret0 = dbInsert("antrag", $antrag);
            if ($ret0 === false) {
                $msgs[] = "antrag.create failed";
                $ret = false;
                continue;
            }
            $antrag_id = (int) $ret0;

            foreach ($inhalte as $inhalt) {
                $inhalt["antrag_id"] = $antrag_id;
                $ret0 = dbInsert("inhalt", $inhalt);
                $ret = $ret && $ret0;
            }

            if ($ret && !isValid($antrag_id, "postEdit", $msgs))
                $ret = false;
        }
        if ($ret)
            $ret = dbCommit();
        if (!$ret)
            dbRollBack();
        if ($ret) {
            $forceClose = true;
            $target = "$URIBASE?tab=booking";
        }
        break;
        case "booking":
        requireGroup($HIBISCUSGROUP);
        $zahlungSum = 0.00;
        $grundSum = 0.00;

        if (!isset($_REQUEST["zahlungId"])) $_REQUEST["zahlungId"] = [];
        if (!isset($_REQUEST["grundId"])) $_REQUEST["grundId"] = [];
        if (count($_REQUEST["grundId"]) != 1 && count($_REQUEST["zahlungId"]) != 1 ) {
            $msgs[] = "Nur 1:n oder n:1 Zuordnungen erlaubt. Zu viele oder keine Buchungen ausgewählt.";
            $ret = false;
            break;
        }

        foreach($_REQUEST["zahlungId"] as $aId)
            $zahlungSum += (float) $_REQUEST["zahlungValue"][$aId];
        foreach($_REQUEST["grundId"] as $aId)
            $grundSum += (float) $_REQUEST["grundValue"][$aId];

        if (abs($zahlungSum - $grundSum) > 0.001) {
            $msgs[] = "Die Beträge stimmen nicht überein: $zahlungSum != $grundSum.";
            $ret = false;
            break;
        }

        if (!dbBegin()) {
            $msgs[] = "Cannot start DB transaction";
            $ret = false;
            break;
        } else {
            $ret = true;
        }

        $filesCreated = []; $filesRemoved = [];

        foreach($_REQUEST["zahlungId"] as $zId) {
            $appendGrund = [];
            foreach($_REQUEST["grundId"] as $gId) {
                if (count($_REQUEST["zahlungId"]) == 1) {
                    # alle Gründe zusammen addieren auf diese Zahlung
                    $appendGrund[] = [ $gId, $_REQUEST["grundValue"][$gId] ];
                } elseif (count($_REQUEST["grundId"]) == 1) {
                    # alle Zahlungen zusammen addieren auf diesen Grund
                    $appendGrund[] = [ $gId, $_REQUEST["zahlungValue"][$zId] ];
                } else {
                    die("ups cannot compute this");
                }
            }
            $z = getAntrag($zId);
            if ($z === false) {
                $msgs[] = "Zahlung $zId war nicht lesbar.";
                $ret = false;
                break;
            }
            $form = getForm($z["type"], $z["revision"]);
            $canEditZahlung = hasPermission($form, $z, "canEdit");
            $canEditPartiellZahlung = hasPermission($form, $z, "canEditPartiell") &&
                hasPermission($form, $z, "canEditPartiell.field.zahlung.grund.table") &&
                hasPermission($form, $z, "canEditPartiell.field.zahlung.group2") &&
                hasPermission($form, $z, "canEditPartiell.field.zahlung.grund.beleg") &&
                hasPermission($form, $z, "canEditPartiell.field.zahlung.grund.hinweis") &&
                hasPermission($form, $z, "canEditPartiell.field.zahlung.grund.einnahmen") &&
                hasPermission($form, $z, "canEditPartiell.field.zahlung.grund.ausgaben");

            if (!$canEditZahlung && !$canEditPartiellZahlung) die("Antrag (Zahlung) $zId ist nicht (partiell-)editierbar");

            $rowCountFieldName =  "zahlung.grund.table[rowCount]";
            $rowNumber = getFormValueInt($rowCountFieldName, null, $z["_inhalt"], false);
            $rowNumberPresent = true;
            if ($rowNumber === false) {
                $rowNumberPresent = false;
                $rowNumber = 0;
            }
            $rowIdNumberPresent = true;
            $rowIdCountFieldName =  "zahlung.grund.table[rowIdCount]";
            $rowIdNumber = getFormValueInt($rowIdCountFieldName, null, $z["_inhalt"], false);
            if ($rowIdNumber === false) {
                $rowIdNumberPresent = false;
                $rowIdNumber = 0;
            }

            foreach ($appendGrund as $i => $a) {

                $rowIdFieldName = "zahlung.grund.table[rowId][{$rowNumber}]";
                $inhalt = [ "fieldname" => $rowIdFieldName, "contenttype" => "table", "antrag_id" => $zId ];
                $inhalt["value"] = $rowIdNumber;

                $ret0 = dbInsert("inhalt", $inhalt);
                if ($ret0 === false) $ret = false;

                $rowBelegFieldName = "zahlung.grund.beleg[{$rowNumber}]";
                $inhalt = [ "fieldname" => $rowBelegFieldName, "contenttype" => "otherForm", "antrag_id" => $zId ];
                $inhalt["value"] = $a[0];
                $ret0 = dbInsert("inhalt", $inhalt);
                if ($ret0 === false) $ret = false;

                $value = $a[1];
                if ($value < 0) {
                    $value *= -1.0;
                    $rowGeldFieldName = "zahlung.grund.ausgaben[{$rowNumber}]";
                } else {
                    $rowGeldFieldName = "zahlung.grund.einnahmen[{$rowNumber}]";
                }
                $inhalt = [ "fieldname" => $rowGeldFieldName, "contenttype" => "money", "antrag_id" => $zId ];
                $inhalt["value"] = $value; # this already is a php float, so convertUserValueToDBValue($value, $inhalt["contenttype"]) is not needed
                $ret0 = dbInsert("inhalt", $inhalt);
                if ($ret0 === false) $ret = false;
                $rowNumber++;
                $rowIdNumber++;
            }

            if ($rowNumberPresent) {
                $ret0 = dbUpdate("inhalt", ["fieldname" => $rowCountFieldName, "contenttype" => "table", "antrag_id" => $zId ], [ "value" => $rowNumber ] );
            } else {
                $ret0 = dbInsert("inhalt", [ "fieldname" => $rowCountFieldName, "contenttype" => "table", "antrag_id" => $zId, "value" => $rowNumber ] );

            }

            if ($ret0 === false) $ret = false;

            if ($rowIdNumberPresent) {
                $ret0 = dbUpdate("inhalt", ["fieldname" => $rowIdCountFieldName, "contenttype" => "table", "antrag_id" => $zId ], [ "value" => $rowIdNumber ] );
            } else {
                $ret0 = dbInsert("inhalt", [ "fieldname" => $rowIdCountFieldName, "contenttype" => "table", "antrag_id" => $zId, "value" => $rowIdNumber ] );

            }
            $msgs[] = $ret;
            if ($ret0 === false) $ret = false;

            if ($ret && !isValid($zId, "postEdit", $msgs))
                $ret = false;
        }//iteration über alle zahlungsgründe

        // alle Buchungen wechseln nach state booked
        foreach($_REQUEST["zahlungId"] as $aId) {
            $a = dbGet("antrag", ["id" => $aId]);
            $a["_inhalt"] = dbFetchAll("inhalt", ["antrag_id" => $aId]);
            $form = getForm($a["type"], $a["revision"]);
            $ret0 = writeState("booked", $a, $form, $msgs, $filesCreated, $filesRemoved, $target);
            $ret = $ret && $ret0;
        }

        // alle Zahlungen wechseln nach state payed
        foreach($_REQUEST["grundId"] as $aId) {
            $a = dbGet("antrag", ["id" => $aId]);
            $a["_inhalt"] = dbFetchAll("inhalt", ["antrag_id" => $aId]);
            $form = getForm($a["type"], $a["revision"]);
            if($a['type']=='extern-express'){
                //Extern Express anträge
                if(isValid($a['id'],"vorkasse-zu-zahlen")){
                    $ret0 = writeState("vorkasse-bezahlt", $a, $form, $msgs, $filesCreated, $filesRemoved, $target);
                }else{
                    $ret0 = writeState("terminated", $a, $form, $msgs, $filesCreated, $filesRemoved, $target);
                }
            }else{
                //jeder anderer Typ
                $ret0 = writeState("payed", $a, $form, $msgs, $filesCreated, $filesRemoved, $target);
            }
            $ret = $ret && $ret0;
        }

        if ($ret)
            $ret = dbCommit();
        if (!$ret) {
            dbRollBack();
            foreach ($filesCreated as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        } else {
            // delete files from disk after successfull commit
            foreach ($filesRemoved as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        }
        if ($ret) {
            $forceClose = true;
            $target = "$URIBASE?tab=booking";
        }
        break;
        //action
        case "hibiscus.sct":
        if (!isset($_REQUEST["ueberweisung"])) {
            $forceClose = true;
            $target = "$URIBASE?tab=hibiscus.sct";
            $msgs[] = "Keine Zahlung übermittelt.";
            break;
        }

        if (!dbBegin()) {
            $msgs[] = "Cannot start DB transaction";
            $ret = false;
            break;
        } else {
            $ret = true;
        }

        $filesCreated = []; $filesRemoved = [];

        $antrag = [];
        $antrag["type"] = "zahlung-anweisung";
        $antrag["revision"] = "v1-giro";
        $antrag["creator"] = getUsername();
        $antrag["creatorFullName"] = getUserFullName();
        $antrag["token"] = $token = substr(sha1(sha1(mt_rand())),0,16);
        $antrag["createdat"] = date("Y-m-d H:i:s");
        $antrag["lastupdated"] = date("Y-m-d H:i:s");
        $form = getForm($antrag["type"], $antrag["revision"]);
        $createState = "draft";
        if (isset($form["_class"]["createState"]))
            $createState = $form["_class"]["createState"];
        $antrag["state"] = $createState;
        $antrag["stateCreator"] = getUsername();
        if (!hasPermission($form, $antrag, "canCreate")) {
            $forceClose = true;
            $target = "$URIBASE?tab=hibiscus.sct";
            $msgs[] = "Zahlungsanweisung kann nicht erstellt werden: keine Berechtigung.";
            $ret = false;
            break;
        }
        $antrag_id = dbInsert("antrag", $antrag);
        if ($antrag_id === false) {
            $forceClose = true;
            $target = "$URIBASE?tab=hibiscus.sct";
            $msgs[] = "Zahlungsanweisung kann nicht erstellt werden.";
            $ret = false;
            break;
        }

        $target = str_replace("//","/",$URIBASE."/").rawurlencode($token);
        $antrag_id = (int) $antrag_id;

        $rowCountFieldName =  "zahlung.table[rowCount]";
        $rowNumber = 0;
        $rowIdCountFieldName =  "zahlung.table[rowIdCount]";
        $rowIdNumber = 0;

        $map = [
            "id" => [ "zahlung.beleg", "otherForm" ],
            "betrag" => [ "zahlung.ausgaben", "money" ],
            "empfname" => [ "zahlung.empfname", "text" ],
            "empfiban" => [ "zahlung.empfiban", "iban" ],
            "eref" => [ "zahlung.eref", "text" ],
            "vzw" => [ "zahlung.verwendungszweck", "textarea" ],
        ];

        foreach ($_REQUEST["ueberweisung"] as $id => $u) {
            $rowIdFieldName = "zahlung.table[rowId][{$rowNumber}]";
            $inhalt = [ "fieldname" => $rowIdFieldName, "contenttype" => "table", "antrag_id" => $antrag_id ];
            $inhalt["value"] = $rowIdNumber;
            $ret0 = dbInsert("inhalt", $inhalt);
            if ($ret0 === false) {
                $ret = false;
                break 2;
            }

            foreach ($map as $reqFieldName => $dbFieldDesc) {
                $rowFieldName = "{$dbFieldDesc[0]}[{$rowNumber}]";
                $inhalt = [ "fieldname" => $rowFieldName, "contenttype" => $dbFieldDesc[1], "antrag_id" => $antrag_id ];
                $value = $u[$reqFieldName];
                if (is_array($value)) $value = implode("\n", $value);
                $inhalt["value"] = $value;
                $ret0 = dbInsert("inhalt", $inhalt);
                if ($ret0 === false) {
                    $ret = false;
                    break 3;
                }
            }

            $oldAntrag = getAntrag($id);
            $oldForm = getForm($oldAntrag["type"], $oldAntrag["revision"]);
            if ($oldAntrag === false) {
                $ret = false;
                break 2;
            }
            if ($u["version"] !== $oldAntrag["version"]) {
                $msgs[] = "Die Zahlungsgründe wurden editiert.";
                $ret = false;
                break 2;
            }
            if (false === writeState("instructed", $oldAntrag, $oldForm, $msgs, $filesCreated, $filesRemoved, $altTarget)) {
                $ret = false;
                break 2;
            }

            $rowNumber++;
            $rowIdNumber++;
        }

        $ret0 = dbInsert("inhalt", [ "fieldname" => $rowCountFieldName, "contenttype" => "table", "antrag_id" => $antrag_id, "value" => $rowNumber ] );
        if ($ret0 === false) { $ret = false; break; }

        $ret0 = dbInsert("inhalt", [ "fieldname" => $rowIdCountFieldName, "contenttype" => "table", "antrag_id" => $antrag_id, "value" => $rowIdNumber ] );
        if ($ret0 === false) { $ret = false; break; }

        $datum = date("Y-m-d");
        $ret0 = dbInsert("inhalt", [ "fieldname" => "zahlung.datum", "contenttype" => "date", "value" => $datum, "antrag_id" => $antrag_id]);
        if ($ret0 === false) { $ret = false; break; }

        $ret0 = dbInsert("inhalt", [ "fieldname" => "zahlung.konto", "contenttype" => "ref", "value" => "01 01", "antrag_id" => $antrag_id]);
        if ($ret0 === false) { $ret = false; break; }

        $f = ["type" => "kontenplan"];
        $f["state"] = "final";
        $f["revision"] = substr($datum,0,4); // year
        $al = dbFetchAll("antrag", $f);
        if (count($al) != 1) die("Kontenplan nicht gefunden: ".print_r($f,true));
        $kpId = $al[0]["id"];
        $ret0 = dbInsert("inhalt", [ "fieldname" => "kontenplan.otherForm", "contenttype" => "otherForm", "value" => $kpId, "antrag_id" => $antrag_id]);
        if ($ret0 === false) { $ret = false; break; }

        if ($ret && !isValid($antrag_id, "postEdit", $msgs))
            $ret = false;

        if ($ret)
            $ret = dbCommit();
        if (!$ret) {
            dbRollBack();
            foreach ($filesCreated as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        } else {
            // delete files from disk after successfull commit
            foreach ($filesRemoved as $f) {
                if (@unlink($f) === false) $msgs[] = "Kann Datei nicht löschen: {$f}";
            }
        }
        if ($ret) {
            $forceClose = true;
        }
        break;
        default:
        logAppend($logId, "__result", "invalid action");
        die("Aktion nicht bekannt.");
        endswitch;
    } /* switch */

    if ($logId !== false) {
        logAppend($logId, "__result", ($ret !== false) ? "ok" : "failed");
        logAppend($logId, "__result_msg", $msgs);
    }

    $result = Array();
    $result["msgs"] = $msgs;
    $result["ret"] = ($ret !== false);
    if ($target !== false)
        $result["target"] = $target;
    if ($altTarget !== false)
        $result["altTarget"] = $altTarget;
    $result["forceClose"] = ($forceClose !== false);
    # $result["_REQUEST"] = $_REQUEST;
    # $result["_FILES"] = $_FILES;

    header("Content-Type: text/json; charset=UTF-8");
    echo json_encode($result);
    exit;
}

if (!isset($_REQUEST["tab"])) {
    $_REQUEST["tab"] = "antrag.listing";
}

switch($_REQUEST["tab"]) {
    case "antrag.anhang":
        if (count($_REQUEST["__args"]) == 0)
            break;

        $antrag = getAntrag();
        $f = ["antrag_id" => $antrag["id"], "id" => $_REQUEST["__args"][0]];
        $ah = dbGet("anhang", $f);
        if ($ah === false) die("Antrag nicht gefunden.");
        header("Content-Type: ".$ah["mimetype"]);
        header('Content-Disposition: attachment; filename="' . $antrag["id"]."-".$ah["id"]." ".$ah["filename"]. '"');
        header('Content-Transfer-Encoding: binary');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private');
        header('Pragma: private');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

        $fileSize = $ah["size"];
        $filePath = $STORAGE."/".$ah["antrag_id"]."/".$ah["path"];

        // Multipart-Download and Download Resuming Support
        if(isset($_SERVER['HTTP_RANGE'])) {
            list($a, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
            list($range) = explode(',', $range, 2);
            list($range, $rangeEnd) = explode('-', $range);

            $range = intval($range);

            if(!$rangeEnd) {
                $rangeEnd = $fileSize - 1;
            }
            else {
                $rangeEnd = intval($rangeEnd);
            }

            $newLength = $rangeEnd - $range + 1;

            // Send Headers
            header('HTTP/1.1 206 Partial Content');
            header('Content-Length: ' . $newLength);
            header("Content-Range: bytes $range-$rangeEnd/$fileSize");
        }
        else {
            $range = 0;
            $newLength = $fileSize;
            header('Content-Length: ' . $fileSize);
        }

        // Output File
        $chunkSize = 1 * (1024*1024);
        $bytesSend = 0;

        if($file = fopen($filePath, 'r')) {
            if(isset($_SERVER['HTTP_RANGE']))
                fseek($file, $range);

            while(!feof($file) && !connection_aborted() && $bytesSend < $newLength) {
                $buffer = fread($file, $chunkSize);
                echo $buffer;
                flush();
                $bytesSend += strlen($buffer);
            }

            fclose($file);
        }

        exit;
    case "antrag.print":
        $antrag = getAntrag();
        $form = getForm($antrag["type"],$antrag["revision"]);
        if ($form === false) die("Unbekannter Formulartyp/-revision, kann nicht dargestellt werden.");
        $kv="";
        $hv="";
        if($antrag['type']=="auslagenerstattung" || $antrag['type'] == "rechnung-zuordnung"){ // vieles ist gleich, daher zusammengefasst
            //baue content[] das an FUI2PDF geschickt wird
            $content['meta']['type'] = $antrag['type'];
            $content['meta']['domain'] = $_SERVER["SERVER_NAME"];
            $inhalt = $antrag["_inhalt"];
            $antrag_id = $antrag['id'];
            $content['data']['id'] = $antrag_id;
            //var_dump($antrag);
            //suche IBAN, Antragsteller und so
            $inhalt_better = betterValues($inhalt);
            $content['data']['iban'] = $inhalt_better['iban'];
            $content['data']['hv'] = $inhalt_better['genehmigung.sachlicheRichtigkeit'];
            $content['data']['kv'] = $inhalt_better['genehmigung.rechnerischeRichtigkeit'];
            $content['data']['projektname'] = $inhalt_better['projekt.name'];

            $radio_val = $inhalt_better['genehmigung.recht'];
            $hhp = $inhalt_better['genehmigung.titel'];
            foreach($inhalt as $fields){
                switch(explode("[",$fields['fieldname'])[0]){
                    case 'genehmigung.konto':
                        $konto = $fields['value'];
                        break;
                    case 'genehmigung':
                        $genID = $fields['value'];
                        break;
                    case 'geld.titel':
                        $titeln[] = $fields['value'];
                        break;
                    case 'geld.konto':
                        $kontos[] = $fields['value'];
                        break;
                    case 'geld.ausgaben':
                        $ausgaben[] = $fields['value'];
                        break;
                    case 'geld.einnahmen':
                        $einnahmen[] = $fields['value'];
                        break;
                    case 'finanzauslagenposten': //Auslagenerstattung
                        $posten[] = $fields['value'];
                        break;
                    default:
                        break;
                }
            }
            // Rechtsgrundlage im System 'schönen' Fließtext zuordnen
            switch($radio_val){
                case "buero":
                    $content['data']['recht'] = "Büromaterial: Beschluss 21/20-07: bis zu 50 EUR";
                    break;
                case "fahrt":
                    $content['data']['recht'] = "Fahrtkosten: StuRa-Beschluss 21/20-08";
                    break;
                case "verbrauch":
                    $content['data']['recht'] = "Verbrauchsmaterial lt. Finanzordnung (11) bis zu 150 EUR";
                    break;
                case "stura":
                    $content['data']['recht'] = "Beschluss vom ".$inhalt_better['genehmigung.recht.stura.datum']."(".$inhalt_better['genehmigung.recht.stura.beschluss'].")";
                    break;
                case "fsr":
                    $content['data']['recht'] = $inhalt_better['genehmigung.recht.int.gremium']."/".$inhalt_better['genehmigung.recht.int.datum']." (StuRa: ".$inhalt_better['genehmigung.recht.int.sturabeschluss'].")";
                    break;
                case "kleidung":
                    $content['data']['recht'] = "Gremienkleidung: StuRa Beschluss 24/04-09";
                    break;
                case "other":
                    $content['data']['recht'] = $inhalt_better['genehmigung.recht.other.reason'];
                    break;
                default:
                    $content['data']['recht'] ="";
                    break;
            }

            //$content['data']['kostenstelle'] = $inhalt_better['genehmigung.konto'];
            switch($antrag['type']){ // einige Daten müssen unterschiedlich geholt werden
                case "auslagenerstattung":
                    $content['data']['empfaenger'] = $inhalt_better['antragsteller.name'];
                    $content['data']['projektid'] = $inhalt_better['genehmigung'];
                    break;
                    $posten = array_filter($posten,function($val){
                        return (strpos($val, '-') !== false);
                    });
                    $posten = array_map(function($val){
                        $s = strrev($val);
                        $ar = explode("-",$s);
                        $ar[0] = intval($ar[0])+1;
                        $ar[1] = intval($ar[1])+1;
                        return "B".$ar[0];//."-P".$ar[1];
                    },$posten);
                    foreach($antrag['_anhang'] as $anhang){
                        $content['pdfs'][] = $anhang['path'];
                    }
                    break;
                case "rechnung-zuordnung":
                    $content['data']['empfaenger'] = $inhalt_better['rechnung.firma'];
                    $posten = array_fill(0,count($ausgaben),'B1');
                    $id_beleg = $inhalt_better['teilrechnung.beleg'];
                    $content['meta']['belegid'] = $id_beleg;
                    $content['data']['projektid'] = $inhalt_better['teilrechnung.projekt'];
                    $antrag_beleg = getAntrag($id_beleg);
                    foreach($antrag_beleg['_anhang'] as $anhang){
                        $content['pdfs'][] = $anhang['path'];
                    }
                    break;
                default:
                    break;
            }
            //fill up all empty with default titel
            $titeln = array_map(function($value) {
                global $hhp;
                return $value === "" ? $hhp : $value;
            }, $titeln);
            //fill up all empty with default konto
            $kontos = array_map(function($value) {
                global $konto;
                return $value === "" ? $konto : $value;
            }, $kontos);
            //sum everything with same titel and same beleg
            $posten = array_values($posten);
            $tmp_a = array_fill_keys($posten,array_fill_keys($titeln,0));
            $tmp_e = array_fill_keys($posten,array_fill_keys($titeln,0));
            for($i = 0; $i < count($titeln); $i++){
                $tmp_a[$posten[$i]][$titeln[$i]] += $ausgaben[$i];
                $tmp_e[$posten[$i]][$titeln[$i]] += $einnahmen[$i];
            }
            $posten = array();
            $titeln = array();
            $ausgaben = array();
            $einnahmen = array();
            foreach($tmp_a as $bel => $ar){
                foreach($ar as $tit => $aus){
                    if($aus != 0){
                        $posten[]=$bel;
                        $titeln[] = $tit;
                        $ausgaben[]=$aus;
                        $einnahmen[]=0;
                    }
                }
            }
            foreach($tmp_e as $bel => $ar){
                foreach($ar as $tit => $ein){
                    if($ein != 0){
                        $posten[]=$bel;
                        $titeln[]=$tit;
                        $einnahmen[]=$ein;
                        $ausgaben[]=0;
                    }
                }
            }

            $content['data']['betrag'] = array_sum($ausgaben)-array_sum($einnahmen);
            $content['data']['posten'] = implode(",",$posten);
            $content['data']['einnahmen'] = implode(",",$einnahmen);
            $content['data']['ausgaben'] = implode(",",$ausgaben);
            $content['data']['hhptitel'] = implode(",",$titeln);
            $content['data']['datumauszahlung'] = "";
            //var_dump($content['data']);

            // Code um POST zu senden
            $url = "https://box.stura.tu-ilmenau.de/FUI2PDF/index.php";
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER,array("Content-type: application/json"));
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($content));

            //execute Latex on external drive
            $ret = curl_exec($curl);

            //Dann lade sie runter
            header("Content-type: application/pdf");
            header("Content-disposition: inline; filename=Auslagenerstattung-".$antrag_id.".pdf");
            echo $ret;

            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            //lösche pdf auf remote system
            $ch = curl_init("https://box.stura.tu-ilmenau.de/FUI2PDF/index.php?del=1");
            curl_exec($ch);
            curl_close($ch);
            //*/ // <-- end debugging print
        }else{
            global $inlineCSS;
            $inlineCSS = true;
            require "../template/header-print.tpl";
            require "../template/antrag.head.tpl";
            require "../template/antrag.tpl";
            require "../template/antrag.foot-print.tpl";
            require "../template/footer-print.tpl";
        }
        exit;
    case "antrag.export":
        $antrag = getAntrag();
        if (!file_exists(SYSBASE.'/tmp')) { mkdir(SYSBASE.'/tmp'); }; # else tempnam fails
        $zipFileName = tempnam(SYSBASE.'/tmp', 'exp');
        if ($zipFileName === false) die("Out of space.");

        $zip = new ZipArchive();
        if ($zip->open( $zipFileName, ZIPARCHIVE::OVERWRITE ) === false) {
            goto deleteZip;
        }

        $antraghtml = antrag2html($antrag);
        $zip->addFromString('antrag.html', $antraghtml);

        $zip->addFromString('antrag.json', json_encode($antrag));

        foreach ($antrag["_anhang"] as $ah) {
            if (strtolower($ah["state"]) != "active") continue;
            $zip->addFile($STORAGE."/".$ah["antrag_id"]."/".$ah["path"], $ah["id"].".attach");
        }

        $ret = $zip->close();

        deleteZip:
        if ($ret === true) {
            header("Content-Type: application/zip");
            header("Content-Length: " . filesize($zipFileName));
            header("Content-Disposition: attachment; filename=\"{$antrag["id"]} {$antrag["type"]} {$antrag["revision"]}.zip\"");
            readfile($zipFileName);
        }
        unlink($zipFileName);
        if ($ret === false)
            die( "Failed to create ZIP" );
        else
            exit;
        break;
    case "antrag.exportBank":
        $antrag = getAntrag();
        $form = getForm($antrag["type"], $antrag["revision"]);
        if ($form === false) die("Unbekannter Formulartyp/-revision, kann nicht dargestellt werden.");

        $sepa_file = new SepaTransferFile();
        $sepa_file->messageIdentification = "StuRa-".$antrag["id"];
        $sepa_file->initiatingPartyName = "Studierendenrat der TU Ilmenau";
        $payment1 = $sepa_file->addPaymentInfo(array(
            'id'                     => "Stura-{$antrag["id"]}-pymnt",
            'debtorName'             => "Studierendenrat",
            #      'debtorAccountIBAN'      => "",
            #      'debtorAgentBIC'         => "NOTPROVIDED",
            'debtorAccountCurrency'  => 'EUR',
            'requestedExecutionDate' => date("Y-m-d"),
            'BtchBookg'              => false,
        ));

        $numTx = getFormValueInt("zahlung.table[rowCount]", null, $antrag["_inhalt"], 0);
        for ($rowNumber = 0; $rowNumber < $numTx; $rowNumber++) {

            $map = [
                "id" => [ "zahlung.beleg", "otherForm" ],
                "betrag" => [ "zahlung.ausgaben", "money" ],
                "empfname" => [ "zahlung.empfname", "text" ],
                "empfiban" => [ "zahlung.empfiban", "iban" ],
                "eref" => [ "zahlung.eref", "text" ],
                "vzw" => [ "zahlung.verwendungszweck", "textarea" ],
            ];

            foreach ($map as $reqFieldName => $dbFieldDesc) {
                $rowFieldName = "{$dbFieldDesc[0]}[{$rowNumber}]";
                $$reqFieldName = getFormValueInt($rowFieldName, $dbFieldDesc[1], $antrag["_inhalt"], "");
            }

            $empfiban = str_replace(" ", "", $empfiban);

            $payment1->addCreditTransfer(array(
                'id'                    => "StuRa-{$antrag["id"]}-$rowNumber-$id",
                'currency'              => 'EUR',
                'amount'                => (float) $betrag,
                'creditorBIC'           => "NOTPROVIDED",
                'creditorName'          => sanitizeName($empfname),
                'creditorAccountIBAN'   => $empfiban,
                'remittanceInformation' => sanitizeName($vzw),
            ));
        }

        $xmlout = $sepa_file->asXML();

        #    if (false === writeState("payed", $antrag, $form, $msgs, $filesCreated, $filesRemoved, $target))

        header("Content-Type: application/force-download");
        header('Content-Disposition: attachment; filename="' . $antrag["id"].'-sepa.xml"');
        header('Content-Transfer-Encoding: binary');
        echo $xmlout;

        exit(0);

        break;
}
prof_flag("Pre Header");
require "../template/header.tpl";
prof_flag("Header erstellt");
switch($_REQUEST["tab"]) {
    case "antrag.listing":
        $tmp = dbFetchAll("antrag", [], ["type" => true, "revision" => true, "lastupdated" => false]);
        $antraege = [];
        foreach ($tmp as $t) {
            $form = getForm($t["type"],$t["revision"]);
            if (false === $form) continue;
            $t["_inhalt"] = dbFetchAll("inhalt", ["antrag_id" => $t["id"] ]);
            if (!hasPermission($form, $t, "canRead")) continue;
            $antraege["all"][$t["type"]][$t["revision"]][$t["id"]] = $t;
            foreach (array_keys($form["_categories"]) as $cat) {
                if (!hasCategory($form, $t, $cat)) continue;
                $antraege[$cat][$t["type"]][$t["revision"]][$t["id"]] = $t;
            }
        }
        require "../template/antrag.createpanel.tpl";
        require "../template/antrag.list.tpl";
        break;
    case "antrag":
        $antrag = getAntrag();
        $form = getForm($antrag["type"],$antrag["revision"]);
        if ($form === false) die("Unbekannter Formulartyp/-revision, kann nicht dargestellt werden.");

        $tmp = dbFetchAll("inhalt", ["contenttype" => "otherForm", "value" => $antrag["id"]], ["antrag_id" => true]);
        $idx = [];
        $antraegeRef = [];
        foreach ($tmp as $t) {
            $antrag_id = $t["antrag_id"];

            if (isset($idx[$antrag_id])) continue;
            $idx[$antrag_id] = true;

            $a = dbGet("antrag", ["id" => $antrag_id]);
            $f = getForm($a["type"],$a["revision"]);
            if (false === $f) continue;

            $a["_inhalt"] = dbFetchAll("inhalt", ["antrag_id" => $a["id"] ]);
            if (!hasPermission($f, $a, "canRead")) continue;

            $antraegeRef[$a["type"]][$a["revision"]][$a["id"]] = $a;
        }

        require "../template/antrag.menu.tpl";
        require "../template/antrag.state.tpl";

        require "../template/antrag.subcreate.tpl";

        require "../template/antrag.copy.tpl";
        require "../template/antrag.tpl";
        require "../template/antrag.ref.tpl";
        require "../template/antrag.comments.tpl";
        break;
    case "antrag.edit":
        $antrag = getAntrag();
        $form = getForm($antrag["type"], $antrag["revision"]);
        if ($form === false) die("Unbekannter Formulartyp/-revision, kann nicht dargestellt werden.");
        if (!hasPermission($form, $antrag, "canEdit")) die("Antrag {$antrag["id"]} ist nicht editierbar");

        require "../template/antrag.head.tpl";
        require "../template/antrag.edit.tpl";
        break;
    case "antrag.editPartiell":
        $antrag = getAntrag();
        $form = getForm($antrag["type"], $antrag["revision"]);
        if ($form === false) die("Unbekannter Formulartyp/-revision, kann nicht dargestellt werden.");
        if (!hasPermission($form, $antrag, "canEditPartiell")) die("Antrag ist nicht partiell editierbar");

        require "../template/antrag.head.tpl";
        require "../template/antrag.editPartiell.tpl";
        break;
    case "antrag.create":
        if (!isset($_REQUEST["type"]) || !isset($_REQUEST["revision"])) {
            header("Location: $URIBASE");
            exit;
        }
        $form = getForm($_REQUEST["type"], $_REQUEST["revision"]);
        if ($form === false) die("Unbekannter Formulartyp oder keine Berechtigung");
        if (!hasPermission($form, null, "canCreate")) die("Antrag ist nicht erstellbar");

        require "../template/antrag.head.tpl";
        require "../template/antrag.create.tpl";
        break;
    case "booking":
        requireGroup($HIBISCUSGROUP);
        $alGrund = dbFetchAll("antrag", [ ] );
        foreach(array_keys($alGrund) as $i) {
            $antrag = $alGrund[$i]; unset($alGrund[$i]);
            $inhalt = dbFetchAll("inhalt", ["antrag_id" => $antrag["id"]]);
            $antrag["_inhalt"] = $inhalt;
            $form = getForm($antrag["type"], $antrag["revision"]);

            if (!hasPermission($form, $antrag, "canRead")) continue;
            if (!hasCategory($form, $antrag, "_need_booking_payment")) continue;

            $ctrl = ["_values" => $antrag, "render" => [ "no-form"] ];
            ob_start();
            $success = renderFormImpl($form, $ctrl);
            ob_end_clean();
            $inhalt_better = betterValues($inhalt, "fieldname","value");

            $inhalt = $inhalt_better;
            $value = 0.00;
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["ausgaben"])) {
                $value -= $ctrl["_render"]->addToSumValue["ausgaben"];
            }
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["einnahmen"])) {
                $value += $ctrl["_render"]->addToSumValue["einnahmen"];
            }
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["ausgaben.zahlung"])) {
                $value += $ctrl["_render"]->addToSumValue["ausgaben.zahlung"];
            }
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["einnahmen.zahlung"])) {
                $value -= $ctrl["_render"]->addToSumValue["einnahmen.zahlung"];
            }

            if($antrag['type'] == 'extern-express'){
                $value = -1;
                if($antrag['state'] == 'beschlossen'){
                    if($inhalt['geld.vorkasse'] > 0){
                        $value = -$inhalt['geld.vorkasse'];
                    }else continue;
                }
                if($antrag['state'] == 'abrechnung-ok'){
                    if($inhalt['geld.vorkasse'] > 0 && (!isset($inhalt['vorkasse.buchung']) || $inhalt['vorkasse.buchung'] == "")){

                        echo $value = $inhalt['geld.vorkasse'];
                    }else{
                        if($inhalt['geld.abgerechnet'] > 0 && (!isset($inhalt['abrechnung.buchung']) || $inhalt['abrechnung.buchung'] == "")){
                            $value = -$inhalt['geld.abgerechnet'];
                        }
                    }
                }
            }
            $antrag["_value"] = $value;
            $antrag["_ctrl"] = $ctrl;
            $antrag["_form"] = $form;
            $alGrund[$i] = $antrag;
        }
        $alZahlung = dbFetchAll("antrag", [ ] );
        foreach(array_keys($alZahlung) as $i) {
            $antrag = $alZahlung[$i]; unset($alZahlung[$i]);
            $inhalt = dbFetchAll("inhalt", ["antrag_id" => $antrag["id"]]);
            $antrag["_inhalt"] = $inhalt;
            $form = getForm($antrag["type"], $antrag["revision"]);

            if (!hasPermission($form, $antrag, "canRead")) continue;
            if (!hasCategory($form, $antrag, "_need_booking_reason")) continue;

            $ctrl = ["_values" => $antrag, "render" => [ "no-form"] ];
            ob_start();
            $success = renderFormImpl($form, $ctrl);
            ob_end_clean();

            $value = 0.00;
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["ausgaben"])) {
                $value -= $ctrl["_render"]->addToSumValue["ausgaben"];
            }
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["einnahmen"])) {
                $value += $ctrl["_render"]->addToSumValue["einnahmen"];
            }
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["ausgaben.beleg"])) {
                $value += $ctrl["_render"]->addToSumValue["ausgaben.beleg"];
            }
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["einnahmen.beleg"])) {
                $value -= $ctrl["_render"]->addToSumValue["einnahmen.beleg"];
            }



            $antrag["_value"] = $value;
            $antrag["_ctrl"] = $ctrl;
            $antrag["_form"] = $form;
            $alZahlung[$i] = $antrag;
        }
        usort($alGrund, function ($a, $b) {
            if ($a["_value"] < $b["_value"]) return -1;
            if ($a["_value"] > $b["_value"]) return 1;
            return 0;
        });
        usort($alZahlung, function ($a, $b) {
            if ($a["_value"] < $b["_value"]) return -1;
            if ($a["_value"] > $b["_value"]) return 1;
            return 0;
        });
        require "../template/booking.tpl";
        break;
    case "hibiscus.sct":
        $tmp = dbFetchAll("antrag", [], ["id" => false]);
        $antraege = [];
        foreach ($tmp as $t) {
            $form = getForm($t["type"],$t["revision"]);
            if (false === $form) continue;
            $t["_inhalt"] = dbFetchAll("inhalt", ["antrag_id" => $t["id"] ]);

            if (!hasPermission($form, $t, "canRead")) continue;
            if (!hasCategory($form, $t, "_export_sct")) continue;

            $ctrl = ["_values" => $t, "render" => [ "no-form"] ];
            ob_start();
            $success = renderFormImpl($form, $ctrl);
            ob_end_clean();

            $value = 0.00;
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["ausgaben"])) {
                $value += $ctrl["_render"]->addToSumValue["ausgaben"];
            }
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["einnahmen"])) {
                $value -= $ctrl["_render"]->addToSumValue["einnahmen"];
            }
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["ausgaben.zahlung"])) {
                $value -= $ctrl["_render"]->addToSumValue["ausgaben.zahlung"];
            }
            if (isset($ctrl["_render"]) && isset($ctrl["_render"]->addToSumValue["einnahmen.zahlung"])) {
                $value += $ctrl["_render"]->addToSumValue["einnahmen.zahlung"];
            }

            if ($value <= 0.0) continue; # keine Überweisung notwendig hier

            $destIBAN = getFormValueInt("iban", null, $t["_inhalt"], false);
            $destEmpfaenger = getFormValueInt("antragsteller.name", null, $t["_inhalt"], false);
            $destEmpfaengerMail = getFormValueInt("antragsteller.email", null, $t["_inhalt"], false);

            $t["_value"] = $value;
            $t["_iban"] = $destIBAN;
            $t["_empfname"] = $destEmpfaenger;
            $t["_ctrl"] = $ctrl;
            $t["_form"] = $form;

            $antraege[$t["id"]] = $t;
        }
        require "../template/hibiscus.sct.tpl";
        break;
    case "bookingHistory":
        echo "to be implemented";
        //require "../template/bookingHistory.tpl";
        break;
    default:
        echo "invalid tab name: ".htmlspecialchars($_REQUEST["tab"]);
}
prof_flag("Vor footer");
require "../template/footer.tpl";
prof_flag("Nach Footer");
prof_print();
exit;


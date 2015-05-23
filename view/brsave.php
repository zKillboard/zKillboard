<?php

$sID = $_GET["sID"];
$dttm = $_GET["dttm"];
$options = $_GET["options"];

$battleID = Db::queryField("select battleID from zz_battle_report where solarSystemID = :sID and dttm = :dttm and options = :options limit 1", "battleID", array(":sID" => $sID, ":dttm" => $dttm, ":options" => $options), 0);
if ($battleID === null) $battleID = Db::execute("insert into zz_battle_report (solarSystemID, dttm, options) values (:sID, :dttm, :options)", array(":sID" => $sID, ":dttm" => $dttm, ":options" => $options));
$battleID = Db::queryField("select battleID from zz_battle_report where solarSystemID = :sID and dttm = :dttm and options = :options limit 1", "battleID", array(":sID" => $sID, ":dttm" => $dttm, ":options" => $options), 0);

$app->redirect("/br/$battleID/", 302);

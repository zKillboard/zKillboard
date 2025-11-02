<?php

global $mdb;

$sID = $_GET['sID'] ?? null;
$dttm = $_GET['dttm'] ?? null;
$options = $_GET['options'] ?? null;

$battleID = $mdb->findField('battles', 'battleID', ['$and' => [['solarSystemID' => $sID], ['dttm' => $dttm], ['options' => $options]]], ['battleID' => -1]);
while ($battleID === null) {
    $battleID = $mdb->findField('battles', 'battleID', [], ['battleID' => -1]);
    ++$battleID;
    try {
        $mdb->insert('battles', ['battleID' => (int) $battleID]);
    } catch (Exception $ex) {
        $battleID = null;
        sleep(1);
    }
}
$battle = $mdb->findDoc('battles', ['battleID' => $battleID]);
$battle['solarSystemID'] = $sID;
$battle['dttm'] = $dttm;
$battle['options'] = $options;
$mdb->save('battles', $battle);

if (isset($GLOBALS['route_args'])) {
    header("Location: /br/$battleID/");
} else {
    $app->redirect("/br/$battleID/", 302);
}

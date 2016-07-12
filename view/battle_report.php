<?php

global $mdb;

$battleID = (int) $battleID;

$battle = $mdb->findDoc('battles', ['battleID' => $battleID]);
$battle['battleID'] = (int) $battle['battleID'];

if (!$mdb->exists('battles', ['battleID' => $battleID])) {
    $mdb->save('battles', $battle);
}

$system = @$battle['solarSystemID'];
$time = @$battle['dttm'];
$options = @$battle['options'];
$showBattleOptions = false;

global $baseDir;
require_once $baseDir.'/view/related.php';
die();

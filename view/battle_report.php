<?php

$battle = Db::queryRow('select * from zz_battle_report where battleID = :id', array(':id' => $battleID));

$system = @$battle['solarSystemID'];
$time = @$battle['dttm'];
$options = @$battle['options'];
$showBattleOptions = false;

global $baseDir;
require_once $baseDir.'/view/related.php';
die();

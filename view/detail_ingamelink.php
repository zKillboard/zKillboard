<?php

global $mdb;

$id = (int) $id;

$crest = $mdb->findDoc("crestmails", ['killID' => $id, 'processed' => true]);
$killdata = Kills::getKillDetails($id);

$app->render("components/ingamelink.html", ['crest' => $crest, 'killdata' => $killdata]);

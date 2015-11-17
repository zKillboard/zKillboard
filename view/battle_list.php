<?php

global $mdb;

$battles = $mdb->find("battles", [], ['battleID' => -1], 50);
Info::addInfo($battles);

$app->render('battles.html', ['battles' => $battles]);

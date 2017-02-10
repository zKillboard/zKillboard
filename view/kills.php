<?php

$kills = KillCategories::getKills($type, $page);
$app->render('kills.html', array('kills' => $kills, 'page' => $page, 'killsType' => $type, 'pager' => true));

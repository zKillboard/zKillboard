<?php

if ($page > 20) return $app->redirect("/kills/$type/page/20/", 302);

$kills = KillCategories::getKills($type, $page);
$app->render('kills.html', array('kills' => $kills, 'page' => $page, 'killsType' => $type, 'pager' => true));

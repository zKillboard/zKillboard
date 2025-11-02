<?php

global $mdb;

$id = (int) $id;

$crest = $mdb->findDoc("crestmails", ['killID' => $id, 'processed' => true]);
$killdata = Kills::getKillDetails($id);

if (isset($GLOBALS['route_args'])) {
    global $twig;
    $GLOBALS['capture_render_data'] = $twig->render("components/ingamelink.html", ['crest' => $crest, 'killdata' => $killdata]);
} else {
    $app->render("components/ingamelink.html", ['crest' => $crest, 'killdata' => $killdata]);
}

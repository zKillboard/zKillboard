<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $templates;

    $id = (int) $args['id'];

    $crest = $mdb->findDoc("crestmails", ['killID' => $id, 'processed' => true]);
    $killdata = Kills::getKillDetails($id);

    $data = ['crest' => $crest, 'killdata' => $killdata];
    return $container->get('view')->render($response->withHeader('Cache-Tag', "www,kill,kill:$id"), 'components/ingamelink.pug', $data);
}

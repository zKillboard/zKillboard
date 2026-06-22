<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $twig;

    $id = (int) $args['id'];

    $crest = $mdb->findDoc("crestmails", ['killID' => $id, 'processed' => true]);
    $killdata = Kills::getKillDetails($id);

    $data = ['crest' => $crest, 'killdata' => $killdata];
    return $container->get('view')->render($response->withHeader('Cache-Tag', "kill,kill:$id"), 'components/ingamelink.html', $data);
}

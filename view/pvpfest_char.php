<?php

function pvpfestHandler($request, $response, $args, $container) {
    global $mdb, $redis;


    // Extract parameters (comes from overview.php)
    $inputString = $args['input'] ?? '';
    $input = explode('/', trim($inputString, '/'));

    $key = $input[0]; // character, corporation, or alliance
    $id = (int) $input[1];

    return $response
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withHeader('Cache-Tag', "pvpfest:$id,pvpfest,overview")
        ->withHeader('Cache-Control', 'public, max-age=1, s-maxage=1')
        ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + 1) . ' GMT');

}

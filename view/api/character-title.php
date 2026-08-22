<?php

function handler($request, $response, $args, $container) {
    global $mdb;

    $id = strtolower((string) ($args['id'] ?? ''));
    $response = $response
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withHeader('Cache-Control', 'public, max-age=86400')
        ->withHeader('Cache-Tag', 'www,api,character-title');

    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
        $response->getBody()->write(json_encode(['error' => 'Character title not found']));
        return $response->withStatus(404);
    }

    $title = $mdb->findDoc('sde_characterTitles', ['key' => $id], [], ['name' => 1]);
    $name = $title['name'] ?? '';
    if (is_array($name)) $name = (string) ($name['en'] ?? reset($name));

    if ($name == '') {
        $response->getBody()->write(json_encode(['error' => 'Character title not found']));
        return $response->withStatus(404);
    }

    $response->getBody()->write(json_encode(['id' => $id, 'name' => $name]));
    return $response;
}

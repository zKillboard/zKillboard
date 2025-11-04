<?php

use MongoDB\BSON\ObjectId;

function handler($request, $response, $args, $container) {
    global $mdb;

    $id = $args['id'] ?? '';

    try {
        $record = $mdb->findDoc("shortener", ['_id' => new ObjectId($id)]);
        if ($record == null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        } else {
            return $response->withHeader('Location', $record['url'])->withStatus(302);
        }
    } catch (Exception $e) {
        // Invalid ObjectId format, redirect to home
        return $response->withHeader('Location', '/')->withStatus(302);
    }
}

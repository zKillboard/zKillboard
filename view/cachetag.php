<?php

function handler($request, $response, $args, $container) {
    global $redis;

    $cacheTag = 'www,cachetag';
    $json = function ($payload, $status = 200) use ($response, $cacheTag) {
        $response->getBody()->write(json_encode($payload));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('Cache-Tag', $cacheTag);
    };

    $tag = trim((string) ($args['tag'] ?? ''));
    if ($tag == '' || !preg_match('/^[A-Za-z0-9:_-]+$/', $tag)) {
        return $json(['success' => false, 'message' => 'Invalid cache tag.'], 400);
    }

    return $json(['success' => true, 'pending' => (bool) $redis->sismember('queueCacheTags', $tag), 'tag' => $tag]);
}

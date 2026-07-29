<?php

function handler($request, $response, $args, $container) {
    $killID = (int) ($args['killID'] ?? 0);
    if ($killID <= 0) return $response->withStatus(404);

    $kills = Kills::getDetails([$killID], true);
    if (empty($kills)) return $response->withStatus(404);

    foreach ($kills as &$kill) {
        $vics = [];
        foreach (['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'groupID', 'factionID'] as $key) {
            if (isset($kill['victim'][$key])) $vics[] = $kill['victim'][$key];
        }
        $kill['vics'] = implode(',', $vics);
        if (isset($kill['dttm'])) $kill['unixtime'] = $kill['dttm']->toDateTime()->getTimestamp();
    }
    unset($kill);

    $html = $container->get('view')->getEnvironment()->render('components/kill_list_row_v2.pug', ['killList' => array_values($kills)]);
    $cacheTime = ($args['cacheType'] ?? '') == '24hour' ? 86400 : 3600;
    $cacheControl = "public, max-age=$cacheTime, s-maxage=$cacheTime";
    $response->getBody()->write($html);
    return $response
        ->withHeader('Content-Type', 'text/html; charset=utf-8')
        ->withHeader('Cache-Control', $cacheControl)
        ->withHeader('CDN-Cache-Control', $cacheControl)
        ->withHeader('Cloudflare-CDN-Cache-Control', $cacheControl)
        ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $cacheTime) . ' GMT')
        ->withHeader('Cache-Tag', "www,killrow,kill:$killID");
}

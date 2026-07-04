<?php

function handler($request, $response, $args, $container) {
    $wars = War::getWarsPageTables();

    $cacheControl = 'public, max-age=3600, s-maxage=3600';
    return $container->get('view')->render(
        $response
            ->withHeader('Cache-Control', $cacheControl)
            ->withHeader('CDN-Cache-Control', $cacheControl)
            ->withHeader('Cloudflare-CDN-Cache-Control', $cacheControl)
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT')
            ->withHeader('Cache-Tag', 'www,wars'),
        'wars.pug',
        array('warTables' => $wars)
    );
}

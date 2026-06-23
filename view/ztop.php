<?php

function handler($request, $response, $args, $container) {
    return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,ztop'), "ztop.pug", ['showAds' => false]);
}

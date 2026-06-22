<?php

function handler($request, $response, $args, $container) {
    return $container->get('view')->render($response->withHeader('Cache-Tag', 'ztop'), "ztop.pug", ['showAds' => false]);
}

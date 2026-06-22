<?php

function handler($request, $response, $args, $container) {
    $mobile = false;
    $response->getBody()->write(Google::getAd());
    return $response->withHeader('Cache-Tag', 'ads,google');
}

<?php

function handler($request, $response, $args, $container) {
    return $container->get('view')->render($response, "ztop.html", ['showAds' => false]);
}
<?php

function handler($request, $response, $args, $container) {
    global $redis, $templates;

    return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,scanalyzer'), 'scanalyzer.pug', []);
}

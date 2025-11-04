<?php

function handler($request, $response, $args, $container) {
    global $redis, $twig;

    return $container->get('view')->render($response, 'scanalyzer.html', []);
}

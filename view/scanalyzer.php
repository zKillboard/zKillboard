<?php

function handler($request, $response, $args, $container) {
    global $redis, $twig;

    return $container->view->render($response, 'scanalyzer.html', []);
}

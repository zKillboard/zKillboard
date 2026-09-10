<?php

function handler($request, $response, $args, $container)
{
    return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,simulate'), 'simulate.pug', [
        'fittingWheelSVG' => file_get_contents(__DIR__ . '/../public/img/panel/simulate-wheel.svg'),
    ]);
}

<?php

function handler($request, $response, $args, $container)
{
    $fittingWheelSVG = file_get_contents(__DIR__ . '/../public/img/panel/simulate-wheel.svg');
    return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,simulate'), 'simulate.pug', [
        'fittingWheelSVG' => $fittingWheelSVG,
        'implantWheelSVG' => preg_replace('/id="([^"]+)"/', 'id="implant-$1"', str_replace('aria-label="Ship fitting wheel"', 'aria-label="Capsule implant fitting wheel"', $fittingWheelSVG)),
    ]);
}

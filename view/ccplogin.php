<?php

function handler($request, $response, $args, $container) {
    return $response->withStatus(302)->withHeader('Location', '/ccpoauth2/');
}
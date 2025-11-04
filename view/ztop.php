<?php

function handler($request, $response, $args, $container) {
    return $container->view->render($response, "ztop.html", ['showAds' => false]);
}
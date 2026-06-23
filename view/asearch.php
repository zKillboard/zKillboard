<?php

function handler($request, $response, $args, $container) {
	return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,asearch'), 'asearch.pug', ['labels' => AdvancedSearch::$labels]);
}

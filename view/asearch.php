<?php

function handler($request, $response, $args, $container) {
	return $container->get('view')->render($response->withHeader('Cache-Tag', 'asearch'), 'asearch.pug', ['labels' => AdvancedSearch::$labels]);
}

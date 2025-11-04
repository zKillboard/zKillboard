<?php

function handler($request, $response, $args, $container) {
	return $container->get('view')->render($response, 'asearch.html', ['labels' => AdvancedSearch::$labels]);
}

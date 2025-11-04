<?php

function handler($request, $response, $args, $container) {
	return $container->view->render($response, 'asearch.html', ['labels' => AdvancedSearch::$labels]);
}

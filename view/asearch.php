<?php

// Handle rendering for Slim 3 compatibility
if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['render_template'] = 'asearch.html';
	$GLOBALS['render_data'] = ['labels' => AdvancedSearch::$labels];
} else {
	// Fallback for any remaining Slim 2 usage
	$app->render('asearch.html', ['labels' => AdvancedSearch::$labels]);
}

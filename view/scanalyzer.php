<?php

global $redis;

// Handle rendering for Slim 3 compatibility
if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['render_template'] = 'scanalyzer.html';
	$GLOBALS['render_data'] = [];
} else {
	// Fallback for any remaining Slim 2 usage
	$app->render('scanalyzer.html', []);
}

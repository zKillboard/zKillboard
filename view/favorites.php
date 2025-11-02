<?php

global $mdb;

if (!User::isLoggedIn()) {
    $kills = [];
} else {
    $userID = (int) User::getUserID();

    $killIDs = $mdb->find("favorites", ['characterID' => (int) $userID], ['killID' => -1]);
    $kills = Kills::getDetails($killIDs);
}

// Handle rendering for Slim 3 compatibility
if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['render_template'] = 'favorites.html';
	$GLOBALS['render_data'] = ['kills' => $kills];
} else {
	// Fallback for any remaining Slim 2 usage
	$app->render("favorites.html", ['kills' => $kills]);
}

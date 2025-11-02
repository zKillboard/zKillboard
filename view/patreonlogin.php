<?php

use Patreon\API;
use Patreon\OAuth;

global $patreon_client_id, $patreon_client_secret, $patreon_redirect_uri;

// Make sure the user is logged in
$userID = User::getUserID();
if ($userID == 0) {
    // User not logged in
    $_SESSION['patreon'] = true;
    if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
        $GLOBALS['redirect_url'] = '/ccplogin/';
        $GLOBALS['redirect_status'] = 302;
        return;
    } else {
        $app->redirect('/ccplogin/');
        return;
    }
}

$factory = new \RandomLib\Factory;
$generator = $factory->getGenerator(new \SecurityLib\Strength(\SecurityLib\Strength::MEDIUM));
$state = $generator->generateString(128, "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");
$_SESSION['oauth2State'] = $state;

$min_cents = 100;
$scope_parameters = '&scope=identity%20identity'.urlencode('[email]');
$href = 'https://www.patreon.com/oauth2/become-patron?response_type=code&min_cents=' . $min_cents . '&client_id=' . $patreon_client_id . $scope_parameters . '&redirect_uri=' . $patreon_redirect_uri . "&state=" . urlencode($state);

if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['redirect_url'] = $href;
	$GLOBALS['redirect_status'] = 302;
} else {
	$app->redirect($href, 302);
}

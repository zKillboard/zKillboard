<?php

use Patreon\API;
use Patreon\OAuth;

function handler($request, $response, $args, $container) {
    global $patreon_client_id, $patreon_client_secret, $patreon_redirect_uri;

    // Make sure the user is logged in
    $userID = User::getUserID();
    if ($userID == 0) {
        // User not logged in
        $_SESSION['patreon'] = true;
        return $response->withStatus(302)->withHeader('Location', '/ccplogin/');
    }

    $factory = new \RandomLib\Factory;
    $generator = $factory->getGenerator(new \SecurityLib\Strength(\SecurityLib\Strength::MEDIUM));
    $state = $generator->generateString(128, "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");
    $_SESSION['oauth2State'] = $state;

    $min_cents = 100;
    $scope_parameters = '&scope=identity%20identity'.urlencode('[email]');
    $href = 'https://www.patreon.com/oauth2/become-patron?response_type=code&min_cents=' . $min_cents . '&client_id=' . $patreon_client_id . $scope_parameters . '&redirect_uri=' . $patreon_redirect_uri . "&state=" . urlencode($state);

    return $response->withStatus(302)->withHeader('Location', $href);
}

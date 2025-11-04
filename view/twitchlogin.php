<?php

function handler($request, $response, $args, $container) {
    global $twitch_client_id, $twitch_client_secret, $twitch_redirect_uri;

    // Make sure the user is logged in
    $userID = User::getUserID();
    if ($userID == 0) {
        // User not logged in
        $_SESSION['twitch'] = true;
        return $response->withStatus(302)->withHeader('Location', '/ccplogin/');
    }

    $factory = new \RandomLib\Factory;
    $generator = $factory->getGenerator(new \SecurityLib\Strength(\SecurityLib\Strength::MEDIUM));
    $state = $generator->generateString(128, "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");
    $_SESSION['oauth2State'] = $state;

    $scope_parameters = '&scope='.urlencode('user:read:subscriptions');
    $href = "https://id.twitch.tv/oauth2/authorize?response_type=code&force_verify=true&client_id=$twitch_client_id&redirect_uri=$twitch_redirect_uri&state=" . urlencode($state) . $scope_parameters;

    return $response->withStatus(302)->withHeader('Location', $href);
}

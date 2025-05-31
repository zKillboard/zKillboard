<?php

use Patreon\API;
use Patreon\OAuth;

try {
    global $mdb, $patreon_client_id, $patreon_client_secret, $patreon_redirect_uri;

    $userID = User::getUserID();
    if ($userID == 0) {
        // User not logged in
        $app->redirect('/');
        return;
    }

    $state = str_replace("/", "", @$_GET['state']);
    $sessionState = @$_SESSION['oauth2State'];
    if ($state !== $sessionState) {
        return $app->render("error.html", ['message' => "Something went wrong with security. Please try again."]);
    }


    if ( $_GET['code'] != '' ) {
        $oauth_client = new OAuth($patreon_client_id, $patreon_client_secret);  

        $tokens = $oauth_client->get_tokens($_GET['code'], $patreon_redirect_uri);

        $access_token = $tokens['access_token'];
        $refresh_token = $tokens['refresh_token'];

        $api_client = new API($access_token);

        // Now get the current user:
        $patron_response = $api_client->fetch_user();
        $patronID = (int) $patron_response['data']['id'];

        // Save that user and the fact they've verified \o/
        $mdb->remove("patreon", ['patreon_id' => $patronID]);
        $mdb->remove("patreon", ['character_id' => $userID]);
        $mdb->insert("patreon", ['patreon_id' => $patronID, 'character_id' => $userID, 'expires' => $mdb->now(30 * 86400)]);

        ZLog::add("You have linked Patreon. Thank you!!", $userID);
        User::sendMessage("You have linked Patreon. Thank you!!", $userID);
        Util::zout("Character $userID has linked Patreon! ($patronID)");
        $app->redirect("/account/log/");
    }
} catch (Exception $ex) {
throw $ex;
        return $app->render("error.html", ['message' => "Something went wrong with the login from Patreon's end, sorry, can you please try logging in again? *"]);
}

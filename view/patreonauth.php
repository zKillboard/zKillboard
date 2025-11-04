<?php

use Patreon\API;
use Patreon\OAuth;

function handler($request, $response, $args, $container) {
    global $mdb, $patreon_client_id, $patreon_client_secret, $patreon_redirect_uri;

    try {
        $userID = User::getUserID();
        if ($userID == 0) {
            // User not logged in
            return $response->withStatus(302)->withHeader('Location', '/');
        }

        $queryParams = $request->getQueryParams();
        $state = str_replace("/", "", @$queryParams['state']);
        $sessionState = @$_SESSION['oauth2State'];
        if ($state !== $sessionState) {
            return $container->get('view')->render($response, "error.html", ['message' => "Something went wrong with security. Please try again."]);
        }

        if ( @$queryParams['code'] != '' ) {
            $oauth_client = new OAuth($patreon_client_id, $patreon_client_secret);  

            $tokens = $oauth_client->get_tokens($queryParams['code'], $patreon_redirect_uri);

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
            return $response->withStatus(302)->withHeader('Location', "/account/log/");
        }
        
        return $response;
    } catch (Exception $ex) {
        return $container->get('view')->render($response, "error.html", ['message' => "Something went wrong with the login from Patreon's end, sorry, can you please try logging in again? *"]);
    }
}

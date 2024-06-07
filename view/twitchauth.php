<?php

use Patreon\API;
use Patreon\OAuth;

try {
    global $mdb, $twitch_client_id, $twitch_client_secret, $twitch_redirect_uri;

    $userID = User::getUserID();
    if ($userID == 0) {
        // User not logged in
        $app->redirect('/');
        return;
    }

    $state = str_replace("/", "", @$_GET['state']);
    $sessionState = @$_SESSION['oauth2State'];
    $_SESSION['oauth2State'] = '';
    if ($state !== $sessionState) {
        $app->render("error.html", ['message' => "Something went wrong with security. Please try again."]);
        exit();
    }
    $mdb->remove("twitch", ['character_id' => $userID]);

    if ( $_GET['code'] != '' ) {
        $code = $_GET['code'];

        $helixGuzzleClient = new \TwitchApi\HelixGuzzleClient($twitch_client_id);
        $twitchApi = new \TwitchApi\TwitchApi($helixGuzzleClient, $twitch_client_id, $twitch_client_secret);
        $oauth = $twitchApi->getOauthApi();

        $token = $oauth->getUserAccessToken($code, $twitch_redirect_uri);
        if ($token->getStatusCode() == 200) {
            // Below is the returned token data
            $data = json_decode($token->getBody()->getContents(), true);

            $access_token = $data['access_token'];
            $response = $twitchApi->getUsersApi()->getUserByAccessToken($access_token);

            $user = json_decode($response->getBody()->getContents(), true)['data'][0];
            $twitch_id = $user['id'];
            $mdb->remove("twitch", ['twitch_id' => $twitch_id]);

            $response = $twitchApi->getSubscriptionsApi()->checkUserSubscription($access_token, "42847657", $twitch_id);
            $subData = json_decode($response->getBody()->getContents(), true)['data'][0];
            $subbed = (@$subData['tier'] > 0);
            if ($subbed) {
                $mdb->insert("twitch", ['character_id' => $userID, 'twitch_id' => $twitch_id, 'expires' => $mdb->now(30 * 86400)]);

                ZLog::add("You have linked Twitch. Twitch ad free integration has succeeded. Thank you!!", $userID);
                User::sendMessage("You have linked Twitch. Twitch ad free integration has succeeded. Thank you!!", $userID);
                return $app->redirect("/account/log/");
            } else {
                return $app->render("error.html", ['message' => "You are NOT subbed to SquizzCaphinator on Twitch. Twitch ad free integration has failed."]);
            }
        } else {
            return $app->render("error.html", ['message' => "Something went wrong while trying to validate your Twitch subscription. Please try again."]);
        }
    }
} catch (Exception $ex) {
    Log::log("Error from twitch:\n" . print_r($ex, true));
    return $app->render("error.html", ['message' => "Something went wrong with the login from Twitch's end, sorry, can you please try logging in again? *"]);
}

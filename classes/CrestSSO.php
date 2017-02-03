<?php

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisTtlCounter;

// Borrowed very heavily from FuzzySteve <3 https://github.com/fuzzysteve/eve-sso-auth/
class CrestSSO
{
    public static $userAgent = 'zKillboard.com CREST SSO';

    // Redirect user to CREST login
    public static function login()
    {
        global $app, $redis, $ccpClientID;
        // https://sisilogin.testeveonline.com/ https://login.eveonline.com/

        $referrer = @$_SERVER['HTTP_REFERER'];
        if ($referrer == '') {
            $referrer = '/';
        }

        $charID = @$_SESSION['characterID'];
        $hash = @$_SESSION['characterHash'];

        if ($charID != null && $hash != null) {
            $value = $redis->get("login:$charID:$hash");
            if ($value == true) {
                $app->redirect($referrer, 302);
                exit();
            }
        }

        $factory = new \RandomLib\Factory;
        $generator = $factory->getGenerator(new \SecurityLib\Strength(\SecurityLib\Strength::MEDIUM));
        $state = $generator->generateString(128, "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");
        $_SESSION['oauth2State'] = $state;

        $scopes = 'publicData';
        $requestedScopes = isset($_GET['scopes']) ? $_GET['scopes'] : [];
        if (in_array('esi-killmails.read_killmails.v1', $requestedScopes)) {
            $requestedScopes[] = 'corporationKillsRead';
        }

        if (count($requestedScopes) > 0) {
            $scopes .= '+'.implode('+', $requestedScopes);
        }
        $url = "https://login.eveonline.com/oauth/authorize/?response_type=code&redirect_uri=https://zkillboard.com/ccpcallback/&client_id=$ccpClientID&scope=$scopes&state=$state";
        $app->redirect($url, 302);
        exit();
    }

    public static function callback()
    {
        global $mdb, $app, $redis, $ccpClientID, $ccpSecret;

        $authSuccess = new RedisTtlCounter('ttlc:AuthSuccess', 300);
        $authFailure = new RedisTtlCounter('ttlc:AuthFailure', 300);

        try {
            $charID = @$_SESSION['characterID'];
            $hash = @$_SESSION['characterHash'];

            if ($charID != null && $hash != null) {
                $value = $redis->get("login:$charID:$hash");
                if ($value == true) {
                    $app->redirect('/', 302);
                    exit();
                }
            }

            $state = str_replace("/", "", @$_GET['state']);
            $sessionState = @$_SESSION['oauth2State'];
            if ($state !== $sessionState) {
                $app->render("error.html", ['message' => "Something went wrong with the login from CCP's end, sorry, can you please try logging in again?"]);
                exit();
            }

            $url = 'https://login.eveonline.com/oauth/token';
            $verify_url = 'https://login.eveonline.com/oauth/verify';
            $header = 'Authorization: Basic '.base64_encode($ccpClientID.':'.$ccpSecret);
            $fields_string = '';
            $fields = array(
                    'grant_type' => 'authorization_code',
                    'code' => $_GET['code'],
                    );
            foreach ($fields as $key => $value) {
                $fields_string .= $key.'='.$value.'&';
            }
            rtrim($fields_string, '&');
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array($header));
            curl_setopt($ch, CURLOPT_POST, count($fields));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $result = curl_exec($ch);

            if ($result === false) {
                auth_error(curl_error($ch));
            }
            curl_close($ch);
            $response = json_decode($result, true);

            if (isset($response['error'])) {
                $app->render("error.html", ['message' => "Something went wrong with the login from CCP's end, sorry, can you please try logging in again?"]);
                exit();
            }

            $access_token = $response['access_token'];
            $refresh_token = $response['refresh_token'];
            $ch = curl_init();
            // Get the Character details from SSO
            $header = 'Authorization: Bearer '.$access_token;
            curl_setopt($ch, CURLOPT_URL, $verify_url);
            curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array($header));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $result = curl_exec($ch);
            if ($result === false) {
                auth_error(curl_error($ch));
            }
            curl_close($ch);
            $response = json_decode($result);
            if (!isset($response->CharacterID)) {
                auth_error('No character ID returned');
            }
            if (strpos(@$response->Scopes, 'publicData') === false) {
                auth_error('Expected at least publicData scope but did not get it.');
            }
            $charID = (int) $response->CharacterID;
            $scopes = split(' ', (string) @$response->Scopes);
            foreach ($scopes as $scope) {
                if ($scope == "publicData") continue;
                if ($mdb->count("scopes", ['characterID' => $charID, 'scope' => $scope]) == 0) {
                    $mdb->save("scopes", ['characterID' => $charID, 'scope' => $scope, 'refreshToken' => $refresh_token]);
                } 
            }

            // Lookup the character details in the DB.
            $userdetails = $mdb->findDoc('information', ['type' => 'characterID', 'id' => $charID]);
            if (!isset($userdetails['name'])) {
                if ($userdetails == null) {
                    $mdb->save('information', ['type' => 'characterID', 'id' => $charID, 'name' => $response->CharacterName]);
                }
            }

            ZLog::add("Logged in: " . (isset($userdetails['name']) ? $userdetails['name'] : $charID), $charID, true);

            $key = "login:$charID:" . session_id();
            $redis->setex("$key:refreshToken", (86400 * 14), $refresh_token);
            $redis->setex("$key:accessToken", 1000, $access_token);
            $redis->setex("$key:scopes", (86400 * 14), @$response->Scopes);
            $scopes = explode(' ', @$response->Scopes);

            if (in_array('esi-killmails.read_killmails.v1', $scopes)) {
                $esi = new RedisTimeQueue('tqApiESI', 3600);
                $esi->add($charID);
            }

            $_SESSION['characterID'] = $charID;
            $_SESSION['characterName'] = $response->CharacterName;
            session_write_close();

            $redirect = '/';
            $sessID = session_id();
            $forward = $redis->get("forward:$sessID");
            $redis->del("forward:$sessID");
            if ($forward !== null) {
                $redirect = $forward;
            }
            header('Location: '.$redirect, 302);
            $authSuccess->add(uniqid());
            exit();
        } catch (Exception $ex) {
            $app->render("error.html", ['message' => "An unexpected error has happened, it has been logged and will be checked into. Please try to log in again."]);
            Log::log(print_r($ex, true));
            $authFailure->add(uniqid());
            exit();
        }
    }

    public static function getAccessToken($charID = null, $sessionID = null, $refreshToken = null)
    {
        global $app, $redis, $ccpClientID, $ccpSecret;

        $authSuccess = new RedisTtlCounter('ttlc:AuthSuccess', 300);
        $authFailure = new RedisTtlCounter('ttlc:AuthFailure', 300);

        if ($charID === null) {
            $charID = User::getUserID();
        }
        if ($sessionID === null) {
            $sessionID = session_id();
        }
        if ($refreshToken === null) {
            $refreshToken = $redis->get("login:$charID:$sessionID:refreshToken");
        }

        $key = "login:$charID:$sessionID:$refreshToken";
        $accessToken = $redis->get("$key:accessToken");

        if ($accessToken != null) {
            return $accessToken;
        }

        if ($refreshToken == null) {
            $refreshToken = $redis->get("$key:refreshToken");
        }
        if ($charID  == null || $refreshToken == null) {
            Util::out("No refreshToken for $charID with key $key");

            return $app !== null ? $app->redirect('/ccplogin/', 302) : null;
        }
        $redis->setex("$key:refreshToken", (86400 * 14), $refreshToken); // Reset the timer on the refreshToken
        $fields = array('grant_type' => 'refresh_token', 'refresh_token' => $refreshToken);

        $url = 'https://login.eveonline.com/oauth/token';
        $header = 'Authorization: Basic '.base64_encode($ccpClientID.':'.$ccpSecret);
        $fields_string = '';
        foreach ($fields as $arrKey => $value) {
            $fields_string .= $arrKey.'='.$value.'&';
        }
        $fields_string = rtrim($fields_string, '&');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array($header));
        curl_setopt($ch, CURLOPT_POST, count($fields));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result = json_decode($raw, true);
        $accessToken = @$result['access_token'];
        if ($accessToken != null) {
            $redis->setex("$key:accessToken", 1000, $accessToken);
        } else {
            if (isset($result['error'])) {
                return $result;
            }

            $authFailure->add(uniqid());
            return $httpCode;
        }

        $authSuccess->add(uniqid());
        return $accessToken;
    }

    public static function crestGet($url, $accessToken = null)
    {
        $accessToken = $accessToken == null ? self::getAccessToken() : $accessToken;
        $authHeader = "Authorization: Bearer $accessToken";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$url?access_token=$accessToken");
        curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array($authHeader));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $result = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($result, true);

        return $json;
    }

    public static function crestPost($url, $fields, $accessToken = null)
    {
        $accessToken = $accessToken == null ? self::getAccessToken() : $accessToken;
        $authHeader = "Authorization: Bearer $accessToken";
        $data = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$url");
        curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array($authHeader, 'Content-Type:application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($result, true);
        $json['httpCode'] = $httpCode;

        return $json;
    }
}

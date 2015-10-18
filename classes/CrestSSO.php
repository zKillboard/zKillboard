<?php

// Borrowed very heavily from FuzzySteve <3 https://github.com/fuzzysteve/eve-sso-auth/
class CrestSSO
{
	// Redirect user to CREST login
	public static function login()
	{
		global $app, $redis, $ccpClientID, $ccpCallback;
		// https://sisilogin.testeveonline.com/ https://login.eveonline.com/

		$referrer = @$_SERVER['HTTP_REFERER'] ;
		if ($referrer == '') $referrer = '/';

		$charID = @$_SESSION['characterID'];
		$hash = @$_SESSION['characterHash'];

		if ($charID != null && $hash != null) {
			$value = $redis->get("login:$charID:$hash");
			if ($value == true) {
				$app->redirect($referrer, 302);
				exit();
			}
		}

		$url = "https://sisilogin.testeveonline.com/oauth/authorize/?response_type=code&redirect_uri=https://zkillboard.com/ccpcallback/&client_id=$ccpClientID&scope=&state=redirect:$referrer";
		$app->redirect($url, 302);
		exit();
	}

	public static function callback()
	{
		global $mdb, $app, $redis, $ccpClientID, $ccpSecret;

                $charID = @$_SESSION['characterID'];
                $hash = @$_SESSION['characterHash'];

                if ($charID != null && $hash != null) {
                        $value = $redis->get("login:$charID:$hash");
                        if ($value == true) {
                                $app->redirect('/', 302);
                                exit();
                        }
                }

		$useragent = 'zkillboard sso';

		$url = 'https://sisilogin.testeveonline.com/oauth/token';
		$verify_url = 'https://sisilogin.testeveonline.com/oauth/verify';
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
		curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
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
		$response = json_decode($result);
		$auth_token = $response->access_token;
		$ch = curl_init();
		// Get the Character details from SSO
		$header = 'Authorization: Bearer '.$auth_token;
		curl_setopt($ch, CURLOPT_URL, $verify_url);
		curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
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
		// Lookup the character details in the DB.
		$userdetails = $mdb->findDoc('information', ['type' => 'characterID', 'id' => (int) $response->CharacterID]);

		$time = strtotime($response->ExpiresOn);
		$expires = $time - time();
		$key = "login:" . $response->CharacterID . ":" . $response->CharacterOwnerHash;
		$redis->setex($key, $time, true);

		$_SESSION['characterID'] = $response->CharacterID;
		$_SESSION['characterName'] = $response->CharacterName;
		$_SESSION['characterHash'] = $response->CharacterOwnerHash;
		$_SESSION['SSO-Expires'] = $response->ExpiresOn;
		session_write_close();

		$redirect = @$_GET['state'];
		if ($redirect == '') $redirect = '/';
		else if (substr($redirect, 0, 9) == 'redirect:') $redirect = '/';
		header('Location: ' . $redirect, 302);

		exit();
	}
}

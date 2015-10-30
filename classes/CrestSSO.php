<?php

// Borrowed very heavily from FuzzySteve <3 https://github.com/fuzzysteve/eve-sso-auth/
class CrestSSO
{
	public static $userAgent = "zKillboard.com CREST SSO";

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

		$url = "https://sisilogin.testeveonline.com/oauth/authorize/?response_type=code&redirect_uri=https://zkillboard.com/ccpcallback/&client_id=$ccpClientID&scope=characterFittingsWrite&state=redirect:$referrer";
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
		curl_setopt($ch, CURLOPT_USERAGENT, CrestSSO::$userAgent);
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
		$access_token = $response->access_token;
		$refresh_token = $response->refresh_token;
		$ch = curl_init();
		// Get the Character details from SSO
		$header = 'Authorization: Bearer '.$access_token;
		curl_setopt($ch, CURLOPT_URL, $verify_url);
		curl_setopt($ch, CURLOPT_USERAGENT, CrestSSO::$userAgent);
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
		while (!isset($userdetails['name'])) {
			if ($userdetails == null) $mdb->save('information', ['type' => 'characterID', 'id' => (int) $response->CharacterID]);
			sleep(1);
			$userdetails = $mdb->findDoc('information', ['type' => 'characterID', 'id' => (int) $response->CharacterID]);
		}

		$time = strtotime($response->ExpiresOn);
		$key = "login:" . $response->CharacterID . ":" . session_id();
		$redis->setex("$key:refreshToken", (86400 * 14), $refresh_token);
		$redis->setex("$key:accessToken", 1000, $access_token);

		$_SESSION['characterID'] = $response->CharacterID;
		$_SESSION['characterName'] = $response->CharacterName;
		session_write_close();

		$redirect = @$_GET['state'];
		if ($redirect == '') $redirect = '/';
		else if (substr($redirect, 0, 9) == 'redirect:') $redirect = '/';
		header('Location: ' . $redirect, 302);

		exit();
	}

	public static function getAccessToken() {
		global $app, $redis, $ccpClientID, $ccpSecret;

		$charID = @$_SESSION['characterID'];

		$key = "login:" . $charID . ":" . session_id();
		$accessToken = $redis->get("$key:accessToken");

		if ($accessToken != null) return $accessToken;

		$refreshToken = $redis->get("$key:refreshToken");
		if ($charID  == null || $refreshToken == null) {
			$app->redirect("/ccplogin/", 302);
			exit();
		}
		$redis->setex("$key:refreshToken", (86400 * 14), $refreshToken); // Reset the timer on the refreshToken
		$header = array( 'Authorization' => 'Basic '.base64_encode($ccpClientID.':'.$ccpSecret));
		$fields = array('grant_type' => 'refresh_token','refresh_token' => $refreshToken);

		$url = 'https://sisilogin.testeveonline.com/oauth/token';
		$verify_url = 'https://sisilogin.testeveonline.com/oauth/verify';
		$header = 'Authorization: Basic '.base64_encode($ccpClientID.':'.$ccpSecret);
		$fields_string = '';
		foreach ($fields as $arrKey => $value) {
			$fields_string .= $arrKey.'='.$value.'&';
		}
		rtrim($fields_string, '&');
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_USERAGENT, CrestSSO::$userAgent);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($header));
		curl_setopt($ch, CURLOPT_POST, count($fields));
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		$result = curl_exec($ch);
		$result = json_decode($result, true);
		$accessToken = $result['access_token'];
		$redis->setex("$key:accessToken", 1000, $accessToken);

		return $accessToken;
	}

	public static function crestGet($url) {
		global $ccpClientID, $ccpSecret;
	
		$accessToken = CrestSSO::getAccessToken();
		$authHeader = "Authorization: Bearer $accessToken";

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "$url?access_token=$accessToken");
                curl_setopt($ch, CURLOPT_USERAGENT, CrestSSO::$userAgent);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array($authHeader));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                $result = curl_exec($ch);
		curl_close($ch);
		$json = json_decode($result, true);
		return $json;
	}

        public static function crestPost($url, $fields) {
                global $ccpClientID, $ccpSecret;

		$accessToken = CrestSSO::getAccessToken();
                $authHeader = "Authorization: Bearer $accessToken";
		$data = json_encode($fields);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "$url");
		curl_setopt($ch, CURLOPT_USERAGENT, CrestSSO::$userAgent);
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array($authHeader, 'Content-Type:application/json'));
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

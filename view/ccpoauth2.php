<?php

global $redis, $ip;

if ($redis->get("zkb:noapi") == "true") {
	if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
		$GLOBALS['render_template'] = "error.html";
		$GLOBALS['render_data'] = ['message' => 'Downtime is not a good time to login, the CCP servers are not reliable, sorry.'];
		return;
	} else {
		return $app->render("error.html", ['message' => 'Downtime is not a good time to login, the CCP servers are not reliable, sorry.']);
	}
}

if (@$_SESSION['characterID'] > 0) {
	if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
		$GLOBALS['render_template'] = "error.html";
		$GLOBALS['render_data'] = ['message' => "Uh... you're already logged in..."];
		return;
	} else {
		return $app->render("error.html", ['message' => "Uh... you're already logged in..."]);
	}
}

$sessID = session_id();

$delayInt = isset($delay) ? (int) $delay : 0;
if ($delayInt > 0 && $delayInt <= 5) {
	$redis->setex("delay:$sessID", 900, $delay);
} else $redis->del("delay:$sessID");

$uri = "/";
if (isset($_SERVER['HTTP_REFERER'])) {
	$referer = $_SERVER['HTTP_REFERER'];
	$uri = parse_url($referer, PHP_URL_PATH);

	// include query string if you want
	$query = parse_url($referer, PHP_URL_QUERY);
	if ($query) {
		$uri .= '?' . $query;
	}

	if (substr($uri, 0, 4) != "/ccp" && $redis->get("forward:$sessID") == null) {
		$redis->setex("forward:$sessID", 900, $uri);
	}
}

$sso = ZKillSSO::getSSO();
$url = $sso->getLoginURL($_SESSION);

session_write_close();
if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['redirect_url'] = $url;
	$GLOBALS['redirect_status'] = 302;
} else {
	$app->redirect($url, 302);
}

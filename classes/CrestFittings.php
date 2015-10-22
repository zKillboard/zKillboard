<?php

class CrestFittings {

	public static function getFittings() {
		global $app, $redis, $ccpClientID, $ccpSecret;

		$charID = @$_SESSION['characterID'];
		$accessToken = CrestSSO::getAccessToken();
		
		$r = CrestSSO::crestGet("https://api-sisi.testeveonline.com/decode/");
		$character = CrestSSO::crestGet($r['character']['href']);
		$fittings = CrestSSO::crestGet($character['fittings']['href']);
		print_r($fittings);
	}

	public static function saveFitting($killID) {


	}
}

<?php

class CrestTools
{

	public static function getJSON($url)
	{
		return json_decode(self::curlFetch($url), true);
	}

	public static function curlFetch($url)
	{
		$numTries = 0;
		do
		{
			global $baseAddr;

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_USERAGENT, "Crest Fetcher for http://$baseAddr");
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5); //timeout in seconds
			$body = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if ($httpCode == 200) return $body;
			if ($httpCode == 500) return null;
			if ($httpCode == 415) return null;
			$numTries++;
			sleep(1);
		} while ($httpCode != 200 && $numTries <= 3);
Log::log("Gave up on $url");
		return null;
	}

	public static function fetch($id, $hash = null)
	{
		global $mdb;

		// Do we already have this mail?
		$mail = $mdb->findDoc("rawmails", ['killID' => (int) $id]);
		if ($mail != null) return $mail;

		// Nope, don't have it, go fetch
		if ($hash == null) throw new Exception("rawmail not on record, must provide a hash");
		$url = "http://public-crest.eveonline.com/killmails/$id/$hash/";
		return self::getJSON($url);
	}
}

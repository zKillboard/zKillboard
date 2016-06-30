<?php

class RemoteApi
{
	public static function getData($url, $cacheTime = 3600)
	{
		global $baseAddr;

		$ch = curl_init();
		curl_setopt_array($ch, [
				CURLOPT_USERAGENT => "zKillboard fetcher: {$baseAddr}",
				CURLOPT_TIMEOUT => 60,
				CURLOPT_POST => false,
				CURLOPT_FORBID_REUSE => false,
				CURLOPT_ENCODING => '',
				CURLOPT_URL => $url,
				CURLOPT_HTTPHEADER => ['Connection: keep-alive', 'Keep-Alive: timeout=10, max=1000'],
				CURLOPT_RETURNTRANSFER => true,
				]);

		$result = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		$response = ['url' => $url, 'content' => $result, 'httpCode' => $httpCode, 'error' => $error];

		return $response;
	}
}

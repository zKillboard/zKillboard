<?php

use cvweiss\redistools\RedisTtlCounter;

class CrestTools
{
    public static function getJSON($url)
    {
        return json_decode(self::curlFetch($url), true);
    }

    public static function curlFetch($url)
    {
        global $baseAddr;

        $errorCodes = [403, 404, 415, 500];

        $numTries = 0;
        $httpCode = null;
        do {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, "Crest Fetcher for https://$baseAddr");
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30); //timeout in seconds
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode == 200) {
                Status::addStatus('crest', true);
                return $body;
            }
Log::log("curlFetch error ($httpCode) $url");

            if (in_array($httpCode, $errorCodes)) {
                Status::addStatus('crest', false);
                return $httpCode;
            }

            ++$numTries;
            sleep(1);
        } while ($httpCode != 200 && $numTries <= 3);
        Status::addStatus('crest', false);
        Log::log("Gave up on $url");

        return $httpCode;
    }

    public static function fetch($id, $hash = null)
    {
        global $crestServer;

        $url = "$crestServer/killmails/$id/$hash/";

        return self::getJSON($url);
    }
}

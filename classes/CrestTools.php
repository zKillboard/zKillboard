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

        $guzzler = new Guzzler();
        $type = $guzzler->getType($url);

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
                Status::addStatus($type, true);
                return $body;
            }
            //Log::log("curlFetch error ($httpCode) $url");
            Status::addStatus($type, false);

            if (in_array($httpCode, $errorCodes)) {
                return $httpCode;
            }

            ++$numTries;
            sleep(1);
        } while ($httpCode != 200 && $numTries <= 3);
        Status::addStatus($type, false);
        //Log::log("Gave up on $url");

        return $httpCode;
    }
}

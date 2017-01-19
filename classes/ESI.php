<?php

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisTtlCounter;

class ESI {

    public static function curl($url, $fields, $accessToken, $callType = 'GET')
    {
        $esiCalls = new RedisTtlCounter('ttlc:esiCalls', 10);
        while ($esiCalls->count() > 400) sleep(1);
        $esiCalls->add(uniqid());

        $callType = strtoupper($callType);
        $headers = ['Authorization: Bearer ' . $accessToken];

        $url = $callType != 'GET' ? $url : $url . "?" . self::buildParams($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, "curl fetcher for zkillboard.com");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        switch ($callType) {
            case 'DELETE':
            case 'PUT':
            case 'POST_JSON':
                $headers[] = "Content-Type: application/json";
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(empty($fields) ? (object) NULL : $fields, JSON_UNESCAPED_SLASHES));
                $callType = $callType == 'POST_JSON' ? 'POST' : $callType;
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POSTFIELDS, self::buildParams($fields));
                break;
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $callType);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode != 200) {
            $esiFailure = new RedisTtlCounter('ttlc:esiFailure', 300);
            $esiFailure->add(uniqid());
            return "{\"error\": true, \"httpCode\": $httpCode}";
        }
        $esiSuccess = new RedisTtlCounter('ttlc:esiSuccess', 300);
        $esiSuccess->add(uniqid());
        return $result;
    }

    public static function buildParams($fields)
    {
        $string = "";
        foreach ($fields as $field=>$value) {
            $string .= $string == "" ? "" : "&";
            $string .= "$field=" . rawurlencode($value);
        }
        return $string;
    }
}

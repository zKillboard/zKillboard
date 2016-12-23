<?php

require_once "../init.php";

$minutely = date('Hi');
while ($minutely == date('Hi')) {
    $row = $mdb->findDoc("apisESI", [], ['lastFetch' => 1]);
    $lastFetch = $row['lastFetch'];
    if (@$lastFetch->sec > time() - 3600) {
        sleep(1);
        continue;
    }

    $scopes = $row['scopes'];
    if (in_array('esi-killmails.read_killmails.v1', $scopes)) {
        $refreshToken = $row['refreshToken'];
        $charID = $row['characterID'];
        $accessToken = CrestSSO::getAccessToken($charID, null, $refreshToken);

        $headers = [];
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Authorization: Bearer ' . $accessToken;

        $minKillID = 999999999999;
        $maxKillID = isset($row['maxKillID']) ? $row['maxKillID'] : 0;

        $killsAdded = 0;
        do {
            $url = "https://esi.tech.ccp.is/latest/characters/$charID/killmails/recent/";
            $fields = ['max_count' => 50, 'datasource' => 'tranquility'];
            if ($minKillID !== 999999999999) $fields['max_kill_id'] = $minKillID;

            $raw = doCall($url, $fields, $accessToken);
            $json = json_decode($raw, true);
            foreach ($json as $kill) {
                $killID = $kill['killmail_id'];
                $hash = $kill['killmail_hash'];
                $minKillID = min($minKillID, $killID);
                $maxKillID = max($maxKillID, $killID);

                $exists = $mdb->exists('crestmails', ['killID' => $killID]);
                if (!$exists) {
                    try {
                        $mdb->getCollection('crestmails')->save(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'esi', 'added' => $mdb->now()]);
                        $killsAdded++;
                    } catch (MongoDuplicateKeyException $ex) {
                        // ignore it *sigh*
                    }
                }
            }
        } while (sizeof($json) > 0);
    }
    $mdb->set("apisESI", $row, ['lastFetch' => $mdb->now(), 'maxKillID' => $maxKillID]);
    if ($killsAdded > 0) {
        $name = Info::getInfoField('characterID', $charID, 'name');
        if ($name === null) $name = $charID;
        while (strlen("$killsAdded") < 3) $killsAdded = " " . $killsAdded;
        Util::out("$killsAdded kills added by char $name (ESI)");
    }
}

function doCall($url, $fields, $accessToken, $callType = 'GET')
{
    $callType = strtoupper($callType);
    $headers = ['Authorization: Bearer ' . $accessToken];

    $fieldsString = buildParams($fields);
    $url = $callType != 'GET' ? $url : $url . "?" . $fieldsString;
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
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldsString);
            break;
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $callType);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $result = curl_exec($ch);
    if (curl_errno($ch) !== 0) {
        throw new \Exception(curl_error($ch), curl_errno($ch));
    }
    return $result;
}

function buildParams($fields)
{
    $string = "";
    foreach ($fields as $field=>$value) {
        $string .= $string == "" ? "" : "&";
        $string .= "$field=" . rawurlencode($value);
    }
    return $string;
}

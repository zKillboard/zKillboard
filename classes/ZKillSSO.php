<?php

class ZKillSSO extends EveOnlineSSO
{
    private static $defaultScopes = ['esi-killmails.read_killmails.v1', 'esi-killmails.read_corporation_killmails.v1', 'esi-fittings.write_fittings.v1'];

    public static function getDefaultScopes()
    {
        return self::$defaultScopes;
    }

    public static function getSSO($scopes = null)
    {
        global $ccpCallback, $ccpClientID, $ccpSecret;

        if ($scopes === null) $scopes = self::$defaultScopes;
        if (!in_array('publicData', $scopes, true)) $scopes[] = 'publicData';

        return new self($ccpClientID, $ccpSecret, $ccpCallback, $scopes, "zkillboard.com (Squizz Caphinator)");
    }

    public static function cleanupInvalidGrant($characterID, $mdb)
    {
        $deletedSessions = $mdb->getCollection('sessions')->deleteMany(['characterID' => $characterID]);
        $deletedScopes = $mdb->getCollection('scopes')->deleteMany(['characterID' => $characterID]);
        if ($deletedSessions->getDeletedCount() > 0 || $deletedScopes->getDeletedCount() > 0) {
            ZLog::add("SSO invalid grant detected, for security all logged in sessions removed.", $characterID);
        }
    }

    public function getAccessToken($refreshToken, $scopes = [])
    {
        global $mdb, $redis;

        $accessToken = $redis->get("oauth2:$refreshToken");
        if ($accessToken != null) {
            return $accessToken;
        }

        $accessJson = parent::getAccessToken($refreshToken, $scopes);

        if (!isset($accessJson['access_token'])) {
            return $accessJson;
        }


        $accessToken = $accessJson['access_token'];
        $newRT = $accessJson['refresh_token'] ?? null;
        if ($newRT != null && $newRT != $refreshToken) {
            $mdb->set("scopes", ['refreshToken' => $refreshToken], ['refreshToken' => $newRT], true);
        }
        $expires = max($accessJson['expires_in'] - 1, 1);
        $redis->setex("oauth2:$refreshToken", $expires, $accessToken);
        return $accessToken;
    }
}

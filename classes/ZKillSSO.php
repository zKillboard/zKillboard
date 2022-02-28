<?php

class ZKillSSO extends EveOnlineSSO
{
    private static $defaultScopes = ['esi-killmails.read_killmails.v1', 'esi-killmails.read_corporation_killmails.v1', 'esi-fittings.write_fittings.v1'];

    public static function getSSO($scopes = null)
    {
        global $ccpCallback, $ccpClientID, $ccpSecret;

        if ($scopes === null) $scopes = self::$defaultScopes;

        return new self($ccpClientID, $ccpSecret, $ccpCallback, $scopes, "zkillboard.com (Squizz Caphinator)");
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
        $newRT = $accessJson['refresh_token'];
        if ($newRT != null && $newRT != $refreshToken) {
            $mdb->set("scopes", ['refreshToken' => $refreshToken], ['refreshToken' -> $newRT], true);
        }
        $expires = max($accessJson['expires_in'] - 1, 1);
        $redis->setex("oauth2:$refreshToken", $expires, $accessToken);
        return $accessToken;
    }
}

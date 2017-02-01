<?php

use cvweiss\redistools\RedisTimeQueue;

class Api
{
    public static function addKey($keyID, $vCode, $label = null)
    {
        global $mdb, $redis;

        $keyID = (int) $keyID;
        if ($keyID == 0) {
            return 'Invalid keyID';
        }

        $before = $vCode;
        $vCode = preg_replace('/[^a-z0-9]/i', '', (string) $vCode);
        if ($before != $vCode) {
            return 'Invalid vCode';
        }

        $exists = $mdb->exists('apis', ['keyID' => $keyID, 'vCode' => $vCode]);
        if ($exists) {
            return 'We already have this API.';
        }

        $row = ['keyID' => $keyID, 'vCode' => $vCode];
        $mdb->save('apis', $row);

        $redis->lpush("zkb:apis", $keyID);

        return 'Success, your API has been added.';
    }

    public static function deleteKey($keyID)
    {
        global $mdb;

        $userID = (int) User::getUserID();
        if ($userID == null) {
            return 'You do not have access to remove any keys.';
        }

        $result = $mdb->remove('apis', ['keyID' => (int) $keyID, 'userID' => $userID]);
        if ($result['n'] > 0) {
            $mdb->remove('apiCharacters', ['keyID' => (int) $keyID]);
        }

        return "$keyID has been deleted";
    }

    public static function getKeys($userID)
    {
        global $mdb;
        $userID = (int) $userID;
        if ($userID == 0) {
            return [];
        }

        $result = $mdb->find('apis', ['userID' => $userID]);
        $retVal = [];
        foreach ($result as $row) {
            $row['lastFetched'] = date('Y/m/d H:i', isset($row['lastFetched']) ? $row['lastFetched'] : 0);
            $retVal[] = $row;
        }

        return $retVal;
    }

    public static function getSsoKeys($userID = 0)
    {
        return [];
    }

    public static function getCharacterKeys($userID)
    {
        global $mdb;

        $characterIDs = $mdb->find('apiCharacters', ['characterID' => $userID], ['keyID' => 1]);
        Info::addInfo($characterIDs);

        return $characterIDs;
    }

    /**
     * Returns an array of the characters assigned to this user.
     *
     * @static
     *
     * @param $userID int
     *
     * @return array
     */
    public static function getCharacters($userID)
    {
        $result = self::getCharacterKeys($userID);
        Info::addInfo($result);

        return $result;
    }

    /**
     * Tests the access mask for KillLog access.
     *
     * @static
     *
     * @param int $accessMask
     *
     * @return bool
     */
    public static function hasBits($accessMask)
    {
        return (int) ($accessMask & 256) > 0;
    }
}

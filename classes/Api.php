<?php

class Api
{
    public static function addKey($keyID, $vCode, $label = null)
    {
        global $mdb;

        $keyID = (int) $keyID;
        if ($keyID == 0) {
            return 'Invalid keyID';
        }

        $before = $vCode;
        $vCode = preg_replace('/[^a-z0-9]/i', '', (string) $vCode);
        if ($before != $vCode) {
            return 'Invalid vCode';
        }

        $userID = User::getUserID();
        if ($userID == null) {
            $userID = 0;
        }

        $exists = $mdb->exists('apis', ['keyID' => $keyID, 'vCode' => $vCode]);
        if ($exists) {
            if ($userID > 0) {
                $mdb->set('apis', ['keyID' => $keyID, 'vCode' => $vCode], ['userID' => $userID]);

                return 'We have assigned this API key to your account.';
            }

            return 'We already have this API in our database.';
        }

        $row = ['keyID' => $keyID, 'vCode' => $vCode, 'label' => $label, 'lastApiUpdate' => new MongoDate(2), 'userID' => $userID];
        $mdb->save('apis', $row);

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
        try {
            $mdb->remove('apisCrest', ['_id' => new MongoID("$keyID"), 'characterID' => $userID]);
        } catch (Exception $ex) {
            // Just ignore it
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
            $row['lastFetched'] = date('Y/m/d H:i', $row['lastFetched']);
            $retVal[] = $row;
        }

        return $retVal;
    }

    public static function getSsoKeys($userID = 0)
    {
        global $mdb;

        $retVal = [];
        $result = $mdb->find('apisCrest', ['characterID' => $userID]);
        foreach ($result as $row) {
            $row['lastValidation'] = date('Y/m/d H:i', $row['lastFetch']);
            $retVal[] = $row;
        }

        return $retVal;
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

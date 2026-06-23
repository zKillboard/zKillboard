<?php

use cvweiss\redistools\RedisTtlCounter;

class UserGlobals
{

    public function getGlobals(): array
    {
        $result = array();
        if (User::isLoggedIn()) {
            $userID = User::getUserID();
            $u = User::getUserInfo();

            $config = UserConfig::getAll();

            foreach ($config as $key => $val) {
                $this->addGlobal($result, $key, $val);
            }

            $this->addGlobal($result, 'sessionusername', @$u['username']);
            $this->addGlobal($result, 'sessionuserid', (int) @$_SESSION['characterID']);
            $this->addGlobal($result, 'sessionadmin', (bool) @$u['admin']);
            $this->addGlobal($result, 'sessionmoderator', (bool) @$u['moderator']);

            $this->addTrackers($result, $userID);
            $this->addFavorites($result, $userID);
        }
        return $result;
    }

    private function addGlobal(&$array, $key, $value, $defaultValue = null)
    {
        if ($value == null && $defaultValue == null) {
            return;
        } elseif ($value == null) {
            $array[$key] = $defaultValue;
        } else {
            $array[$key] = $value;
        }
    }

    public function addTrackers(&$result, $userID)
    {
        global $mdb;

        // First, load up the current trackers
        $this->parseTrackers($result, 'character');
        $this->parseTrackers($result, 'corporation');
        $this->parseTrackers($result, 'alliance');
        $this->parseTrackers($result, 'faction');
        $this->parseTrackers($result, 'ship');
        $this->parseTrackers($result, 'group');
        $this->parseTrackers($result, 'system');
        $this->parseTrackers($result, 'constellation');
        $this->parseTrackers($result, 'region');

        // Second, add the character, corp, and alliance for the current account
        $info = $mdb->findDoc('information', ['type' => 'characterID', 'id' => $userID, 'cacheTime' => 300]);
        $charName = Info::getInfoField('characterID', $userID, 'name')  . ' *';
        $corpID = (int) (@$info['corporationID'] ?: @$info['corporation_id']);
        $corpName = $corpID > 0 ? Info::getInfoField('corporationID', $corpID, 'name') . ' *' : null;
        $alliID = (int) (@$info['allianceID'] ?: @$info['alliance_id']);
        $alliName = $alliID > 0 ? Info::getInfoField('allianceID', $alliID, 'name')  . ' *' : null;
        $isCEO = $this->isCorporationCEO($userID, $corpID, $info);
        $isExecutorCEO = $isCEO && $this->isAllianceExecutorCorporation($alliID, $corpID, $info);

        $result['tracker_character'] = $this->addTracker(@$result['tracker_character'], $userID, $charName);
        $result['tracker_corporation'] = $this->addTracker(@$result['tracker_corporation'], $corpID, $corpName);
        $this->addGlobal($result, 'corporationID', $corpID, $corpName);
        $this->addGlobal($result, 'canFavoriteCorporation', $corpID > 1999999 && $isCEO);
        $this->addGlobal($result, 'showCorporationFavorites', $corpID > 1999999);
        $result['tracker_alliance'] = $this->addTracker(@$result['tracker_alliance'], $alliID, $alliName);
        $this->addGlobal($result, 'allianceID', $alliID, $alliName);
        $this->addGlobal($result, 'canFavoriteAlliance', $alliID > 0 && $isExecutorCEO);
        $this->addGlobal($result, 'showAllianceFavorites', $alliID > 0);
    }

    public function addFavorites(&$result, $userID)
    {
        global $mdb;

        $info = Info::getInfo('characterID', $userID);
        $corpID = (int) (@$info['corporationID'] ?: @$info['corporation_id']);
        $alliID = (int) (@$info['allianceID'] ?: @$info['alliance_id']);

        $result['favorites'] = $this->getFavoriteKillIDs('characterID', $userID);
        $result['corpFavorites'] = $corpID > 1999999 ? $this->getFavoriteKillIDs('characterID', $corpID) : [];
        $result['alliFavorites'] = $alliID > 0 ? $this->getFavoriteKillIDs('characterID', $alliID) : [];
    }

    private function getFavoriteKillIDs($field, $id)
    {
        global $mdb;

        $favs = $mdb->find('favorites', [$field => (int) $id]);
        $favorites = [];
        foreach ($favs as $fav) $favorites[] = $fav['killID'];
        return $favorites;
    }

    private function isCorporationCEO($userID, $corpID, $info)
    {
        global $mdb;

        if ((bool) @$info['isCEO']) return true;
        if ($corpID <= 1999999) return false;
        return $mdb->exists('information', [
            'type' => 'corporationID',
            'id' => (int) $corpID,
            '$or' => [
                ['ceoID' => (int) $userID],
                ['ceo_id' => (int) $userID],
            ],
        ]);
    }

    private function isAllianceExecutorCorporation($alliID, $corpID, $info)
    {
        global $mdb;

        if ((bool) @$info['isExecutorCEO']) return true;
        if ($alliID <= 0 || $corpID <= 1999999) return false;
        return $mdb->exists('information', [
            'type' => 'allianceID',
            'id' => (int) $alliID,
            '$or' => [
                ['executorCorpID' => (int) $corpID],
                ['executor_corporation_id' => (int) $corpID],
            ],
        ]);
    }

    private function parseTrackers(&$result, $type)
    {
        $array = @$result['tracker_' . $type];
        if ($array == null) $array = [];
        $parsed = [];
        foreach ($array as $id) {
            $id = (int) $id;
            $searchType = $type;
            if ($searchType == 'ship') $searchType = 'type';
            if ($searchType == 'system') $searchType = 'solarSystem';
            $name = Info::getInfoField($searchType . 'ID', $id, 'name');
            $parsed[] = ['id' => $id, 'name' => $name];
        }
        $result['tracker_' . $type] = $parsed;
    }

    private function addTracker($array, $id, $name)
    {
        if ($id == 0) {
            return $array;
        }
        if ($array == null) {
            $array = [];
        }

        $addIt = true;
        foreach ($array as $a) {
            if ($a['id'] == $id) {
                $addIt = false;
            }
        }
        if ($addIt) {
            $array[] = ['id' => $id, 'name' => $name];
        }

        usort($array, 'UserGlobals::sortIt');

        return $array;
    }

    private static function sortIt($a, $b)
    {
        return strtolower($a['name']) > strtolower($b['name']);
    }
}

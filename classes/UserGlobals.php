<?php

use cvweiss\redistools\RedisTtlCounter;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class UserGlobals extends AbstractExtension implements GlobalsInterface
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
        $corpID = (int) @$info['corporationID'];
        $corpName = $corpID > 0 ? Info::getInfoField('corporationID', $corpID, 'name') . ' *' : null;
        $alliID = (int) @$info['allianceID'];
        $alliName = $alliID > 0 ? Info::getInfoField('allianceID', $alliID, 'name')  . ' *' : null;

        $result['tracker_character'] = $this->addTracker(@$result['tracker_character'], $userID, $charName);
        $result['tracker_corporation'] = $this->addTracker(@$result['tracker_corporation'], $corpID, $corpName);
        $this->addGlobal($result, 'corporationID', $corpID, $corpName);
        $result['tracker_alliance'] = $this->addTracker(@$result['tracker_alliance'], $alliID, $alliName);
        $this->addGlobal($result, 'allianceID', $alliID, $alliName);
    }

    public function addFavorites(&$result, $userID)
    {
        global $mdb;

        $favs = $mdb->find("favorites", ['characterID' => (int) $userID]);
        $favorites = [];
        foreach ($favs as $fav) $favorites[] = $fav['killID'];
        $result['favorites'] = $favorites;
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

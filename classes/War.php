<?php

class War
{
    public static function getWars($id, $active = true, $combined = false)
    {
        if (!self::isAlliance($id)) {
            $alliID = Db::queryField('select allianceID from zz_corporations where corporationID = :id', 'allianceID', array(':id' => $id));
            if ($alliID != 0) {
                $id = $alliID;
            }
        }
        $active = $active ? '' : 'not';
        $aggressing = Db::query("select * from zz_wars where aggressor = :id and timeFinished is $active null", array(':id' => $id));
        $defending = Db::query("select * from zz_wars where defender = :id and timeFinished is $active null", array(':id' => $id));
        if ($combined) {
            return array_merge($aggressing, $defending);
        }

        return array('agr' => $aggressing, 'dfd' => $defending);
    }

    public static function getKillIDWarInfo($killID)
    {
        global $mdb;
        $warID = $mdb->findField('killmails', 'warID', ['killID' => $killID]);

        return self::getWarInfo($warID);
    }

    public static function getWarInfo($warID)
    {
        global $mdb;
        $warInfo = array();
        if ($warID == null) {
            return $warInfo;
        }
        $warInfo = $mdb->findDoc('information', ['type' => 'warID', 'id' => $warID]);
        if (!isset($warInfo['aggressor'])) {
            return [];
        }

        $warInfo['warID'] = $warID;
        $agr = isset($warInfo['aggressor']['alliance_id']) ? $warInfo['aggressor']['alliance_id'] : (isset($warInfo['aggressor']['corporation_id']) ? $warInfo['aggressor']['corporation_id'] : $warInfo['aggressor']['id']);
        $agrIsAlliance = self::isAlliance($agr);
        $agrName = $agrIsAlliance ? Info::getInfoField('allianceID', $agr, 'name') : Info::getInfoField('corporationID', $agr, 'name');
        $warInfo['agrName'] = $agrName;
        $warInfo['agrLink'] = ($agrIsAlliance ? '/alliance/' : '/corporation/')."$agr/";

        $dfd = isset($warInfo['defender']['alliance_id']) ? $warInfo['defender']['alliance_id'] : (isset($warInfo['defender']['corporation_id']) ? $warInfo['defender']['corporation_id'] : $warInfo['defender']['id']);
        $dfdIsAlliance = self::isAlliance($dfd);
        $dfdName = $dfdIsAlliance ? Info::getInfoField('allianceID', $dfd, 'name') : Info::getInfoField('corporationID', $dfd, 'name');
        $warInfo['dfdName'] = $dfdName;
        $warInfo['dfdLink'] = ($dfdIsAlliance ? '/alliance/' : '/corporation/')."$dfd/";

        $warInfo['dscr'] = "$agrName vs $dfdName";

        return $warInfo;
    }

    public static function getWarsPageTables($forceRefresh = false)
    {
        global $mdb, $redis;

        $cacheKey = 'zkb:wars:page:v1';
        if (!$forceRefresh) {
            try {
                $cached = $redis->get($cacheKey);
                if ($cached != null) {
                    $wars = unserialize($cached);
                    if (is_array($wars)) {
                        return $wars;
                    }
                }
            } catch (Exception $ex) {
                try {
                    $redis->del($cacheKey);
                } catch (Exception $ex) {
                }
            }
        }

        $fields = ['id' => 1, 'aggressor' => 1, 'defender' => 1, 'started' => 1, 'finished' => 1, 'timeStarted' => 1];
        $wars = array();
        $wars[] = ['name' => 'Recent Declared Wars - Open to Allies', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID', 'open_for_allies' => true], ['timeStarted' => -1], 50, $fields)];
        $wars[] = ['name' => 'Recent Declared Wars - Mutual', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID', 'mutual' => true], ['timeStarted' => -1], 50, $fields)];
        $wars[] = ['name' => 'Recently Declared Wars', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID'], ['started' => -1], 25, $fields)];
        $wars[] = ['name' => 'Recently Finished Wars', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID'], ['finished' => -1], 25, $fields)];

        try {
            $redis->setex($cacheKey, 3900, serialize($wars));
        } catch (Exception $ex) {
            // The page can still render directly if Redis is temporarily unavailable.
        }

        return $wars;
    }

    public static function isAlliance($entityID)
    {
        global $mdb;

        return $mdb->exists('information', ['type' => 'allianceID', 'id' => $entityID]);
    }
}

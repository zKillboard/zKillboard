<?php

class Related
{
    private static $killstorage = array();

    public static function buildSummary(&$kills, $parameters, $options)
    {
        $involvedEntities = array();
        foreach ($kills as $killID => $kill) {
            static::addAllInvolved($involvedEntities, $killID);
        }

        $blueTeam = array();
        $redTeam = static::findWinners($kills, 'allianceID');
        foreach ($involvedEntities as $entity => $chars) {
            if (!in_array($entity, $redTeam)) {
                $blueTeam[] = $entity;
            }
        }

        if (isset($options['A'])) {
            static::assignSides($options['A'], $redTeam, $blueTeam);
        }
        if (isset($options['B'])) {
            static::assignSides($options['B'], $blueTeam, $redTeam);
        }

        $redInvolved = static::getInvolved($kills, $redTeam);
        $blueInvolved = static::getInvolved($kills, $blueTeam);

        $redKills = static::getKills($kills, $redTeam);
        $blueKills = static::getKills($kills, $blueTeam);

        static::addMoreInvolved($redInvolved, $redKills);
        static::addMoreInvolved($blueInvolved, $blueKills);
        Info::addInfo($redInvolved);
        Info::addInfo($blueInvolved);

        $redTotals = static::getStatsKillList(array_keys($redKills));
        $redTotals['pilotCount'] = sizeof($redInvolved);
        $blueTotals = static::getStatsKillList(array_keys($blueKills));
        $blueTotals['pilotCount'] = sizeof($blueInvolved);

        $red = static::addInfo($redTeam);
        asort($red);
        $blue = static::addInfo($blueTeam);
        asort($blue);

        usort($redInvolved, 'Related::compareShips');
        usort($blueInvolved, 'Related::compareShips');

        $retValue = array(
                'teamA' => array(
                    'list' => $redInvolved,
                    'kills' => $redKills,
                    'totals' => $redTotals,
                    'entities' => $red,
                    ),
                'teamB' => array(
                    'list' => $blueInvolved,
                    'kills' => $blueKills,
                    'totals' => $blueTotals,
                    'entities' => $blue,
                    ),
                );

        return $retValue;
    }

    private static function addAllInvolved(&$entities, $killID)
    {
        global $mdb;
        $kill = $mdb->findDoc('killmails', ['cacheTime' => 3600, 'killID' => $killID]);

        static::$killstorage[$killID] = $kill;

        $victim = $kill['involved'][0];
        static::addInvolved($entities, $victim);
        $involved = $kill['involved'];
        array_shift($involved);
        if (is_array($involved)) {
            foreach ($involved as $entry) {
                static::addInvolved($entities, $entry);
            }
        }
    }

    private static function addInvolved(&$entities, &$entry)
    {
        $entity = isset($entry['allianceID']) && $entry['allianceID'] != 0 ? $entry['allianceID'] : @$entry['corporationID'];
        if (!isset($entities["$entity"])) {
            $entities["$entity"] = array();
        }
        if (!in_array(@$entry['characterID'], $entities["$entity"])) {
            $entities["$entity"][] = @$entry['characterID'];
        }
    }

    private static function getInvolved(&$kills, $team)
    {
        $involved = array();
        foreach ($kills as $kill) {
            $kill = static::$killstorage[$kill['victim']['killID']];

            $attackers = $kill['involved'];
            array_shift($attackers);
            if (is_array($attackers)) {
                foreach ($attackers as $entry) {
                    $add = false;
                    if (in_array(@$entry['allianceID'], $team)) {
                        $add = true;
                    }
                    if (in_array(@$entry['corporationID'], $team)) {
                        $add = true;
                    }

                    if ($add) {
                        $key = @$entry['characterID'].':'.@$entry['corporationID'].':'.@$entry['allianceID'].':'.@$entry['shipTypeID'];
                        $entry['shipName'] = Info::getInfoField('typeID', @$entry['shipTypeID'], 'name');
                        if (!in_array($key, $involved)) {
                            $involved[$key] = $entry;
                        }
                    }
                }
            }
        }

        return $involved;
    }

    private static function addMoreInvolved(&$team, $kills)
    {
        foreach ($kills as $kill) {
            $victim = $kill['victim'];
            Info::addInfo($victim);
            if (@$victim['characterID'] > 0 && @$victim['groupID'] != 29) {
                $key = @$victim['characterID'].':'.@$victim['corporationID'].':'.(int) @$victim['allianceID'].':'.$victim['shipTypeID'];
                $victim['destroyed'] = $victim['killID'];
                $team[$key] = $victim;
            }
        }
    }

    private static function getKills(&$kills, $team)
    {
        $teamsKills = array();
        foreach ($kills as $killID => $kill) {
            $victim = $kill['victim'];
            $add = in_array((int) @$victim['allianceID'], $team) || in_array($victim['corporationID'], $team);

            if ($add) {
                $teamsKills[$killID] = $kill;
            }
        }

        return $teamsKills;
    }

    private static function getStatsKillList($killIDs)
    {
        $totalPrice = 0;
        $totalPoints = 0;
        $groupIDs = array();
        $totalShips = 0;
        foreach ($killIDs as $killID) {
            $kill = Kills::getKillDetails($killID);
            $info = $kill['info'];
            $victim = $kill['victim'];
            $totalPrice += $info['zkb']['totalValue'];
            $totalPoints += $info['zkb']['points'];
            $groupID = $victim['groupID'];
            if (!isset($groupIDs[$groupID])) {
                $groupIDs[$groupID] = array();
                $groupIDs[$groupID]['count'] = 0;
                $groupIDs[$groupID]['isk'] = 0;
                $groupIDs[$groupID]['points'] = 0;
            }
            $groupIDs[$groupID]['groupID'] = $groupID;
            ++$groupIDs[$groupID]['count'];
            $groupIDs[$groupID]['isk'] += $info['zkb']['totalValue'];
            $groupIDs[$groupID]['points'] += $info['zkb']['points'];
            ++$totalShips;
        }
        Info::addInfo($groupIDs);

        return array(
                'total_price' => $totalPrice, 'groupIDs' => $groupIDs, 'totalShips' => $totalShips,
                'total_points' => $totalPoints,
                );
    }

    private static function addInfo(&$team)
    {
        global $mdb;

        $retValue = array();
        foreach ($team as $entity) {
            $retValue[$entity] = $mdb->findField('information', 'name', ['id' => ((int) $entity)]);
        }

        return $retValue;
    }

    /**
     * @param string $typeColumn
     */
    private static function findWinners(&$kills, $typeColumn)
    {
        $involvedArray = array();
        foreach ($kills as $killID => $kill) {
            $finalBlow = @$kill['finalBlow'];
            $added = self::addInvolvedEntity($involvedArray, $killID, @$finalBlow['allianceID']);
            if (!$added) {
                $added = self::addInvolvedEntity($involvedArray, $killID, @$finalBlow['corporationID']);
            }
            if (!$added) {
                $added = self::addInvolvedEntity($involvedArray, $killID, @$finalBlow['characterID']);
            }
        }

        return array_keys($involvedArray);
    }

    private static function addInvolvedEntity(&$involvedArray, &$killID, $entity)
    {
        if ($entity == 0) {
            return false;
        }
        if (!isset($involvedArray["$entity"])) {
            $involvedArray["$entity"] = array();
        }
        if (!in_array($killID, $involvedArray["$entity"])) {
            $involvedArray["$entity"][] = $killID;

            return true;
        }

        return false;
    }

    private static function assignSides($assignees, &$teamA, &$teamB)
    {
        foreach ($assignees as $id) {
            if (!isset($teamA[$id])) {
                $teamA[] = $id;
            }
            if (($key = array_search($id, $teamB)) !== false) {
                unset($teamB[$key]);
            }
        }
    }

    public static function compareShips($a, $b)
    {
        $aSize = Db::queryField('select mass from ccp_invTypes where typeID = :typeID', 'mass', array(':typeID' => @$a['shipTypeID']));
        $bSize = Db::queryField('select mass from ccp_invTypes where typeID = :typeID', 'mass', array(':typeID' => @$b['shipTypeID']));

        return $aSize < $bSize;
    }
}

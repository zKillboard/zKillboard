<?php

class Summary
{
    /**
     * @param array $data
     * @param int   $id
     * @param array $parameters
     *
     * @return array
     */
    public static function getPilotSummary(&$data, $id, $parameters = array())
    {
        return self::getSummary('pilot', 'characterID', $data, $id, $parameters);
    }

    /**
     * @param string $type
     * @param string $column
     * @param array  $data
     * @param int    $id
     * @param array  $parameters
     *
     * @return array
     */
    public static function getSummary($type, $column, &$data, $id, $parameters = array(), $overRide = false)
    {
        global $mdb;

        if ($type == 'pilot') {
            $type = 'characterID';
        } elseif ($type == 'corp') {
            $type = 'corporationID';
        } elseif ($type == 'alli') {
            $type = 'allianceID';
        } elseif ($type == 'faction') {
            $type = 'factionID';
        } elseif ($type == 'ship') {
            $type = 'shipTypeID';
        } elseif ($type == 'system') {
            $type = 'solarSystemID';
        } elseif ($type == 'region') {
            $type = 'regionID';
        }

        $stats = $mdb->findDoc('statistics', ['type' => $type, 'id' => (int) $id]);
//if ($stats == null) echo $type;
        if ($stats == null) {
            $stats = [];
        }
        $data['stats'] = $stats;
        $data[''] = $stats;

        $arr = ['ships', 'isk', 'points'];
        if ($arr != null) {
            foreach ($arr as $a) {
                $data["{$a}Destroyed"] = (int) @$stats["{$a}Destroyed"];
                $data["{$a}DestroyedRank"] = (int) @$stats["{$a}DestroyedRank"];
                $data["{$a}Lost"] = (int) @$stats["{$a}Lost"];
                $data["{$a}LostRank"] = (int) @$stats["{$a}LostRank"];
            }
        }
        $data['overallRank'] = @$stats['overallRank'];

        return $data;
    }

    /**
     * @param array $data
     * @param int   $id
     * @param array $parameters
     *
     * @return array
     */
    public static function getCorpSummary(&$data, $id, $parameters = array())
    {
        return self::getSummary('corp', 'corporationID', $data, $id, $parameters);
    }

    /**
     * @param array $data
     * @param int   $id
     * @param array $parameters
     *
     * @return array
     */
    public static function getAlliSummary(&$data, $id, $parameters = array())
    {
        return self::getSummary('alli', 'allianceID', $data, $id, $parameters);
    }

    /**
     * @param array $data
     * @param int   $id
     * @param array $parameters
     *
     * @return array
     */
    public static function getFactionSummary(&$data, $id, $parameters = array())
    {
        return self::getSummary('faction', 'factionID', $data, $id, $parameters);
    }

    /**
     * @param array $data
     * @param int   $id
     * @param array $parameters
     *
     * @return array
     */
    public static function getShipSummary(&$data, $id, $parameters = array())
    {
        return self::getSummary('ship', 'shipTypeID', $data, $id, $parameters);
    }

    /**
     * @param array $data
     * @param int   $id
     * @param array $parameters
     *
     * @return array
     */
    public static function getGroupSummary(&$data, $id, $parameters = array())
    {
        return self::getSummary('group', 'groupID', $data, $id, $parameters);
    }

    /**
     * @param array $data
     * @param int   $id
     * @param array $parameters
     *
     * @return array
     */
    public static function getRegionSummary(&$data, $id, $parameters = array())
    {
        return self::getSummary('region', 'regionID', $data, $id, $parameters);
    }

    /**
     * @param array $data
     * @param int   $id
     * @param array $parameters
     *
     * @return array
     */
    public static function getSystemSummary(&$data, $id, $parameters = array())
    {
        return self::getSummary('system', 'solarSystemID', $data, $id, $parameters);
    }

    /**
     * @param $type
     * @param $typeID
     *
     * @return array
     */
    public static function getMonthlyHistory($type, $typeID)
    {
        global $mdb;

        $stats = $mdb->findDoc('statistics', ['type' => $type, 'id' => (int) $typeID]);
        if (!isset($stats['months'])) {
            return [];
        }
        $months = $stats['months'];
        krsort($months);

        return $months;
    }
}

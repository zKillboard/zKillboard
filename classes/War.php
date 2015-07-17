<?php

class War
{
    public static function getWars($id, $active = true, $combined = false)
    {
        return [];
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
	if (!isset($warInfo['aggressor'])) return [];

        $warInfo['warID'] = $warID;
        $agr = $warInfo['aggressor']['id'];
        $agrIsAlliance = self::isAlliance($agr);
        $agrName = $agrIsAlliance ? Info::getAlliName($agr) : Info::getCorpName($agr);
        $warInfo['agrName'] = $agrName;
        $warInfo['agrLink'] = ($agrIsAlliance ? '/alliance/' : '/corporation/')."$agr/";

        $dfd = $warInfo['defender']['id'];
        $dfdIsAlliance = self::isAlliance($dfd);
        $dfdName = $dfdIsAlliance ? Info::getAlliName($dfd) : Info::getCorpName($dfd);
        $warInfo['dfdName'] = $dfdName;
        $warInfo['dfdLink'] = ($dfdIsAlliance ? '/alliance/' : '/corporation/')."$dfd/";

        $warInfo['dscr'] = "$agrName vs $dfdName";

        return $warInfo;
    }

    public static function isAlliance($entityID)
    {
        global $mdb;

        return $mdb->exists('information', ['type' => 'allianceID', 'id' => $entityID]);
    }
}

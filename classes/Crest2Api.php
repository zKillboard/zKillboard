<?php

class Crest2Api
{
    public static function convert($killID)
    {
        global $mdb;
        $count = 0;
        $timer = new Timer();

        $crestmail = $mdb->findDoc('rawmails', ['killID' => $killID]);
        $kill = $mdb->findDoc('killmails', ['killID' => $killID]);

        $killmail = array();
        $killmail['killID'] = (int) $killID;
        $killmail['solarSystemID'] = (int) $crestmail['solarSystem']['id'];
        $killmail['killTime'] = str_replace('.', '-', $crestmail['killTime']);
        $killmail['moonID'] = (int) @$crestmail['moon']['id'];

        $victim = array();
        $killmail['victim'] = self::getVictim($crestmail['victim']);
        $killmail['attackers'] = self::getAttackers($crestmail['attackers']);
        $killmail['items'] = self::getItems($crestmail['victim']['items']);
        $killmail['zkb'] = $kill['zkb'];

        return $killmail;
    }

    private static function getVictim($pvictim)
    {
        $victim = array();
        $victim['shipTypeID'] = (int) $pvictim['shipType']['id'];
        $victim['characterID'] = (int) @$pvictim['character']['id'];
        $victim['characterName'] = (string) @$pvictim['character']['name'];
        $victim['corporationID'] = (int) $pvictim['corporation']['id'];
        $victim['corporationName'] = (string) @$pvictim['corporation']['name'];
        $victim['allianceID'] = (int) @$pvictim['alliance']['id'];
        $victim['allianceName'] = (string) @$pvictim['alliance']['name'];
        $victim['factionID'] = (int) @$pvictim['faction']['id'];
        $victim['factionName'] = (string) @$pvictim['faction']['name'];
        $victim['damageTaken'] = (int) @$pvictim['damageTaken'];

        return $victim;
    }

    private static function getAttackers($attackers)
    {
        $aggressors = array();
        foreach ($attackers as $attacker) {
            $aggressor = array();
            $aggressor['characterID'] = (int) @$attacker['character']['id'];
            $aggressor['characterName'] = (string) @$attacker['character']['name'];
            $aggressor['corporationID'] = (int) @$attacker['corporation']['id'];
            $aggressor['corporationName'] = (string) @$attacker['corporation']['name'];
            $aggressor['allianceID'] = (int) @$attacker['alliance']['id'];
            $aggressor['allianceName'] = (string) @$attacker['alliance']['name'];
            $aggressor['factionID'] = (int) @$attacker['faction']['id'];
            $aggressor['factionName'] = (string) @$attacker['faction']['name'];
            $aggressor['securityStatus'] = $attacker['securityStatus'];
            $aggressor['damageDone'] = (int) @$attacker['damageDone'];
            $aggressor['finalBlow'] = (int) @$attacker['finalBlow'];
            $aggressor['weaponTypeID'] = (int) @$attacker['weaponType']['id'];
            $aggressor['shipTypeID'] = (int) @$attacker['shipType']['id'];
            $aggressors[] = $aggressor;
        }

        return $aggressors;
    }

    /**
     * @param array $items
     *
     * @return array
     */
    private static function getItems($items)
    {
        $retArray = array();
        foreach ($items as $item) {
            $i = array();
            $i['typeID'] = (int) @$item['itemType']['id'];
            $i['flag'] = (int) @$item['flag'];
            $i['qtyDropped'] = (int) @$item['quantityDropped'];
            $i['qtyDestroyed'] = (int) @$item['quantityDestroyed'];
            $i['singleton'] = (int) @$item['singleton'];
            if (isset($item->items)) {
                $i['items'] = self::getItems($item->items);
            }
            $retArray[] = $i;
        }

        return $retArray;
    }
}

<?php

class Killmail
{
    // https://forums.eveonline.com/default.aspx?g=posts&m=4900335#post4900335
    public static function getCrestHash($killID, $killmail)
    {
        $victim = $killmail['victim'];
        $victimID = $victim['characterID'] == 0 ? 'None' : $victim['characterID'];

        $attackers = $killmail['attackers'];
        $attacker = null;
        if ($attackers != null) {
            foreach ($attackers as $att) {
                if ($att['finalBlow'] != 0) {
                    $attacker = $att;
                }
            }
        }
        if ($attacker == null) {
            $attacker = $attackers[0];
        }
        $attackerID = $attacker['characterID'] == 0 ? 'None' : $attacker['characterID'];

        $shipTypeID = $victim['shipTypeID'];

        $dttm = (strtotime($killmail['killTime']) * 10000000) + 116444736000000000;

        $string = "$victimID$attackerID$shipTypeID$dttm";

        $sha = sha1($string);

        return $sha;
    }

    public static function deleteKillmail($killID)
    {
        global $mdb, $redis;

        $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);

        foreach ($killmail['involved'] as $involved) {
            foreach ($involved as $type => $id) {
                $mdb->remove('statistics', ['type' => $type, 'id' => (int) $id]);
            }
        }
        $p = ['killID' => $killID];
        $mdb->remove('killmails', $p);
        $mdb->remove('rawmails', $p);
        $mdb->remove('oneWeek', $p);
        $mdb->set('crestmails', $p, ['processed' => false]);
        $redis->del("CacheKill:$killID:overview");
    }
}

<?php

class Killmail
{
    public static function deleteKillmail($killID)
    {
        global $mdb, $redis;

        $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);

        foreach ($killmail['involved'] as $involved) {
            foreach ($involved as $type => $id) {
                $mdb->set('statistics', ['type' => $type, 'id' => (int) $id], ['reset' => true]);
            }
        }
        $p = ['killID' => $killID];
        $mdb->remove('killmails', $p);
        $mdb->remove('rawmails', $p);
        $mdb->remove('esimails', ['killmail_id' => $killID]);
        $mdb->remove('oneWeek', $p);
        $mdb->set('crestmails', $p, ['processed' => false], true);
        $redis->del("CacheKill:$killID:overview");
    }
}

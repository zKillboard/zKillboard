<?php

class Killmail
{
    public static function addMail($killID, $hash) 
    {
        global $mdb, $redis;

        $exists = $mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash]);
        if (!$exists) {
            try {
                $mdb->save('crestmails', ['killID' => $killID, 'hash' => $hash, 'processed' => false]);
                return 1;
            } catch (Exception $ex) {
                if ($ex->getCode() != 11000) echo "$killID $hash : " . $ex->getCode() . " " . $ex->getMessage() . "\n";
            }
        }
        return 0;
    }


    public static function deleteKillmail($killID)
    {
        global $mdb, $redis;

        $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);

        if ($killmail) foreach ($killmail['involved'] as $involved) {
            foreach ($involved as $type => $id) {
                $mdb->set('statistics', ['type' => $type, 'id' => (int) $id], ['reset' => true]);
            }
        }
        $p = ['killID' => $killID];
        $mdb->remove('killmails', $p);
        $mdb->remove('rawmails', $p);
        $mdb->remove('oneWeek', $p);
        $mdb->set('crestmails', $p, ['processed' => false], true);
        $redis->del("CacheKill:$killID:overview");
    }
}

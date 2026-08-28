<?php

class Killmail
{
    public static function addMails($kills, $source = 'Killmail::add', $delay = 0)
    {
        global $redis;

        if (sizeof($kills) == 0) return 0;

        $keys = [];
        foreach ($kills as $kill) $keys[] = "killmailChecked:{$kill['killmail_id']}:{$kill['killmail_hash']}";
        $checked = $redis->mget($keys);

        $newKills = 0;
        foreach ($kills as $index => $kill) {
            if (@$checked[$index] == "true") continue;
            $newKills += self::addMail($kill['killmail_id'], $kill['killmail_hash'], $source, $delay, true);
        }
        return $newKills;
    }

    public static function addMail($killID, $hash, $source = 'Killmail::add', $delay = 0, $cacheChecked = false)
    {
        global $mdb, $redis;

        $kmKey = "killmailChecked:$killID:$hash";

        if (!$cacheChecked && $redis->get($kmKey) == "true") return 0;
        if (strlen($hash) != 40) return (int) Util::zout("Invalid Killmail::addMail $killID $hash");

        $exists = $mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash]);
        if (!$exists) {
            try {
                $mdb->save('crestmails', ['killID' => $killID, 'hash' => $hash, 'processed' => false, 'source' => $source, 'delay' => $delay]);
                $redis->setex($kmKey, 80000 + rand(1,9600), "true");
                return 1;
            } catch (Exception $ex) {
                if ($ex->getCode() != 11000) echo "$killID $hash : " . $ex->getCode() . " " . $ex->getMessage() . "\n";
                return 0;
            }
        }
        $mdb->set('crestmails', ['killID' => $killID, 'hash' => $hash, 'delay' => [ '$gt' => $delay]], ['delay' => $delay]);
        $redis->setex($kmKey, 80000 + rand(1,9600), "true");
        return 0;
    }


    public static function deleteKillmail($killID)
    {
        global $mdb, $redis;

        $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);

        if ($killmail) {
            foreach ($killmail['involved'] as $involved) {
                foreach ($involved as $type => $id) {
                    $mdb->set('statistics', ['type' => $type, 'id' => (int) $id], ['reset' => true]);
                }
            }
            foreach (['solarSystemID', 'constellationID', 'regionID'] as $type) {
                if (isset($killmail['system'][$type])) {
                    $mdb->set('statistics', ['type' => $type, 'id' => (int) $killmail['system'][$type]], ['reset' => true]);
                }
            }
            if (isset($killmail['locationID'])) {
                $mdb->set('statistics', ['type' => 'locationID', 'id' => (int) $killmail['locationID']], ['reset' => true]);
            }
            foreach ((array) ($killmail['labels'] ?? []) as $label) {
                $mdb->set('statistics', ['type' => 'label', 'id' => $label], ['reset' => true]);
            }
            $mdb->set('statistics', ['type' => 'label', 'id' => 'all'], ['reset' => true]);
        }
        $p = ['killID' => $killID];
        $mdb->remove('killmails', $p);
        $mdb->remove('ninetyDays', $p);
        $mdb->remove('oneWeek', $p);
        $mdb->remove('rawmails', $p);
        $mdb->set('crestmails', $p, ['processed' => false], true);
        $redis->setex("kill-deleted:$killID", 3600, "true");
    }
}

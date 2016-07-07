<?php

use cvweiss\redistools\RedisTtlCounter;

class KillmailParser
{
    public static function extendApiTime($mdb, $timeQueue, $api, $type)
    {
        global $redis; 
        $topKillID = $redis->get("zkb:topKillID");
        $id = $type == 'char' ? $api['characterID'] : $api['corporationID'];
        $field = $type == 'char' ? 'characterID' : 'corporationID';
        $query = ["involved.$field" => $id, 'killID' => ['$gte' => ($topKillID - 1000000)]];
        if (!$mdb->exists("killmails", $query)) {
            $time = time() + rand(43200, 86400);
            $timeQueue->setTime($id, $time);
        }
    }

    public static function updateApiRow($mdb, $collection, $api, $errorCode)
    {
        $ttlName = $errorCode == 0 ? 'ttlc:XmlSuccess' : 'ttlc:XmlFailure';
        $ttlCounter = new RedisTtlCounter($ttlName, 300);
        $ttlCounter->add(uniqid());
        $mdb->set($collection, $api, ['errorCode' => (int) $errorCode, 'lastFetched' => time()]);
    }

    public static function processCharApi($mdb, $apiServer, $type, $row) {
        $charID = $row['characterID'];
        $corpID = $row['corporationID'];
        $keyID = $row['keyID'];
        $vCode = $row['vCode'];
        $killmails = self::fetchKillmails($apiServer, $type, $charID, $keyID, $vCode);
        $hasKillmails = sizeof($killmails) > 0;
        $added = self::processKillmails($mdb, $killmails);
        $name = $type == 'char' ? Info::getInfoField('characterID', $charID, 'name')  : Info::getInfoField('corporationID', $corpID, 'name');
        if ($added) {
            while (strlen("$added") < 3) {
                $added = " " . $added;
            }
            Util::out("$added kills added by $type $name");
        }
        return $hasKillmails;
    }

    public static function fetchKillmails($apiServer, $type, $charID, $keyID, $vCode)
    {
        $url = "$apiServer/$type/KillMails.xml.aspx?characterID=$charID&keyID=$keyID&vCode=$vCode";
        $response = RemoteApi::getData($url);
        $content = $response['content'];
        $xml = simplexml_load_string($content);

        $rows = isset($xml->result->rowset->row) ? $xml->result->rowset->row : [];
        $killmails = [];
        foreach ($rows as $c=>$row) {
            $killmails[] = $row;
        }
        return $killmails;
    }

    public static function processKillmails($mdb, $killmails)
    {
        $added = 0;
        foreach ($killmails as $killmail) {
            $killID = (int) $killmail['killID'];
            $hash = self::getHash($killmail);
            $added += self::addKillmail($mdb, $killID, $hash);
        }
        return $added;
    }

    public static function addKillmail($mdb, $killID, $hash)
    {
        if ($mdb->count("crestmails", ['killID' => $killID, 'hash' => $hash]) == 0) {
            $mdb->insert("crestmails", ['killID' => $killID, 'hash' => $hash, 'source' => 'api', 'processed' => false, 'added' => $mdb->now()]);
            return 1;
        }
        return 0;
    }

    public static function getHash($killmail)
    {
        $victim = $killmail->victim;
        $victimID = $victim['characterID'] == 0 ? 'None' : $victim['characterID'];

        $attackers = $killmail->rowset->row;
        $first = null;
        $attacker = null;
        foreach ($attackers as $att) {
            $first = $first == null ? $att : $first;
            if ($att['finalBlow'] != 0) {
                $attacker = $att;
            }
        }
        $attacker = $attacker == null ? $first : $attacker;
        $attackerID = $attacker['characterID'] == 0 ? 'None' : $attacker['characterID'];

        $shipTypeID = $victim['shipTypeID'];
        $dttm = (strtotime($killmail['killTime']) * 10000000) + 116444736000000000;

        $string = "$victimID$attackerID$shipTypeID$dttm";
        $hash = sha1($string);

        return $hash;
    }
}

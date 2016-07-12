<?php

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$counter = 0;
$information = $mdb->getCollection('information');
$timer = new Timer();
$xmlSuccess = new RedisTtlCounter('ttlc:XmlSuccess', 300);
$xmlFailure = new RedisTtlCounter('ttlc:XmlFailure', 300);

$queueCorps = new RedisTimeQueue('tqCorporations', 86400);

$i = date('i');
if ($i == 30) {
    $corps = $information->find(['type' => 'corporationID']);
    foreach ($corps as $corp) {
        $queueCorps->add($corp['id']);
    }
}

while ($timer->stop() < 55000) {
    $id = $queueCorps->next();
    if ($id == null) {
        exit();
    }
    $row = $mdb->findDoc('information', ['type' => 'corporationID', 'id' => (int) $id]);

    $updates = [];
    if (!isset($row['memberCount']) || (isset($row['memberCount']) && $row['memberCount'] != 0)) {
        $id = (int) $row['id'];
        $raw = @file_get_contents("https://api.eveonline.com/corp/CorporationSheet.xml.aspx?corporationID=$id");
        if ($raw != '') {
            $xmlSuccess->add(uniqid());
            ++$counter;
            $xml = @simplexml_load_string($raw);
            if ($xml != null) {
                $corpInfo = $xml->result;
                if (isset($corpInfo->ticker)) {
                    $ceoID = (int) $corpInfo->ceoID;
                    $ceoName = (string) $corpInfo->ceoName;
                    $updates['ticker'] = (string) $corpInfo->ticker;
                    $updates['ceoID'] = $ceoID;
                    $updates['memberCount'] = (int) $corpInfo->memberCount;
                    $updates['allianceID'] = (int) $corpInfo->allianceID;
                    if (!isset($row['name'])) {
                        $updates['name'] = (string) $corpInfo->corporationName;
                    }

                    // Does the CEO exist in our info table?
                    $ceoExists = $mdb->count('information', ['type' => 'characterID', 'id' => $ceoID]);
                    if ($ceoExists == 0) {
                        $mdb->insertUpdate('information', ['type' => 'characterID', 'id' => $ceoID], ['name' => $ceoName, 'corporationID' => $id]);
                    }
                }
            }
        } else {
            $xmlFailure->add(uniqid());
        }
    }
    $updates['lastApiUpdate'] = new MongoDate(time());
    $mdb->insertUpdate('information', ['type' => 'corporationID', 'id' => (int) $row['id']], $updates);
}

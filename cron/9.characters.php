<?php

require_once '../init.php';

$counter = 0;
$information = $mdb->getCollection('information');
$queueCharacters = new RedisTimeQueue('tqCharacters', 86400);
$timer = new Timer();
$counter = 0;

while ($timer->stop() < 59000) {
    $ids = [];
    for ($i = 0; $i < 100; ++$i) {
        $id = $queueCharacters->next(false);
        if ($id != null) {
            $ids[] = $id;
        }
    }

    if (sizeof($ids) == 0) {
	sleep(1);
	continue;
    }

    $stringIDs = implode(',', $ids);
    $href = "https://api.eveonline.com/eve/CharacterAffiliation.xml.aspx?ids=$stringIDs";
    $raw = file_get_contents($href);
    if ($raw == '') {
        exit();
    }
    $xml = @simplexml_load_string($raw);

    foreach ($xml->result->rowset->row as $info) {
        $id = (int) $info['characterID'];
        $row = $mdb->findDoc('information', ['type' => 'characterID', 'id' => $id]);

        if (isset($info['characterName'])) {
            ++$counter;
	    //if (!isset($row['name'])) continue;
            if ((string) @$row['name'] != (string) $info['characterName']) {
                $mdb->set('information', $row, ['name' => (string) $info['characterName']]);
            }
            if (@$row['corporationID'] != (int) @$info['corporationID']) {
                $mdb->set('information', $row, ['corporationID' => (int) @$info['corporationID']]);
            }
            if (!$mdb->exists('information', ['type' => 'corporationID', 'id' => (int) $info['corporationID']])) {
                $mdb->insert('information', ['type' => 'corporationID', 'id' => (int) $info['corporationID'], 'name' => (string) $info['corporationName']]);
            }

            if (@$row['allianceID'] != (int) $info['allianceID']) {
                $mdb->set('information', $row, ['allianceID' => (int) @$info['allianceID']]);
            }
            if ($info['allianceID'] != 0 && !$mdb->exists('information', ['type' => 'allianceID', 'id' => (int) $info['allianceID']])) {
                $mdb->insert('information', ['type' => 'allianceID', 'id' => (int) $info['allianceID'], 'name' => (string) $info['allianceName']]);
            }

            if (@$row['factionID'] != (int) $info['factionID']) {
                $mdb->set('information', $row, ['factionID' => (int) @$info['factionID']]);
            }
            if ($info['factionID'] != 0 && !$mdb->exists('information', ['type' => 'factionID', 'id' => (int) $info['factionID']])) {
                $mdb->insert('information', ['type' => 'factionID', 'id' => (int) $info['factionID'], 'name' => (string) $info['factionName']]);
            }
        }
        $mdb->set('information', $row, ['lastApiUpdate' => new MongoDate(time())]);
    }
    sleep(1);
}

<?php

require_once '../init.php';

libxml_use_internal_errors(false);

$listName = "tqCharacters";
$type = "characterID";
$mdb->remove("information", ['id' => 0, 'type' => $type]);
$mdb->remove("information", ['id' => 1, 'type' => $type]);
Entities::populateList($mdb, $redis, $listName, $type);

$uri = "${apiServer}eve/CharacterInfo.xml.aspx?&characterId={id}";
Entities::iterateList($mdb, $redis, $listName, $type, $uri, "updateChar", 5);

function updateChar($mdb, $id, $raw) {
    try {
        $xml = @simplexml_load_string($raw);
        $charInfo = @$xml->result;
        if (!isset($charInfo->characterName)) {
            return;
        }

        $row = $mdb->findDoc("information", ['type' => 'characterID', 'id' => (int) $id]);
        $corpID = (int) $charInfo->corporationID;

        $updates = [];
        compareAttributes($updates, "name", @$row['name'], (string) $charInfo->characterName);
        compareAttributes($updates, "corporationID", @$row['corporationID'], (int) $charInfo->corporationID);
        compareAttributes($updates, "allianceID", @$row['allianceID'], (int) $charInfo->allianceID);
        compareAttributes($updates, "factionID", @$row['factionID'], (int) $charInfo->factionID);
        compareAttributes($updates, "secStatus", @$row['secStatus'], (double) $charInfo->securityStatus);

        $corpExists = $mdb->count('information', ['type' => 'corporationID', 'id' => $corpID]);
        if ($corpExists == 0) {
            $mdb->insertUpdate('information', ['type' => 'corporationID', 'id' => $corpID]);
        }

        $updates['lastApiUpdate'] = new MongoDate(time());
        $mdb->set("information", ['type' => 'characterID', 'id' => (int) $id], $updates);
        $xmlSuccess = new \cvweiss\redistools\RedisTtlCounter('ttlc:XmlSuccess', 300);
        $xmlSuccess->add(uniqid());
    } catch (Exception $ex) {
        print_r($ex);
        $xmlFailure = new \cvweiss\redistools\RedisTtlCounter('ttlc:XmlFailure', 300);
        $xmlFailure->add(uniqid());
    }
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}

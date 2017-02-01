<?php

require_once '../init.php';

libxml_use_internal_errors(false);

$listName = "tqCorporations";
$type = "corporationID";
$mdb->remove("information", ['id' => 0, 'type' => $type]);
$mdb->remove("information", ['id' => 1, 'type' => $type]);
Entities::populateList($mdb, $redis, $listName, $type);

$uri = "${apiServer}corp/CorporationSheet.xml.aspx?corporationID={id}";
Entities::iterateList($mdb, $redis, $listName, $type, $uri, "updateCorp", 10);

function updateCorp($mdb, $id, $raw) {
    try {
        $xml = @simplexml_load_string($raw);
        $corpInfo = @$xml->result;
        if (!isset($corpInfo->corporationName)) {
            return;
        }

        $row = $mdb->findDoc("information", ['type' => 'corporationID', 'id' => (int) $id]);
        $ceoID = (int) $corpInfo->ceoID;

        $updates = [];
        compareAttributes($updates, "name", @$row['name'], (string) $corpInfo->corporationName);
        compareAttributes($updates, "ticker", @$row['ticker'], (string) $corpInfo->ticker);
        compareAttributes($updates, "ceoID", @$row['ceoID'], $ceoID);
        compareAttributes($updates, "memberCount", @$row['memberCount'], (int) $corpInfo->memberCount);
        compareAttributes($updates, "allianceID", @$row['allianceID'], (int) $corpInfo->allianceID);
        compareAttributes($updates, "factionID", @$row['factionID'], (int) $corpInfo->factionID);

        // Does the CEO exist in our info table?
        $ceoExists = $mdb->count('information', ['type' => 'characterID', 'id' => $ceoID]);
        if ($ceoExists == 0) {
            $ceoName = (string) $corpInfo->ceoName;
            $mdb->insertUpdate('information', ['type' => 'characterID', 'id' => $ceoID], ['name' => $ceoName, 'corporationID' => $id]);
        }

        $updates['lastApiUpdate'] = new MongoDate(time());
        $mdb->set("information", ['type' => 'corporationID', 'id' => (int) $id], $updates);
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

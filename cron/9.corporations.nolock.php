<?php

require_once '../init.php';

$xmlFailure = new \cvweiss\redistools\RedisTtlCounter('ttlc:XmlFailure', 300);
$guzzler = new Guzzler(20, 50000);

$minute = date('Hi');
while ($minute == date('Hi')) {
    $row = $mdb->findDoc("information", ['type' => 'corporationID'], ['lastApiUpdate' => 1]);
    $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now()]);

    $url = "${apiServer}corp/CorporationSheet.xml.aspx?corporationID=" . $row['id'];
    $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
    $guzzler->call($url, "updateCorp", "failCorp", $params);
    if ($xmlFailure->count() > 200) sleep(1);
}
$guzzler->finish();

function failCorp(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];

    switch ($code) {
        case 0: // timeout
        case 503: // server error
        case 200: // timeout...
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(86400 * -2)]);
            break;
        default:
            Util::out("/corp/CorporationSheet failed for $id with code $code");
    }
    $xmllog = new \cvweiss\redistools\RedisTtlCounter('ttlc:XmlFailure', 300);
    $xmllog->add(uniqid());
}

function updateCorp(&$guzzler, &$params, &$content)
{
    $mdb = $params['mdb'];
    $row = $params['row'];

    $xml = @simplexml_load_string($content);
    $corpInfo = @$xml->result;
    if (!isset($corpInfo->corporationName)) {
        return;
    }

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

    if (sizeof($updates)) {
        $mdb->set("information", $row, $updates);
    }
    $xmlSuccess = new \cvweiss\redistools\RedisTtlCounter('ttlc:XmlSuccess', 300);
    $xmlSuccess->add(uniqid());
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}

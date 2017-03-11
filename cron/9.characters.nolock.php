<?php

require_once '../init.php';

$xmlFailure = new \cvweiss\redistools\RedisTtlCounter('ttlc:XmlFailure', 300);
$guzzler = new Guzzler(30, 1);

if ($redis->llen("zkb:char:pool") == 0) {
    $rows = $mdb->find("information", ['type' => 'characterID'], ['lastApiUpdate' => 1], 10000);
    foreach ($rows as $row) {
        $redis->rPush("zkb:char:pool", $row['id']);
    }
}

$minute = date('Hi');
while ($minute == date('Hi')) {
    $id = $redis->lPop("zkb:char:pool");
    if ($id == null) exit();
    $row = $mdb->findDoc("information", ['type' => 'characterID', 'id' => (int) $id]);
    $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now()]);

    $url = "${apiServer}eve/CharacterInfo.xml.aspx?&characterId=" . $row['id'];
    $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
    $guzzler->call($url, "updateChar", "failChar", $params);
    if ($xmlFailure->count() > 200) sleep(1);
}      
$guzzler->finish();

function failChar(&$guzzler, &$params, &$connectionException)
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
            Util::out("/eve/CharacterInfo failed for $id with code $code");
    }
    $xmllog = new \cvweiss\redistools\RedisTtlCounter('ttlc:XmlFailure', 300);
    $xmllog->add(uniqid());
}

function updateChar(&$guzzler, &$params, &$content)
{
    $mdb = $params['mdb'];
    $row = $params['row'];

    $xml = @simplexml_load_string($content);
    $charInfo = @$xml->result;
    if (!isset($charInfo->characterName)) {
        // Bad xml?
        return;
    }

    $id = $row['id'];
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

    if (sizeof($updates) > 0) {
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

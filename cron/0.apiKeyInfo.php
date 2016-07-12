<?php

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisTtlCounter;

$pid = 1;
for ($i = 0; $i < 30; ++$i) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        exit();
    }
    if ($pid == 0) {
        break;
    }
}

require_once '../init.php';

$minute = date('Hi');
$zkbApis = new RedisTimeQueue('zkb:apis', 14400);

if ($pid > 0) {
    $apis = $mdb->find('apis');
    foreach ($apis as $api) {
        $errorCode = (int) @$api['errorCode'];
        if (in_array($errorCode, [106, 203, 220, 222, 404])) {
            continue;
        }

        $_id = (string) $api['_id'];
        $zkbApis->add($_id);
    }
}

while ($minute == date('Hi') && ($_id = $zkbApis->next()) !== null) {
    $api = $mdb->findDoc('apis', ['_id' => new MongoID($_id)]);
    if ($api === null) {
        $zkbApis->remove($_id);
    } else {
        processApi($api, $apiServer, $mdb);
        sleep(1);
    }
}

function processApi($api, $apiServer, $mdb)
{
    $keyID = $api['keyID'];
    $vCode = $api['vCode'];
    $url = "$apiServer/account/APIKeyInfo.xml.aspx?keyID=$keyID&vCode=$vCode";

    $response = RemoteApi::getData($url);
    $content = $response['content'];
    $httpCode = $response['httpCode'];
    $xml = simplexml_load_string($content);

    switch ($httpCode) {
        case 200:
            processKeyInfo($mdb, $keyID, $vCode, $xml);
            updateErrorCode($mdb, $api, 0);
            break;
        default:
            $errorCode = (string) @$xml->error['code'];
            updateErrorCode($mdb, $api, $errorCode);
    }
}

function updateErrorCode($mdb, $api, $errorCode)
{
    $ttlName = $errorCode == 0 ? 'ttlc:XmlSuccess' : 'ttlc:XmlFailure';
    $ttlCounter = new RedisTtlCounter($ttlName, 300);
    $ttlCounter->add(uniqid());
    $mdb->set('apis', $api, ['errorCode' => (int) $errorCode, 'lastFetched' => time()]);
}

function processKeyInfo($mdb, $keyID, $vCode, $xml)
{
    // Ensure this is a Killmail API
    $accessMask = (int) (string) $xml->result->key['accessMask'];
    if (!($accessMask & 256)) {
        return;
    }

    // Get the type of API key we are working with here
    $type = $xml->result->key['type'];
    $type = $type == 'Account' ? 'Character' : $type;

    $rows = $xml->result->key->rowset->row;
    foreach ($rows as $c => $row) {
        $charID = (int) (string) $row['characterID'];
        $corpID = (int) (string) $row['corporationID'];
        addToDb($mdb, $type, $charID, $corpID, $keyID, $vCode);
    }
}

function addToDb($mdb, $type, $charID, $corpID, $keyID, $vCode)
{
    // Cleanup old associated keys just in case
    $mdb->remove("api$type", ['characterID' => $charID, 'corporationID' => ['$ne' => $corpID]]);

    $array = ['characterID' => $charID, 'corporationID' => $corpID, 'keyID' => $keyID, 'vCode' => $vCode];
    if ($mdb->count("api$type", $array) == 0) {
        $mdb->insert("api$type", $array);
    }
}

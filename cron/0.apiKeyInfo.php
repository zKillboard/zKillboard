<?php

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisTtlCounter;

$pid = 1;
$threadNum = 0;
$max = 30;
for ($i = 0; $i < $max; ++$i) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        exit();
    }
    if ($pid == 0) {
        break;
    }
    $threadNum++;
}

require_once '../init.php';

//if ($redis->llen("queueProcess") > 100) exit();
$minute = date('Hi');
$zkbApis = new RedisTimeQueue('zkb:apis', 14400);

if ($threadNum == $max - 1 && ($zkbApis->size() == 0 || date('i') == 15)) {
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
    global $redis;

    try {
        // Cleanup old associated keys just in case
        $mdb->remove("api$type", ['characterID' => $charID, 'corporationID' => ['$ne' => $corpID]]);

        $array = ['characterID' => $charID, 'corporationID' => $corpID, 'keyID' => $keyID, 'vCode' => $vCode];
        if ($mdb->count("api$type", $array) == 0) {
            $redisType = substr(strtolower($type), 0, 4);
            $r = new RedisTimeQueue("zkb:{$redisType}s", 3600);
            $r->add($redisType == 'char' ? $charID : $corpID);
            $mdb->insert("api$type", $array);
        }
    } catch (Exception $ex) {
        //Util::out("Error inserting record: (api$type) " . $ex->getMessage() . "\n" . print_r($array, true));
    }
}

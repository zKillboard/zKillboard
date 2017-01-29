<?php

require_once '../init.php';

libxml_use_internal_errors(false);

$curl = new \GuzzleHttp\Handler\CurlMultiHandler();
$handler = \GuzzleHttp\HandlerStack::create($curl);
$client = new \GuzzleHttp\Client(['connect_timeout' => 10, 'timeout' => 10, 'handler' => $handler]);

$information = $mdb->getCollection('information');
$queueChars = new \cvweiss\redistools\RedisTimeQueue('tqCharacters', 86400 * 3);

$query = ['type' => 'characterID'];
if (date ('i') != 30) {
    $query['lastApiUpdate'] = ['$exists' => false];
}
$chars = $information->find($query)->sort(['lastApiUpdate' => 1]);
foreach ($chars as $char) {
    if ($char['id'] === (string) $char['id'] || $char['id'] <= 599999) {
        $mdb->remove("information", $char);
        continue;
    }
    $queueChars->add($char['id']);
    $queueChars->setTime($char['id'], (int) @$char['lastApiUpdate']->sec);
}

$minute = date('Hi');
$count = 0;
while ($minute == date('Hi') && ($id = $queueChars->next()))
{
    $queueChars->setTime($id, time() + 300);

    $url = "https://api.eveonline.com/eve/CharacterInfo.xml.aspx?&characterId=$id";
    $client->getAsync($url)->then(function($response) use ($mdb, $id, $queueChars, &$count) {
            $count--;
            $raw = (string) $response->getBody();
            updateChar($mdb, $id, $raw, $queueChars);
            }, function($connectionException) use ($queueChars, $id, &$count) {   
            $count--;
            $queueChars->setTime($id, time() + 300);
            });

    $count++;
    do {
        $curl->tick();
    } while ($count > 30) ;
    usleep(50000);
}
$curl->execute();

function updateChar($mdb, $id, $raw, $queueChars) {
    try {
        $xml = simplexml_load_string($raw);
        if ($xml === false) {
            $queueChars->setTime($id, time() + 300);
        }

        $charInfo = $xml->result;

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
            $mdb->insertUpdate('information', ['type' => 'characterID', 'id' => $corpID]);
        }

        $updates['lastApiUpdate'] = new MongoDate(time());
        $mdb->set("information", ['type' => 'characterID', 'id' => (int) $id], $updates);
    } catch (Exception $ex) {
        print_r($ex);
        $queueChars->setTime($id, time() + 300);
    }
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}

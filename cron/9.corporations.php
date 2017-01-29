<?php

require_once '../init.php';

libxml_use_internal_errors(false);

$curl = new \GuzzleHttp\Handler\CurlMultiHandler();
$handler = \GuzzleHttp\HandlerStack::create($curl);
$client = new \GuzzleHttp\Client(['connect_timeout' => 10, 'timeout' => 10, 'handler' => $handler]);

$information = $mdb->getCollection('information');
$queueCorps = new \cvweiss\redistools\RedisTimeQueue('tqCorporations', 86400);

$query = ['type' => 'corporationID'];
if (date ('i') != 30) {
    $query['lastApiUpdate'] = ['$exists' => false];
}
$corps = $information->find($query)->sort(['lastApiUpdate' => 1]);
foreach ($corps as $corp) {
    if ($corp['id'] === (string) $corp['id'] || $corp['id'] <= 599999) {
        $mdb->remove("information", $corp);
        continue;
    }
    $queueCorps->add($corp['id']);
    $queueCorps->setTime($corp['id'], (int) @$corp['lastApiUpdate']->sec);
}

$minute = date('Hi');
$count = 0;
while ($minute == date('Hi') && ($id = $queueCorps->next()))
{
    $url = "https://api.eveonline.com/corp/CorporationSheet.xml.aspx?corporationID=$id";
    $client->getAsync($url)->then(function($response) use ($mdb, $id, $queueCorps, &$count) {
            $count--;
            $raw = (string) $response->getBody();
            updateCorp($mdb, $id, $raw, $queueCorps);
            }, function($connectionException) use ($queueCorps, $id, &$count) {   
            $count--;
            $queueCorps->setTime($id, time() + 300);
            });

    $count++;
    do {
        $curl->tick();
    } while ($count > 30) ;
    usleep(50000);
}
$curl->execute();

function updateCorp($mdb, $id, $raw, $queueCorps) {
    try {
        $xml = simplexml_load_string($raw);
        if ($xml === false) {
            $queueCorps->setTime($id, time() + 300);
        }

        $corpInfo = $xml->result;

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
    } catch (Exception $ex) {
        print_r($ex);
        $queueCorps->setTime($id, time() + 300);
    }
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}

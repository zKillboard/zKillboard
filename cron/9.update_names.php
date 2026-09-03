<?php

require_once "../init.php";

if ($kvc->get("zkb:noapi") == "true") exit();

$rset = "zkb:updatenames";
$nameQueue = new MongoQueue($mdb, $rset, true);
$rsetLoad = "zkb:updatenames:" . date('Ymd');
$rsetMonthlyCharacters = "zkb:updatenames:characters:" . date('Ym');
$rsetMonthlyCharactersLastID = "$rsetMonthlyCharacters:lastID";

$guzzler = new Guzzler();

if ($kvc->get($rsetMonthlyCharacters) != "true" && (date('j') == 1 || $kvc->get($rsetMonthlyCharactersLastID) !== null) && $nameQueue->count() <= 50000) {
    $lastID = (int) $kvc->get($rsetMonthlyCharactersLastID, 0);
    $rows = $mdb->getCollection('information')->find(['type' => 'characterID', 'id' => ['$gt' => $lastID]], ['projection' => ['_id' => 0, 'id' => 1], 'sort' => ['id' => 1], 'limit' => 5000]);
    $set = [];
    foreach ($rows as $row) {
        $set[] = $row['id'];
        $lastID = $row['id'];
    }
    if (sizeof($set) > 0) {
        foreach ($set as $id) $nameQueue->add($id);
        $kvc->setex($rsetMonthlyCharactersLastID, 86400 * 40, $lastID);
    } else {
        $kvc->setex($rsetMonthlyCharacters, 86400 * 40, "true");
        $kvc->del($rsetMonthlyCharactersLastID);
    }
}

if ($kvc->get($rsetLoad) != "true" && $nameQueue->count() <= 100) {
    addToRset($nameQueue, $mdb->getCollection('ninetyDays')->distinct('involved.characterID'));
    addToRset($nameQueue, $mdb->getCollection('ninetyDays')->distinct('involved.corporationID'));
    addToRset($nameQueue, $mdb->getCollection('ninetyDays')->distinct('involved.allianceID'));
}

$minute = date("Hi");

do {
    $set = array_values(array_unique(array_filter($nameQueue->popMany(1000), function ($value) {
        return $value != "" && $value != "1";
    })));
    if (sizeof($set) > 0) {
        doCall($guzzler, $mdb, $redis, $rset, $set);
        $guzzler->finish();
    }
    sleep(10);
} while ($minute == date("Hi"));

if ($nameQueue->count() == 0) $kvc->setex($rsetLoad, 86400, "true");

function doCall($guzzler, $mdb, $redis, $rset, $set) {
    $guzzler->call("https://esi.evetech.net/universe/names", "success", "fail", ['mdb' => $mdb, 'rset' => $rset, 'redis' => $redis, 'set' => $set], [], 'POST_JSON', json_encode($set));
}

function success(&$guzzler, &$params, &$content)
{
try {
    $mdb = $params['mdb'];
    $rset = $params['rset'];
    $redis = $params['redis'];
    $queue = new MongoQueue($mdb, $rset, true);

    $rows = json_decode($content, true);
    foreach ($rows as $row) {
        $name = $row['name'];
        $match = ['type' => $row['category'] . "ID", 'id' => $row['id']];
        $current = $mdb->findDoc("information", $match);

        // The name that almost got zkill kicked off of google....
        if (isset($current['obscene'])) {
            $name = ucfirst($row['category']) . " " . $row['id'];
            if (isset($current['ticker'])) {
                $mdb->set("information", ['type' => $row['category'] . "ID", 'id' => $row['id']], ['ticker' => "" . $row['id']]);
            }
        }

        if (@$current['name'] !== $name) {
            $currentName = @$current['name'];
            $mdb->set("information", ['type' => $row['category'] . "ID", 'id' => $row['id']], ['name' => $name, 'l_name' => strtolower($name)]);
        }
        $redis->srem($rset, $row['id']);
    }
    } catch (Exception $ex) {
        print_r($ex);
    }
}

function fail($guzzler, $params, $ex)
{
    $mdb = $params['mdb'];
    $rset = $params['rset'];
    $redis = $params['redis'];
    $queue = new MongoQueue($mdb, $rset, true);

    $set = $params['set'];

    if (sizeof($set) == 1) {
        $id = (int) $set[0];
        $current = $mdb->findDoc('information', ['type' => ['$in' => ['characterID', 'corporationID', 'allianceID', 'factionID']], 'id' => $id]);
        if ($current != null && in_array($ex->getCode(), [404, 422])) {
            $entityType = ucfirst(str_replace('ID', '', $current['type']));
            $currentName = $current['name'] ?? '';
            if (in_array($currentName, ['', "$entityType $id", $current['type'] . " $id"])) {
                $name = "Deleted $entityType $id";
                $mdb->set('information', ['type' => $current['type'], 'id' => $id], ['name' => $name, 'l_name' => strtolower($name)]);
            }
        }
        Util::out("Failure to resolve name for ID: $id - " . $ex->getMessage());
        $redis->srem($rset, $id);
        $queue->remove($id);
        return;
    }

    $half = ceil(count($set) / 2);
    list($part1, $part2) = array_chunk($set, $half);

    Util::out("9.update_names.php splitting results... " . sizeof($set));
    doCall($guzzler, $mdb, $redis, $rset, $part1);
    doCall($guzzler, $mdb, $redis, $rset, $part2);
}

function addToRSet($queue, $cursor) {
    foreach ($cursor as $row) {
        $queue->add($row);
    }

}

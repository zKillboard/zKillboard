<?php

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once "../init.php";

$minute = date("Hi");
while ($minute == date("Hi")) {
    $row = $mdb->findDoc("queues", ['queue' => 'pvpfest']);
    if ($row === null) {
        sleep(1);
        continue;
    }

    $killID = $row['value'];
    $kill = $mdb->findDoc("killmails", ['killID' => $killID]);
    $unixtime = $kill['dttm']->toDateTime()->getTimestamp();
    if ($unixtime < 1770634800 || $unixtime > 1771326000) {
        $mdb->getCollection("queues")->deleteOne(['_id' => $row['_id']]);
        continue;
    }

    $involved = $kill['involved'];
    $vic = $involved[0];

    $att = getFinalBlow($involved, $killID, $kill);
    $loc = getLoc($kill, $killID);

    if (!isset($vic['characterID']) || !isset($att['characterID'])) {
        $mdb->getCollection("queues")->deleteOne(['_id' => $row['_id']]);
        continue;
    }

    $att_vic = $att['characterID'] . "_" . $vic['characterID'];
    $att_ship_loc = $att['characterID'] . "_" . $vic['shipTypeID'] . "_" . $loc;

    $record = [
        "att_vic" => $att_vic,
        "att_ship_loc" => $att_ship_loc,
        "attacker_id" => $att['characterID'],
        "victim_id" => $vic['characterID'],
        "ship_type_id" => $vic['shipTypeID'],
        "loc" => $loc,
        "killID" => $killID,
        "unixtime" => $unixtime
    ];
        //echo "$att_vic $att_ship_loc $killID\n";
        $mdb->getCollection("pvpfest")->deleteMany(['att_vic' => $att_vic, 'killID' => ['$gt' => $killID]]);
        $mdb->getCollection("pvpfest")->deleteMany(['att_ship_loc' => $att_ship_loc, 'killID' => ['$gt' => $killID]]);
        $count_att_vic = $mdb->getCollection("pvpfest")->countDocuments(['att_vic' => $att_vic]);
        $count_att_ship_loc = $mdb->getCollection("pvpfest")->countDocuments(['att_ship_loc' => $att_ship_loc]);
        if ($count_att_vic == 0 && $count_att_ship_loc == 0) {
            $mdb->getCollection("pvpfest")->insertOne($record);
        }
        $mdb->getCollection("queues")->updateOne(
                ['_id' => $row['_id']],
                ['$set' => ['queue' => 'pvpfest-backup']]
                );

}

function getFinalBlow($involved, $killID, $kill) {
    foreach ($involved as $inv) {
        if (isset($inv['finalBlow']) && $inv['finalBlow'] === true && isset($inv['characterID'])) return $inv;
    }

    // NPC somehow got a final blow, look for the first record that has a characterID instead
    foreach ($involved as $inv) {
        if (isset($inv['characterID']) && $inv['characterID'] > 0) return $inv;
    }
    throw new Exception("no final blow?! $killID");
}

function getLoc($kill, $killID) {
    if ($kill['system']['solarSystemID'] === 30100000) return "loc:nullsec";
    if ($kill['system']['regionID'] === 10000070) return "loc:pochven";

    $labels = $kill['labels'];
    foreach ($labels as $label) {
        if (substr($label, 0, 4) == "loc:") return $label;
    }

    throw new Exception("no location?! $killID");
}

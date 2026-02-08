<?php

require_once "../init.php";

$hour = date("H");
$key = "zkb:pvpfest_rankings:$hour";

// Only run once per hour
if ($redis->get($key) == true) {
    exit();
}

Util::out("Calculating PvP Fest rankings...");

// Define regions
$regions = [
    'overall' => null,
    'loc:lowsec' => 'loc:lowsec',
    'loc:highsec' => 'loc:highsec',
    'loc:nullsec' => 'loc:nullsec',
    'loc:pochven' => 'loc:pochven',
    'loc:w-space' => 'loc:w-space'
];

$pvpfestColl = $mdb->getCollection('pvpfest');

$updates = [];

foreach ($regions as $regionKey => $locFilter) {
    Util::out("Processing region: $regionKey");
    
    // Build aggregation pipeline
    $pipeline = [];
    
    // Add location filter if not overall
    if ($locFilter !== null) {
        $pipeline[] = ['$match' => ['loc' => $locFilter]];
    }
    
    // Group by attacker and calculate stats
    $pipeline[] = ['$group' => [
        '_id' => '$attacker_id',
        'kills' => ['$sum' => 1],
        'sumKillID' => ['$sum' => '$killID'],
        'sumUnixtime' => ['$sum' => '$unixtime']
    ]];
    
    // Sort by: kills DESC, sumKillID ASC, sumUnixtime ASC, _id ASC
    $pipeline[] = ['$sort' => [
        'kills' => -1,
        'sumKillID' => 1,
        'sumUnixtime' => 1,
        '_id' => 1
    ]];
    
    $result = $pvpfestColl->aggregate($pipeline);
    
    // Assign ranks
    $rank = 1;
    foreach ($result as $row) {
        $attackerId = $row['_id'];
        if (!isset($updates[$attackerId])) {
            $updates[$attackerId] = [];
        }
        $updates[$attackerId][$regionKey] = $rank;
        $rank++;
    }
    
    Util::out("  Found " . ($rank - 1) . " pilots");
}

// Now update the pvpfest_rankings collection
Util::out("Writing rankings to database...");
$rankingsColl = $mdb->getCollection('pvpfest_rankings');

$bulkOps = [];
foreach ($updates as $attackerId => $rankings) {
    $bulkOps[] = [
        'updateOne' => [
            ['attacker_id' => $attackerId],
            ['$set' => [
                'attacker_id' => $attackerId,
                'rankings' => $rankings,
                'updated' => time()
            ]],
            ['upsert' => true]
        ]
    ];
    
    // Execute in batches of 1000
    if (count($bulkOps) >= 1000) {
        $rankingsColl->bulkWrite($bulkOps);
        $bulkOps = [];
    }
}

// Execute remaining operations
if (count($bulkOps) > 0) {
    $rankingsColl->bulkWrite($bulkOps);
}

Util::out("Rankings calculation complete!");

$redis->setex($key, 3700, true);

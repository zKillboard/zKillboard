<?php

require_once '../init.php';

$redisKey = "zkb:zest3_populate_info_stats";
if ($redis->get($redisKey) == true) exit();

$tasks = $mdb->getCollection('zest3_tasks');

iterate($mdb, $tasks, 'statistics');
iterate($mdb, $tasks, 'information');

$redis->setex($redisKey, 10000, "true");

function iterate($mdb, $tasks, $coll) {
    $inserted = 0;
    $cursor = $mdb->getCollection($coll)->find(
            [],
            [
            'sort' => ['_id' => -1],
            'projection' => [
                'type' => 1,
                'id' => 1,
                'sequence' => 1,
                '_id' => 0,
                ],
            ]
            );


    $ops = [];
    $batchSize = 1000;

    foreach ($cursor as $row) {
        $type = $row['type'];

        // Some types we want to flat out skip
        switch($type) {
            case 'warID':
            case 'typeID':
            case 'categoryID':
            case 'starID':
            case 'marketGroupID':
                continue 2;
            case 'characterID':
            case 'corporationID':
            case 'allianceID':
            case 'factionID':
            case 'shipTypeID':
            case 'groupID':
            case 'locationID':
            case 'solarSystemID':
            case 'constellationID':
            case 'regionID':
            case 'label':
                break;
            default:
                throw new Exception("Unknown type ($type) in collection $coll");
        }

        $id = $row['id'];
        $sequence = (int)($row['sequence'] ?? 0);

        if ($type != "label" && $id <= 0) continue; 

        $ops[] = [
            'updateOne' => [
                ['type' => $type, 'id' => $id],
                [
                    '$setOnInsert' => [
                        'type' => $type,
                'id' => $id,
                'killmails' => true,
                'mixed' => false,
                'sequence' => $sequence,
                    ],
                ],
                ['upsert' => true],
            ],
        ];

            if (count($ops) >= $batchSize) {
                $r = $tasks->bulkWrite($ops, ['ordered' => false]);
                $ops = [];
                $inserted += $r->getInsertedCount();
                if ($inserted == 0) break;
            }
    }

    if ($ops !== []) {
        $r = $tasks->bulkWrite($ops, ['ordered' => false]);
        $inserted += $r->getInsertedCount();
    }

    if ($inserted > 0) Util::out("Added $inserted for zest3_tasks from $coll");
}


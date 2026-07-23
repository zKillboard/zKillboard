<?php

function handler($request, $response, $args, $container)
{
    global $mdb, $redis;

    $search = trim(rawurldecode((string) ($args['ship'] ?? '')));
    $runID = $redis->get('zkb:fitKillers:runID');
    $meta = json_decode($redis->get('zkb:fitKillers:meta'), true);
    $selectedShip = null;
    $shipMatches = [];

    if ($runID == null) {
        $latest = $mdb->findDoc('fitkillers', [], ['updated' => -1], ['runID' => 1]);
        $runID = $latest['runID'] ?? null;
    }

    if ($search != '') {
        if (is_numeric($search)) {
            $typeID = (int) $search;
            $info = Info::getInfo('typeID', $typeID);
            if ((int) ($info['categoryID'] ?? 0) == 6) {
                $shipMatches[] = [
                    'id' => $typeID,
                    'name' => $info['name'] ?? "Ship $typeID",
                    'type' => 'ship',
                    'pip' => $info['pip'] ?? '',
                ];
            }
        } else {
            $regex = '^' . strtolower(preg_quote($search));
            $rows = $mdb->find('information', ['type' => 'typeID', 'published' => true, 'categoryID' => 6, 'l_name' => ['$regex' => $regex]], ['l_name' => 1], 8, ['id' => 1]);
            foreach ($rows as $row) {
                $typeID = (int) ($row['id'] ?? 0);
                if ($typeID <= 0) continue;
                $info = Info::getInfo('typeID', $typeID);
                $shipMatches[] = [
                    'id' => $typeID,
                    'name' => $info['name'] ?? "Ship $typeID",
                    'type' => 'ship',
                    'pip' => $info['pip'] ?? '',
                ];
            }
        }
        if (sizeof($shipMatches) > 0) $selectedShip = $shipMatches[0];
        if ($selectedShip != null && $search != (string) ($selectedShip['id'] ?? 0)) {
            return $response->withStatus(302)->withHeader('Location', '/fits/' . (int) $selectedShip['id'] . '/');
        }
    }

    $shipGroups = [];
    if ($search == '' || $selectedShip != null) {
        $query = [];
        if ($runID != null) $query['runID'] = $runID;
        if ($selectedShip != null) $query['shipTypeID'] = (int) $selectedShip['id'];

        $shipRows = $mdb->getCollection('fitkillers')->aggregate([
            ['$match' => $query],
            ['$group' => [
                '_id' => [
                    'shipTypeID' => '$shipTypeID',
                    'shipName' => '$shipName',
                    'pip' => '$pip',
                ],
                'kills' => ['$sum' => '$kills'],
                'weightedKills' => ['$sum' => '$weightedKills'],
                'losses' => ['$sum' => '$losses'],
                'iskDestroyed' => ['$sum' => '$iskDestroyed'],
                'fitCount' => ['$sum' => 1],
            ]],
        ], ['maxTimeMS' => 30000]);

        $fitStats = [];
        foreach ($shipRows as $shipRow) {
            $shipTypeID = (int) ($shipRow['_id']['shipTypeID'] ?? 0);
            if ($shipTypeID <= 0) continue;
            $fitStats[$shipTypeID] = $shipRow;
        }

        $rankRows = [];
        if ($selectedShip != null && isset($fitStats[(int) $selectedShip['id']])) {
            $rankRows[(int) $selectedShip['id']] = Ranks::getRow('recent', 'all', 'shipTypeID', (int) $selectedShip['id']);
        } else if (sizeof($fitStats) > 0) {
            $pageDoc = Ranks::getPage('recent', 'all', 'shipTypeID', 'shipsDestroyed', 'desc', 1);
            foreach ($pageDoc['ids'] ?? [] as $shipTypeID) {
                $shipTypeID = (int) $shipTypeID;
                if (!isset($fitStats[$shipTypeID])) continue;
                $rankRows[$shipTypeID] = $pageDoc['rows'][$shipTypeID] ?? null;
                if (sizeof($rankRows) >= 50) break;
            }
        }

        foreach ($rankRows as $shipTypeID => $rankRow) {
            $shipRow = $fitStats[$shipTypeID] ?? null;
            if ($shipRow == null) continue;

            $fits = $mdb->find(
                'fitkillers',
                array_merge($runID != null ? ['runID' => $runID] : [], ['shipTypeID' => $shipTypeID]),
                ['kills' => -1, 'weightedKills' => -1, 'losses' => 1],
                5,
                ['_id' => 0]
            );
            foreach ($fits as $fitIndex => &$fit) {
                $fit['displayRank'] = $fitIndex + 1;
            }
            unset($fit);

            $shipGroups[] = [
                'displayRank' => $rankRow['ranks']['shipsDestroyed'] ?? null,
                'shipTypeID' => $shipTypeID,
                'shipName' => $shipRow['_id']['shipName'] ?? '',
                'pip' => $shipRow['_id']['pip'] ?? '',
                'recentKills' => (int) ($rankRow['metrics']['shipsDestroyed'] ?? 0),
                'fitKills' => (int) ($shipRow['kills'] ?? 0),
                'weightedKills' => round((float) ($shipRow['weightedKills'] ?? 0), 3),
                'losses' => (int) ($shipRow['losses'] ?? 0),
                'iskDestroyed' => round((float) ($shipRow['iskDestroyed'] ?? 0), 2),
                'fitCount' => (int) ($shipRow['fitCount'] ?? 0),
                'fits' => $fits,
                'fitCells' => array_pad($fits, 5, null),
            ];
        }
    }

    $response = $response
        ->withHeader('Cache-Control', 'public, max-age=3600, s-maxage=3600')
        ->withHeader('Cache-Tag', 'www,fits');

    return $container->get('view')->render(
        $response,
        'fits.pug',
        [
            'shipGroups' => $shipGroups,
            'search' => $search,
            'searchDisplay' => $selectedShip['name'] ?? $search,
            'selectedShip' => $selectedShip,
            'shipMatches' => $shipMatches,
            'meta' => $meta,
        ]
    );
}

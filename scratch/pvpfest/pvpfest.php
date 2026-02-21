<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $pvpfestURI;

    // Define regions
    $regions = [
        'overall' => 'Overall',
        'loc:lowsec' => 'Lowsec',
        'loc:highsec' => 'Highsec',
        'loc:nullsec' => 'Nullsec',
        'loc:pochven' => 'Pochven',
        'loc:w-space' => 'W-Space'
    ];

    $leaderboards = [];
    $rankingsColl = $mdb->getCollection('pvpfest_rankings');
    $pvpfestColl = $mdb->getCollection('pvpfest');

    foreach ($regions as $loc => $displayName) {
        // Get top 25 from rankings collection for this region
        $pipeline = [];
        
        // Match documents that have a ranking for this region
        $pipeline[] = ['$match' => [
            "rankings.$loc" => ['$exists' => true, '$gt' => 0]
        ]];
        
        // Sort by rank
        $pipeline[] = ['$sort' => ["rankings.$loc" => 1]];
        
        // Limit to top 25
        $pipeline[] = ['$limit' => 25];
        
        $result = $rankingsColl->aggregate($pipeline);
        
        $topPilots = [];
        foreach ($result as $row) {
            $attackerId = $row['attacker_id'];
            $rank = $row['rankings'][$loc] ?? 0;
            
            // Get kill count for this region
            $filter = ['attacker_id' => $attackerId];
            if ($loc !== 'overall') {
                $filter['loc'] = $loc;
            }
            $kills = $mdb->count('pvpfest', $filter);
            
            $topPilots[] = [
                'rank' => $rank,
                'characterID' => $attackerId,
                'kills' => $kills
            ];
        }
        
        // Add character info
        Info::addInfo($topPilots);
        
        // Add corp/alli info for top 10 pilots
        for ($i = 0; $i < min(10, count($topPilots)); $i++) {
            $charId = $topPilots[$i]['characterID'];
            $charInfo = Info::getInfo('characterID', $charId);
            
            if ($charInfo) {
                // Get corporation info
                if (!empty($charInfo['corporationID'])) {
                    $corpInfo = Info::getInfo('corporationID', $charInfo['corporationID']);
                    $topPilots[$i]['corporationID'] = $charInfo['corporationID'];
                    $topPilots[$i]['corporationName'] = $corpInfo['name'] ?? null;
                }
                
                // Get alliance info
                if (!empty($charInfo['allianceID'])) {
                    $alliInfo = Info::getInfo('allianceID', $charInfo['allianceID']);
                    $topPilots[$i]['allianceID'] = $charInfo['allianceID'];
                    $topPilots[$i]['allianceName'] = $alliInfo['name'] ?? null;
                }
            }
        }
        
        $leaderboards[$displayName] = $topPilots;
    }

    // Get total participants
    $totalParticipants = $pvpfestColl->aggregate([
        ['$group' => ['_id' => '$attacker_id']],
        ['$count' => 'total']
    ]);
    $participantCount = 0;
    foreach ($totalParticipants as $tp) {
        $participantCount = $tp['total'];
    }

    // Prepare data for template
    $data = [
        'leaderboards' => $leaderboards,
        'totalParticipants' => $participantCount,
        'pvpfestURI' => $pvpfestURI
    ];

    return $container->get('view')->render(
        $response
			->withHeader('Cache-Tag', "pvpfest,overview"),
        'pvpfest.html',
        $data
    );
}

<?php

function pvpfestHandler($request, $response, $args, $container) {
    global $mdb, $redis, $pvpfestURI;

    // Extract parameters (comes from overview.php)
    $inputString = $args['input'] ?? '';
    $input = explode('/', trim($inputString, '/'));

    $key = $input[0]; // character, corporation, or alliance
    $id = (int) $input[1];

    if ($key != 'character' || $id == 0) {
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    // Define regions
    $regions = [
        'overall' => null, // null means all locations
        'loc:lowsec' => 'lowsec',
        'loc:highsec' => 'highsec',
        'loc:nullsec' => 'nullsec',
        'loc:pochven' => 'pochven',
        'loc:w-space' => 'w-space'
    ];

    $killmailRegionTabs = [
        'overall' => ['label' => 'Overall', 'loc' => null],
        'lowsec' => ['label' => 'Lowsec', 'loc' => 'loc:lowsec'],
        'highsec' => ['label' => 'Highsec', 'loc' => 'loc:highsec'],
        'nullsec' => ['label' => 'Nullsec', 'loc' => 'loc:nullsec'],
        'pochven' => ['label' => 'Pochven', 'loc' => 'loc:pochven'],
        'w-space' => ['label' => 'W-Space', 'loc' => 'loc:w-space']
    ];

    // Get character info
    $info = Info::getInfo("characterID", $id);
    if ($info === null) {
        return $response->withStatus(302)->withHeader('Location', '/');
    }
    
    // Ensure characterID and characterName are set for the template
    $info['characterID'] = $id;
    if (!isset($info['characterName'])) {
        $info['characterName'] = $info['name'] ?? 'Unknown';
    }

    // Build stats for each region
    $stats = [];
    $pvpfestColl = $mdb->getCollection('pvpfest');
    
    // Get pre-calculated rankings
    $rankingsDoc = $mdb->findDoc('pvpfest_rankings', ['attacker_id' => $id]);
    $rankings = $rankingsDoc['rankings'] ?? [];
    
    foreach ($regions as $loc => $displayName) {
        $filter = ['attacker_id' => $id];
        if ($loc !== 'overall') {
            $filter['loc'] = $loc;
        }
        
        // Count kills for this character in this region
        $kills = $mdb->count('pvpfest', $filter);
        
        // Get rank from pre-calculated rankings
        $rank = $rankings[$loc] ?? 0;
        
        $regionName = $displayName ?? 'overall';
        $stats[$regionName] = [
            'kills' => $kills,
            'rank' => $rank
        ];
    }

    // Killmail lists for each region tab
    $killmailLimit = null;
    $killmailTabs = [];
    foreach ($killmailRegionTabs as $tabKey => $tabConfig) {
        $filter = ['attacker_id' => $id];
        if (!empty($tabConfig['loc'])) {
            $filter['loc'] = $tabConfig['loc'];
        }

        $cursor = $pvpfestColl->find(
            $filter,
            [
                'projection' => ['killID' => 1],
                'sort' => ['killID' => -1]
            ]
        );

        $killIds = [];
        foreach ($cursor as $row) {
            if (isset($row['killID'])) $killIds[] = (int) $row['killID'];
        }

        $killmailTabs[$tabKey] = [
            'label' => $tabConfig['label'],
            'ids' => $killIds,
            'count' => count($killIds)
        ];
    }

    // Get some additional details
    $totalParticipants = $pvpfestColl->aggregate([
        ['$group' => ['_id' => '$attacker_id']],
        ['$count' => 'total']
    ]);
    $participantCount = 0;
    foreach ($totalParticipants as $tp) {
        $participantCount = $tp['total'];
    }

    // Get latest kill timestamp
    $latestKill = $mdb->findDoc('pvpfest', ['attacker_id' => $id], ['unixtime' => -1]);
    $lastKillTime = $latestKill ? $latestKill['unixtime'] : 0;

    // Prepare data for template
    $data = [
        'info' => $info,
        'stats' => $stats,
        'totalParticipants' => $participantCount,
        'lastKillTime' => $lastKillTime,
        'characterID' => $id,
        'pvpfestURI' => $pvpfestURI,
        'killmailTabs' => $killmailTabs,
        'killmailLimit' => $killmailLimit,
        'entityType' => 'character',
        'entityID' => $id,
        'pageType' => $pvpfestURI
    ];

    return $container->get('view')->render(
        $response
            ->withHeader('Cache-Tag', "pvpfest:$id,pvpfest,overview"),
        'pvpfest_char.html',
        $data
    );
}

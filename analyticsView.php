<?php

require_once 'init.php';

while (true) {
    // Get top IPs
    $ipPipeline = [
        ['$group' => ['_id' => '$ip', 'count' => ['$sum' => 1]]],
        ['$sort' => ['count' => -1]],
        ['$limit' => 10]
    ];
    $ips = iterator_to_array($mdb->getCollection('visitorlog')->aggregate($ipPipeline));
    
    // Get top URIs
    $uriPipeline = [
        ['$group' => ['_id' => '$uri', 'count' => ['$sum' => 1]]],
        ['$sort' => ['count' => -1]],
        ['$limit' => 10]
    ];
    $uris = iterator_to_array($mdb->getCollection('visitorlog')->aggregate($uriPipeline));
    
    // Get top user agents
    $agentPipeline = [
        ['$group' => ['_id' => '$agent', 'count' => ['$sum' => 1]]],
        ['$sort' => ['count' => -1]],
        ['$limit' => 10]
    ];
    $agents = iterator_to_array($mdb->getCollection('visitorlog')->aggregate($agentPipeline));
    
    system('clear');
    echo "Top IPs:\n";
    show10($ips);
    echo "Top URIs:\n";
    show10($uris);
    echo "Top User Agents:\n";
    show10($agents);
    sleep(5);
}

function show10($results)
{
    foreach ($results as $result) {
        $count = $result['count'];
        $value = $result['_id'];
        echo "$count $value\n";
    }
    echo "\n";
}

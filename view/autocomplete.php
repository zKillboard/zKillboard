<?php

$query = '';
$results = array();
//get the query value
if ($app->request()->isPost()) {
    $query = $app->request()->post('query');
}

//declare the base data/sql etc
$entities = array(
        array('type' => 'ship',        'query' => 'select id, name from zz_name_search where type = "typeID" and flag = "ship" and name like :query', 'image' => 'Type/%1$d_32.png'),
        array('type' => 'region',      'query' => 'select id, name from zz_name_search where type = "regionID" and name like :query LIMIT 9', 'image' => ''),
        array('type' => 'system',      'query' => 'select id, name from zz_name_search where type = "solarSystemID" and name like :query', 'image' => ''),
        array('type' => 'faction',     'query' => 'select id, name from zz_name_search where type = "factionID" and name like :query', 'image' => 'Alliance/%1$d_32.png'),
        array('type' => 'alliance',    'query' => 'select id, name from zz_name_search where type = "allianceID" and (name like :query or flag like :query) limit 9', 'image' => 'Alliance/%1$d_32.png'),
        array('type' => 'corporation', 'query' => 'select id, name from zz_name_search where type = "corporationID" and (name like :query or flag like :query) limit 9', 'image' => 'Corporation/%1$d_32.png'),
        array('type' => 'character',   'query' => 'select id, name from zz_name_search where type = "characterID" and name like :query limit 9', 'image' => 'Character/%1$d_32.jpg'),
        array('type' => 'item',        'query' => 'select id, name from zz_name_search where type = "typeID" and flag != "ship" and name like :query', 'image' => 'Type/%1$d_32.png'),
        );

//define our array for the results
$search_results = array();

$ids = array();
//for each entity type, get any matches and process them
foreach ($entities as $key => $entity) {
    $results1 = Db::query($entity['query'], array(':query' => $query), 30); //see if we have any things that exactly matches the thing
    $results2 = Db::query($entity['query'], array(':query' => $query.'%'), 30); //see if we have any things that matches the thing
    if ($results1 == null) {
        $results1 = [];
    }
    if ($results2 == null) {
        $results2 = [];
    }
    $results = array_merge($results1, $results2);
    if (sizeof($results) > 10) {
        $results = array_slice($results, 0, 10);
    }

    //merge the reults into an single array to throw back to the browser
    foreach ($results as $result) {
        if (!in_array($result['id'], $ids)) {
            $search_results[] = array_merge($result, array('type' => $entity['type'], 'image' => sprintf($entity['image'], $result['id'])));
            $ids[] = $result['id'];
        }
    }
}

// Declare out json return type
$app->contentType('application/json; charset=utf-8');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

//return the top 15 results as a json object
echo json_encode(array_slice($search_results, 0, 15));

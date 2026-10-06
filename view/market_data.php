<?php

function handler($request, $response, $args, $container)
{
    global $mdb, $redis;

    $cacheKey = 'market:data:v6';
    $json = $redis->get($cacheKey);
    if ($json === false || $json === null) {
        $types = $mdb->find('information', [
            'type' => 'typeID',
            'published' => true,
            'market_group_id' => ['$gt' => 0],
        ], ['l_name' => 1], null, ['_id' => 0, 'id' => 1, 'name' => 1, 'groupID' => 1]);

        $groupIDs = array_values(array_unique(array_map(fn($row) => (int) ($row['groupID'] ?? 0), $types)));
        $groupRows = $mdb->find('information', ['type' => 'groupID', 'id' => ['$in' => $groupIDs]], [], null, ['_id' => 0, 'id' => 1, 'name' => 1, 'categoryID' => 1]);
        $groupsByID = [];
        $categoryIDs = [];
        foreach ($groupRows as $row) {
            $groupID = (int) ($row['id'] ?? 0);
            $categoryID = (int) ($row['categoryID'] ?? 0);
            $groupsByID[$groupID] = ['name' => (string) ($row['name'] ?? "Group $groupID"), 'categoryID' => $categoryID];
            $categoryIDs[$categoryID] = true;
        }

        $categoryRows = $mdb->find('information', ['type' => 'categoryID', 'id' => ['$in' => array_keys($categoryIDs)]], [], null, ['_id' => 0, 'id' => 1, 'name' => 1]);
        $categoriesByID = [];
        foreach ($categoryRows as $row) $categoriesByID[(int) ($row['id'] ?? 0)] = (string) ($row['name'] ?? 'Other');

        $groups = [];
        foreach ($types as $row) {
            $groupID = (int) ($row['groupID'] ?? 0);
            if (!isset($groupsByID[$groupID])) continue;
            $categoryName = $categoriesByID[$groupsByID[$groupID]['categoryID']] ?? 'Other';
            $groupName = $groupsByID[$groupID]['name'];
            $name = (string) ($row['name'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || $name === '') continue;
            if (!isset($groups[$categoryName])) $groups[$categoryName] = ['id' => $groupsByID[$groupID]['categoryID'], 'subgroups' => [], 'items' => []];
            if (!isset($groups[$categoryName]['subgroups'][$groupName])) $groups[$categoryName]['subgroups'][$groupName] = ['id' => $groupID, 'subgroups' => [], 'items' => []];
            $groups[$categoryName]['subgroups'][$groupName]['items'][$name] = ['item_id' => $id, 'name' => $name, 'category_id' => $groupsByID[$groupID]['categoryID']];
        }

        $regions = [];
        foreach ($mdb->find('information', ['type' => 'regionID'], ['l_name' => 1], null, ['_id' => 0, 'id' => 1, 'name' => 1]) as $row) {
            $regionID = (int) ($row['id'] ?? 0);
            if ($regionID === 10000019 || ($regionID >= 11000000 && $regionID < 13000000)) continue;
            $regions[] = ['id' => $regionID, 'name' => (string) ($row['name'] ?? '')];
        }

        $locations = [];
        $stations = $mdb->getCollection('sde_npcStations')->find([], ['projection' => ['_id' => 0, '_key' => 1, 'name.en' => 1]]);
        foreach ($stations as $row) {
            $id = (int) ($row['_key'] ?? 0);
            $name = (string) ($row['name']['en'] ?? '');
            if ($id > 0 && $name !== '') $locations[(string) $id] = $name;
        }
        $structures = $mdb->getCollection('marketStructures')->find([], ['projection' => ['_id' => 0, 'structureID' => 1, 'name' => 1]]);
        foreach ($structures as $row) {
            $id = (int) (string) ($row['structureID'] ?? 0);
            $name = (string) ($row['name'] ?? '');
            if ($id > 0 && $name !== '') $locations[(string) $id] = $name;
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        $json = json_encode(['groups' => $groups, 'regions' => $regions, 'locations' => $locations], JSON_UNESCAPED_SLASHES);
        $redis->setex($cacheKey, 86400, $json);
    }

    $response->getBody()->write($json);
    return $response
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withHeader('Cache-Control', 'public, max-age=86400, s-maxage=86400')
        ->withHeader('Cache-Tag', 'www,market-data');
}

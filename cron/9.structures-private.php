<?php

require_once "../init.php";

if ($redis->get("zkb:noapi") == "true") exit();
if ($redis->get("zkb:reinforced") == true) exit();

$guzzler = new Guzzler(1);

$minute = date('Hi');
$scopes = $mdb->find("scopes", ['scope' => 'esi-corporations.read_structures.v1']);
foreach ($scopes as $scope) {
    if ($minute != date('Hi')) break;
    if (@$scope['lastChecked'] > (time() - 86400)) continue;
    if (!isset($scope['corporationID'])) continue;

    $corpID = $scope['corporationID'];
    if ($mdb->count("scopes", ['corporationID' => $corpID, 'scope' => 'esi-universe.read_structures.v1']) == 0) continue;

    $params = ['mdb' => $mdb, 'row' => $scope];
    CrestSSO::getAccessTokenCallback($guzzler, $scope['refreshToken'], "accessTokenDone", "accessTokenFail", $params);
}
$guzzler->finish();

function accessTokenDone($guzzler, $params, $content) {
    global $esiServer;

    $response = json_decode($content, true);
    $accessToken = $response['access_token'];
    //$params['content'] = $content;
    $row = $params['row'];
    $mdb = $params['mdb'];
    $corpID = @$row['corporationID'];

    $headers = [];
    $headers['Content-Type'] = 'application/json';
    $headers['Authorization'] = "Bearer $accessToken";
    //$headers['etag'] = true;

    $params = ['row' => $row, 'mdb' => $mdb];

    $route = "$esiServer/v3/corporations/$corpID/structures/";
    $guzzler->call($route, "success", "fail", $params, $headers, 'GET');
}

function accessTokenFail($guzzler, $params, $ex) {
    $mdb = $params['mdb'];
    $code = $ex->getCode();
    
    switch ($code) {
        case 400:
        case 403:
            $mdb->remove("scopes", $params['row']);
            break;
        default:
        echo "$code access token failed...\n";
    }
}

function success($guzzler, $params, $content) {
    $mdb = $params['mdb'];
    $row = $params['row'];

    $json = json_decode($content, true);
    foreach ($json as $structure) {
        $typeID = $structure['type_id'];
        $catID = Info::getInfoField("typeID", $typeID, "categoryID");
        if ($catID != 65) continue;

        $sid = $structure['structure_id'];
        $corpID = (int) $structure['corporation_id'];
        if ($corpID == 0) { print_r($structure); exit(); }
        if ($mdb->count("structures", ['structure_id' => $sid]) == 0) {
            $mdb->insert("structures", ['structure_id' => $sid, 'public' => false, 'corpID' => $corpID,  'lastChecked' => 0, 'hasMatch' => false]);
            Util::out("Adding private structure $sid");
        }
    }

    $mdb->set("scopes", $row, ['lastChecked' => time()]);
}

function fail($guzzler, $params, $ex) {
    $mdb = $params['mdb'];
    $row = $params['row'];
    $code = $ex->getCode();

    switch ($code) {
        case 400:
        case 403:
        case 404:
            $mdb->remove("scopes", $row); // They don't have the proper role... 
            break;
        default:
            echo "failed $code\n";
    }
}

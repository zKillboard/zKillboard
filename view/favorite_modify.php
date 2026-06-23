<?php

function favoriteScopeConfig($scope, $userID)
{
    global $mdb;

    $info = Info::getInfo('characterID', $userID);
    $corpID = (int) (@$info['corporationID'] ?: @$info['corporation_id']);
    $alliID = (int) (@$info['allianceID'] ?: @$info['alliance_id']);
    $isCEO = (bool) @$info['isCEO'];
    if (!$isCEO && $corpID > 1999999) {
        $isCEO = $mdb->exists('information', [
            'type' => 'corporationID',
            'id' => $corpID,
            '$or' => [
                ['ceoID' => (int) $userID],
                ['ceo_id' => (int) $userID],
            ],
        ]);
    }
    $isExecutorCorp = (bool) @$info['isExecutorCEO'];
    if (!$isExecutorCorp && $alliID > 0 && $corpID > 1999999) {
        $isExecutorCorp = $mdb->exists('information', [
            'type' => 'allianceID',
            'id' => $alliID,
            '$or' => [
                ['executorCorpID' => $corpID],
                ['executor_corporation_id' => $corpID],
            ],
        ]);
    }
    $isExecutorCEO = $isCEO && $isExecutorCorp;

    $configs = [
        'character' => [
            'field' => 'characterID',
            'id' => $userID,
            'label' => 'your',
            'logLabel' => 'their personal',
            'allowed' => true,
            'color' => '#FDBC2C',
            'emptyColor' => '#d0d0d0',
        ],
        'corporation' => [
            'field' => 'characterID',
            'id' => $corpID,
            'legacyField' => 'corporationID',
            'label' => 'corporation',
            'logLabel' => 'corporation',
            'allowed' => $corpID > 1999999 && $isCEO,
            'color' => '#37d05c',
            'emptyColor' => '#37d05c',
        ],
        'alliance' => [
            'field' => 'characterID',
            'id' => $alliID,
            'legacyField' => 'allianceID',
            'label' => 'alliance',
            'logLabel' => 'alliance',
            'allowed' => $alliID > 0 && $isExecutorCEO,
            'color' => '#4aa3ff',
            'emptyColor' => '#4aa3ff',
        ],
    ];

    return $configs[$scope] ?? null;
}

function favoriteResponse($response, $killID, $payload, $status = 200)
{
    $response = $response
        ->withStatus($status)
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Cache-Tag', "www,account,favorite,kill:$killID");
    $response->getBody()->write(json_encode($payload));
    return $response;
}

function updateFavorite($response, $killID, $scope, $action)
{
    global $mdb;

    if (!User::isLoggedIn()) {
        return favoriteResponse($response, $killID, ['color' => '#d0d0d0', 'favorited' => false, 'message' => 'You are not logged in. You need to log in to bookmark killmails.']);
    }

    $scope = $scope ?: 'character';
    $action = in_array($action, ['save', 'add']);
    $userID = (int) User::getUserID();
    $name = Info::getInfoField('characterID', $userID, 'name');
    $config = favoriteScopeConfig($scope, $userID);

    if ($config == null || !$config['allowed'] || (int) $config['id'] <= 0) {
        return favoriteResponse($response, $killID, ['color' => '#d0d0d0', 'favorited' => false, 'message' => 'You do not have permission to update that favorite.']);
    }

    $key = [$config['field'] => (int) $config['id'], 'killID' => (int) $killID];
    $mdb->remove('favorites', $key);
    if (isset($config['legacyField'])) {
        $mdb->remove('favorites', [$config['legacyField'] => (int) $config['id'], 'killID' => (int) $killID]);
    }

    if ($action) {
        $doc = $key + ['addedByCharacterID' => $userID, 'scope' => $scope];
        $mdb->insert('favorites', $doc);
        ZLog::add("$name has favorited $killID for {$config['logLabel']} favorites - https://zkillboard.com/kill/$killID/", $userID, true);
        return favoriteResponse($response, $killID, ['color' => $config['color'], 'favorited' => true, 'message' => "Killmail has been added to {$config['label']} favorites."]);
    }

    ZLog::add("$name has unfavorited $killID for {$config['logLabel']} favorites - https://zkillboard.com/kill/$killID/", $userID, true);
    return favoriteResponse($response, $killID, ['color' => $config['emptyColor'], 'favorited' => false, 'message' => "Killmail has been removed from {$config['label']} favorites."]);
}

function handler($request, $response, $args, $container) {
    $killID = (int) $args['killID'];
    $scope = $args['scope'] ?? 'character';
    return updateFavorite($response, $killID, $scope, $args['action']);
}

// Legacy compatibility - call handler if accessed directly
if (!function_exists('handler') || !isset($args)) {
    $scope = $scope ?? 'character';
    $response = new class {
        private $body = '';
        public function withStatus($status) { return $this; }
        public function withHeader($name, $value) { return $this; }
        public function getBody() { return $this; }
        public function write($body) { echo $body; }
    };
    updateFavorite($response, (int) $killID, $scope, $action);
}

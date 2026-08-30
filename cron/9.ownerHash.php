<?php

require_once '../init.php';

if ($kvc->get('zkb:noapi') == 'true') exit();

$sessions = $mdb->getCollection('sessions');
$scopes = $mdb->getCollection('scopes');
$yesterday = new MongoDB\BSON\UTCDateTime((time() - 86400) * 1000);
$rows = $scopes->find([
    'scope' => 'publicData',
    '$or' => [
        ['lastApiUpdate' => ['$lte' => $yesterday]],
        ['lastApiUpdate' => ['$exists' => false]],
    ],
], ['projection' => ['characterID' => 1, 'refreshToken' => 1]]);
$sso = ZKillSSO::getSSO(['publicData']);

foreach ($rows as $scope) {
    $characterID = (int) ($scope['characterID'] ?? 0);
    if ($characterID <= 1 || empty($scope['refreshToken']) || !$sessions->findOne(['characterID' => $characterID], ['projection' => ['_id' => 1]])) continue;

    try {
        $accessToken = $sso->getAccessToken($scope['refreshToken']);
        if (is_array($accessToken)) {
            $error = is_string($accessToken['error'] ?? null) ? $accessToken['error'] : 'unknown_error';
            throw new Exception("EVE SSO token refresh failed: $error");
        }
        $decoded = $sso->validateAccessToken($accessToken);
        $ownerHash = $decoded['owner'] ?? null;
        if (!is_string($ownerHash) || $ownerHash === '') {
            Util::out("Owner hash missing for character $characterID.");
            continue;
        }

        $deleted = $sessions->deleteMany([
            'characterID' => $characterID,
            'ownerHash' => ['$ne' => $ownerHash],
        ]);
        if ($deleted->getDeletedCount() > 0) Util::out("Removed " . $deleted->getDeletedCount() . " stale sessions for character $characterID.");
        $scopes->updateOne(['_id' => $scope['_id']], ['$set' => ['lastApiUpdate' => new MongoDB\BSON\UTCDateTime()]]);
    } catch (Exception $ex) {
        Util::out("Owner hash check failed for $characterID: " . $ex->getMessage());
    }
}

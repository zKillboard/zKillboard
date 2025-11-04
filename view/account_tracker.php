<?php

function handler($request, $response, $args, $container) {
    global $redis;
    
    $type = $args['type'];
    $id = (int) $args['id'];
    $action = $args['action'];

    $userID = User::getUserID();
    $message = null;
    if ($userID > 0 && $id > 0) {
        $redisKey = 'user:'.$userID;
        $mapKey = 'tracker_'.$type;
        $tracked = UserConfig::get($mapKey, []);
        if ($action == 'add') {
            $tracked[] = $id;
            $name = Info::getInfoField($type.'ID', $id, 'name');
            User::sendMessage("Added $name to your Tracker in the menu bar.");
            Util::zout("$userID adding tracker $type $id");
        } elseif ($action == 'remove') {
            unset($tracked[array_search($id, $tracked)]);
            $name = Info::getInfoField($type.'ID', $id, 'name');
            User::sendMessage("Removed $name from your Tracker in the menu bar. Please note, your logged in character and their corporation and alliance will always show in the tracker.");
            Util::zout("$userID removing tracker $type $id");
        }
        UserConfig::set($mapKey, $tracked);
    }

    return $response->withStatus(302)->withHeader('Location', "/$type/$id/");
}

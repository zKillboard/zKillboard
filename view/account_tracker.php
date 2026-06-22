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
            if (!in_array($id, $tracked)) $tracked[] = $id;
            $name = Info::getInfoField($type.'ID', $id, 'name');
            $message = "Added $name to your Tracker in the menu bar.";
            User::sendMessage($message);
            Util::zout("$userID adding tracker $type $id");
        } elseif ($action == 'remove') {
            $position = array_search($id, $tracked);
            if ($position !== false) unset($tracked[$position]);
            $name = Info::getInfoField($type.'ID', $id, 'name');
            $message = "Removed $name from your Tracker in the menu bar. Please note, your logged in character and their corporation and alliance will always show in the tracker.";
            User::sendMessage($message);
            Util::zout("$userID removing tracker $type $id");
        }
        $tracked = array_values(array_unique(array_map('intval', $tracked)));
        UserConfig::set($mapKey, $tracked);
    }

    $accept = $request->getHeaderLine('Accept');
    $requestedWith = $request->getHeaderLine('X-Requested-With');
    if (strpos($accept, 'application/json') !== false || strtolower($requestedWith) == 'xmlhttprequest') {
        $response->getBody()->write(json_encode([
            'success' => ($userID > 0 && $id > 0),
            'type' => $type,
            'id' => $id,
            'action' => $action,
            'message' => ($message ?: 'Unable to update tracker. Please try again.'),
            'tracked' => ($userID > 0 && $id > 0 ? $tracked : []),
        ]));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    return $response->withStatus(302)->withHeader('Location', "/$type/$id/");
}

<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $ip, $twig;
    
    $killID = $args['killID'] ?? '';
    $hash = $args['hash'] ?? '';
    
    // Basic verification
    if ((int) $killID <= 0 || strlen($hash) != 40) {
        return invalidRequest($response, "Malformed id or hash");
    }
    $killID = (int) $killID;

    // do we trust the poster?
    if (((int) $redis->get("km:post:$ip")) > 5) {
        $response->getBody()->write(json_encode(['error' => 'you cannot be trusted']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $newKillmail = false;
    // do we have the mail?
    if ($mdb->findDoc('killmails', ['killID' => $killID]) == null) {
    try {
        $mdb->insert("crestmails", ['killID' => $killID, 'hash' => $hash, 'processed' => false, 'source' => 'killmail-api-add', 'delay' => 0]);
        Util::zout("api mail add $killID $hash");
		$newKillmail = true;
    } catch (Exception $ex) {
        Util::zout(print_r($ex, true));
    }

        if ($redis->get("zkb:noapi") == "true") {
            return invalidRequest($response, "ESI is unavilable atm");
        }

    // wait for the mail to be processed
    $timer = new Timer();
    $processed = null;
    do {
        usleep(100000);
        $processed = $mdb->findField("crestmails", "processed", ['killID' => $killID, 'hash' => $hash]);
    } while ($processed !== true && in_array($processed, [false, 'delayed', 'fetching', 'fetched', 'processing']) && $timer->stop() <= 28000);

        // Did we take too long?
        if ($timer->stop() > 28000) {
            $response->getBody()->write(json_encode(['error' => 'timeout']));
            return $response->withStatus(408)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        
        // is the mail valid?
        if ($processed !== true) {
            return invalidRequest($response, "Invalid id or hash $processed $killID $hash");
        }
} else {
    // update the delay to 0, manual posts always take priority
    $mdb->set('crestmails', ['killID' => $killID, 'hash' => $hash], ['delay' => 0]);
}

    // return URL for the mail
    $responseData = ['status' => 'success', 'new' => $newKillmail, 'url' => "https://zkillboard.com/kill/${killID}/"];
    $response->getBody()->write(json_encode($responseData));
    return $response->withHeader('Access-Control-Allow-Origin', '*')
                   ->withHeader('Access-Control-Allow-Methods', 'GET, POST')
                   ->withHeader('Content-Type', 'application/json; charset=utf-8');
}

function invalidRequest($response, $reason) {
    global $ip, $redis;
    Util::zout("invalid request! $reason");

    $redis->incr("km:post:$ip");
    $redis->expire("km:post:$ip", 86400);

    $response->getBody()->write(json_encode(['status' => 'error', 'error' => $reason]));
    return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
}

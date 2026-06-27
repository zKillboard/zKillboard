<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $ip, $templates, $kvc;

    $killID = $args['killID'] ?? '';
    $hash = $args['hash'] ?? '';
    $delay = Util::parseKillmailDelay($args['delay'] ?? 0);

    // Basic verification
    if ((int) $killID <= 0 || strlen($hash) != 40) {
        return invalidRequest($response, "Malformed id or hash");
    }
    $killID = (int) $killID;

    // do we trust the poster?
    if (((int) $redis->get("km:post:$ip")) > 5) {
        $response->getBody()->write(json_encode(['error' => 'you cannot be trusted']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Tag', 'www,api,killmail-add,error');
    }

    $newKillmail = false;
    // do we have the mail?
    if ($mdb->findDoc('killmails', ['killID' => $killID]) == null) {
        try {
            $mdb->insert("crestmails", ['killID' => $killID, 'hash' => $hash, 'processed' => false, 'source' => 'killmail-api-add', 'delay' => $delay]);
            Util::zout("api mail add $killID $hash");
            $newKillmail = true;
        } catch (Exception $ex) {
            if ($ex->getCode() != 11000) Util::zout(print_r($ex, true));
            $mdb->set('crestmails', ['killID' => $killID, 'hash' => $hash, 'delay' => [ '$gt' => $delay]], ['delay' => $delay]);
        }

        if ($kvc->get("zkb:noapi") == "true") {
            return invalidRequest($response, "ESI is unavilable atm");
        }

        if ($delay > 0) {
            $responseData = ['status' => 'success', 'new' => $newKillmail, 'delayed' => true, 'delay' => $delay, 'url' => "https://zkillboard.com/kill/${killID}/"];
            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST')
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Tag', "www,api,killmail-add,kill:$killID");
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
            return $response->withStatus(408)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Tag', "www,api,killmail-add,kill:$killID,error");
        }

        // is the mail valid?
        if ($processed !== true) {
            return invalidRequest($response, "Invalid id or hash $processed $killID $hash");
        }
    } else {
        $mdb->set('crestmails', ['killID' => $killID, 'hash' => $hash, 'delay' => [ '$gt' => $delay]], ['delay' => $delay]);
    }

    // return URL for the mail
    $responseData = ['status' => 'success', 'new' => $newKillmail, 'delayed' => false, 'delay' => $delay, 'url' => "https://zkillboard.com/kill/${killID}/"];
    $response->getBody()->write(json_encode($responseData));
    return $response->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST')
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withHeader('Cache-Tag', "www,api,killmail-add,kill:$killID");
}

function invalidRequest($response, $reason) {
    global $ip, $redis;
    Util::zout("invalid request! $reason");

    $redis->incr("km:post:$ip");
    $redis->expire("km:post:$ip", 86400);

    $response->getBody()->write(json_encode(['status' => 'error', 'error' => $reason]));
    return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Tag', 'www,api,killmail-add,error');
}

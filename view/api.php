<?php

use cvweiss\redistools\RedisQueue;

function handler($request, $response, $args, $container) {
    global $redis, $ip, $uri;

    $inputString = $args['input'] ?? '';
    $input = explode('/', trim($inputString, '/'));

    // CORS headers
    $response = $response->withHeader('Access-Control-Allow-Origin', '*');
    $response = $response->withHeader('Access-Control-Allow-Methods', 'GET');

    try {
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        if ($queryString != '') {
            $response->getBody()->write('403 Forbidden - Do not include a query string to evade cache');
            return $response->withStatus(403);
        }

        if ($redis->get("zkb:reinforced") == true) {
            $response->getBody()->write('503 Reinforced mode, please try again later');
            return $response->withStatus(503);
        }

    if (strpos($uri, "/stats/") !== false) {
        throw new Exception("This is not the stats endpoint, refer to the documentation and build your URL properly.");
    }
    if (strpos($uri, "json") !== false) {
        throw new Exception("No need to refer to json, refer to the documentation and build your URL properly.");
    }

    $parameters = Util::convertUriToParameters($_SERVER['REQUEST_URI']);
    asort($parameters);

    $key = md5(json_encode($parameters));

    $return = $redis->get("zkb:api:result:$key");
    if ($return == null || $return == "") {
        $redis->setex("zkb:api:params:$key", 61, serialize($parameters));
        $redis->setex("zkb:api:status:$key", 60, "PENDING");
        $redis->sadd("queueAPI", $key);
    
        while ($redis->get("zkb:api:status:$key") == "PENDING") usleep(100000);
    }

    $return = unserialize($redis->get("zkb:api:result:$key"));

    $array = array();
    foreach ($return as $json) {
        $result = json_decode($json, true);
        if (true || (isset($parameters['zkbOnly']) && $parameters['zkbOnly'] == true)) {
            if (is_array($result)) {
                foreach ($result as $key => $value) {
                    if ($key != 'killID' && $key != 'zkb' && $key != "killmail_id") {
                        unset($result[$key]);
                    }
                }
            }
        }
        $array[] = $result;
        }

        // Add cache headers
        $response = $response->withHeader('Cache-Control', 'public, max-age=3600');
        $response = $response->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');

        $queryParams = $request->getQueryParams();
        if (isset($queryParams['callback']) && Util::isValidCallback($queryParams['callback'])) {
            // JSONP output
            $response = $response->withHeader('X-JSONP', 'true');
            $output = $queryParams['callback'].'('.json_encode($array).')';
            $response->getBody()->write($output);
            return $response->withHeader('Content-Type', 'application/javascript; charset=utf-8');
        } else {
            // JSON output
            if (isset($parameters['pretty'])) {
                $output = json_encode($array, JSON_PRETTY_PRINT);
            } else {
                $output = json_encode($array);
            }
            $response->getBody()->write($output);
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    } catch (Exception $ex) {
        $redis->incr("IP:errorCount:$ip");
        $redis->expire("IP:errorCount:$ip", 300);
        $count = $redis->get("IP:errorCount:$ip");
        if ($count > 40) {
            return $response->withStatus(302)->withHeader('Location', '/api/blocked.json');
        }

        $error = ['error' => $ex->getMessage()];
        $response->getBody()->write(json_encode($error));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}

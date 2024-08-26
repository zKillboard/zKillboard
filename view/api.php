<?php

use cvweiss\redistools\RedisQueue;

global $redis, $ip;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $queryString = $_SERVER['QUERY_STRING'];
    if ($queryString != '') {
        header('HTTP/1.0 403 Forbidden - Do not include a query string to evade cache');
        return;
    }

    if ($redis->get("zkb:reinforced") == true) {
        header('HTTP/1.1 503 Reinforced mode, please try again later');
        return;
    }

    global $uri;
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
    $app->expires('+1 hour');

    if (isset($_GET['callback']) && Util::isValidCallback($_GET['callback'])) {
        $app->contentType('application/javascript; charset=utf-8');
        header('X-JSONP: true');
        echo $_GET['callback'].'('.json_encode($array).')';
    } else {
        $app->contentType('application/json; charset=utf-8');
        if (isset($parameters['pretty'])) {
            echo json_encode($array, JSON_PRETTY_PRINT);
        } else {
            echo json_encode($array);
        }
    }
} catch (Exception $ex) {
    $redis->incr("IP:errorCount:$ip");
    $redis->expire("IP:errorCount:$ip", 300);
    $count = $redis->get("IP:errorCount:$ip");
    if ($count > 40) {
        if ($redis->set("IP:ban:$ip", "true", ['nx', 'ex' => 3600]) === true) Log::log("Banning $ip");
    }

    header('Content-Type: application/json');
    $error = ['error' => $ex->getMessage()];
    echo json_encode($error);
}

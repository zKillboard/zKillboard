<?php

class URI
{
    public static function validate($app, $uri, $params) {
        $l = sizeof($params);
        $keys = array_keys($params);

        // Verify parameters are in proper order
        for ($i = 1; $i < $l; $i++) {
            $x = strpos($uri, $keys[$i - 1] . "=");
            if ($x === false) continue;
            $y = strpos($uri, $keys[$i] . "=");
            if ($y === false) continue;
            if ($x > $y) { Log::log("$x > $y"); return $app->notFound(); } // params are not in expected sequence
        }

        // Verify we have all of the expected paramters
        $ret = [];
        for ($i = 0; $i < $l; $i++) {
            $ret[$keys[$i]] = self::getP($keys[$i]);
            if ($ret[$keys[$i]] === null && $params[$keys[$i]] === true) { Log::log("$uri Missing " . $keys[$i] . "\n" . print_r($params, true)); return $app->notFound(); } // Required param missing
        }

        // Verify we do not have any extra or unexpected parameters
        if (sizeof($_GET) > 0) return $app->notFound();

        return $ret;
    }

    private static function getP($key) {
        $v = @$_GET[$key];
        unset($_GET[$key]);
        return $v;
    }

    public static function html404($app, $reason) {
        header("reason: $reason");
        return $app->notFound();
    }
}

<?php

use cvweiss\redistools\RedisQueue;

class RelatedReport {

    public static function generateReport($system, $time, $options, $battleID = null, $app = null)
    {
        global $mdb, $redis;

        if ($time % 100 != 0 && $app != null) {
            return $app->redirect("/related/$system/" . substr($time, 0, strlen("$time") - 2) . "00/");
        } else if ($time % 100 != 0 && $app == null) { throw new \InvalidArgumentException("Minutes must be 00"); }

        $systemID = (int) $system;
        $relatedTime = (int) $time;
        $unixTime = strtotime($relatedTime);

        if ($redis->get("zkb:reinforced") == true) {
            header('HTTP/1.1 202 Request being processed');
            return $app->render('related_reinforced.html', ['showAds' => false]);
        }
        if ($redis->llen("queueRelated") > 25) {
            header('HTTP/1.1 202 Request being processed');
            return $app->render('related_notnow.html', ['showAds' => false, 'solarSystemID' => $systemID, 'unixtime' => $unixTime]);
        }

        $json_options = json_decode($options, true);
        if (!isset($json_options['A'])) {
            $json_options['A'] = array();
        }
        if (!isset($json_options['B'])) {
            $json_options['B'] = array();
        }

        $redirect = false;
        if (isset($_GET['left'])) {
            $entity = $_GET['left'];
            if (!isset($json_options['A'])) {
                $json_options['A'] = array();
            }
            if (($key = array_search($entity, $json_options['B'])) !== false) {
                unset($json_options['B'][$key]);
            }
            if (!in_array($entity, $json_options['A'])) {
                $json_options['A'][] = $entity;
            }
            $redirect = true;
        }
        if (isset($_GET['right'])) {
            $entity = $_GET['right'];
            if (!isset($json_options['B'])) {
                $json_options['B'] = array();
            }
            if (($key = array_search($entity, $json_options['A'])) !== false) {
                unset($json_options['A'][$key]);
            }
            if (!in_array($entity, $json_options['B'])) {
                $json_options['B'][] = $entity;
            }
            $redirect = true;
        }
        if ($redirect) {
            $json = urlencode(json_encode($json_options));
            $url = "/related/$systemID/$relatedTime/o/$json/";
            return $app->redirect($url, 302);
        }

        $systemInfo = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'solarSystemID', 'id' => $systemID]);
        $systemName = $systemInfo['name'];
        $regionInfo = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'regionID', 'id' => $systemInfo['regionID']]);
        $regionName = $regionInfo['name'];
        $time = date('Y-m-d H:i', $unixTime);

        $exHours = 1;
        if (((int) $exHours) < 1 || ((int) $exHours > 12)) {
            $exHours = 1;
        }

        $sleeps = 0;
        $key = 'br:'.md5("brq:$systemID:$relatedTime:$exHours:".json_encode($json_options).($battleID != null ? ":$battleID" : ''));
        $summary = null;
        while (true) {
            $summary = $redis->get($key);
            if ($summary != null) break;

            $parameters = array('solarSystemID' => $systemID, 'relatedTime' => $relatedTime, 'exHours' => $exHours, 'nolimit' => true, 'options' => $json_options, 'key' => $key);
            $serial = serialize($parameters);
            $redis->sadd('queueRelatedSet', $key);
            $redis->setex("$key:params", 3600, $serial);

            // See if we have a backup in place while the main one is being re-calculated?
            $summary = $redis->get("backup:$key");
            if ($summary != null) {
                break;
            }

            usleep(100000);
            ++$sleeps;
            if ($sleeps > 100) {
                if ($app === null) return [];
                header('HTTP/1.1 202 Request being processed');
                return $app->render('related_wait.html', ['showAds' => false]);
            }
        }

        $summary = unserialize($summary);
        $mc = array('summary' => $summary, 'systemID' => $systemID, 'systemName' => $systemName, 'regionName' => $regionName, 'time' => $time, 'exHours' => $exHours, 'solarSystemID' => $systemID, 'relatedTime' => $relatedTime, 'options' => json_encode($json_options), 'unixtime' => $unixTime);

        if ($battleID > 0) {
            $teamA = $summary['teamA']['totals'];
            $teamB = $summary['teamB']['totals'];
            unset($teamA['groupIDs']);
            unset($teamB['groupIDs']);
            $mdb->set('battles', ['battleID' => $battleID], ['teamA' => $teamA]);
            $mdb->set('battles', ['battleID' => $battleID], ['teamB' => $teamB]);
        }

        return $mc;
    }
}

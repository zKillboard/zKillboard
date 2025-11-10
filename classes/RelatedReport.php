<?php

use cvweiss\redistools\RedisQueue;

class RelatedReport {

    public static function generateReport($system, $time, $options, $battleID = null, $app = null)
    {
        global $mdb, $redis;

        if ($time % 100 != 0) {
            throw new \InvalidArgumentException("Minutes must be 00");
        }

        $systemID = (int) $system;
        $relatedTime = (int) $time;
        $unixTime = strtotime($relatedTime);

        if ($redis->get("zkb:reinforced") == true) {
            throw new \RuntimeException("System is reinforced", 202);
        }
        if ($redis->llen("queueRelated") > 25) {
            throw new \RuntimeException("Queue is too busy", 202);
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
            // Redirect should be handled by the view layer
            // This code path should not be reached as view handlers handle query params
            throw new \RuntimeException("Redirect handling should be done in view layer");
        }

        $systemInfo = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'solarSystemID', 'id' => $systemID]);
        $systemName = $systemInfo['name'] ?? 'Unknown System';
        $regionInfo = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'regionID', 'id' => $systemInfo['regionID'] ?? 0]);
        $regionName = $regionInfo['name'] ?? 'Unknown Region';
        $time = date('Y-m-d H:i', $unixTime);

        $exHours = 1;
        if (((int) $exHours) < 1 || ((int) $exHours > 12)) {
            $exHours = 1;
        }

        $sleeps = 0;
        $key = 'br:'.md5("brq:$systemID:$relatedTime:$exHours:".json_encode($json_options).($battleID != null ? ":$battleID" : ''));
        $summary = $redis->get($key);
        while (strlen($summary) == 0) {
            $parameters = array('solarSystemID' => $systemID, 'relatedTime' => $relatedTime, 'exHours' => $exHours, 'nolimit' => true, 'options' => $json_options, 'key' => $key);
            $serial = serialize($parameters);
            $redis->sadd('queueRelatedSet', $key);
            $redis->setex("$key:params", 3600, $serial);

            usleep(100000);
            ++$sleeps;
            if ($sleeps > 25) {
                // Return empty array to signal waiting state
                return [];
            }
            $summary = $redis->get($key);
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

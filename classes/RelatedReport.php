<?php

use cvweiss\redistools\RedisQueue;

class RelatedReport {

    public static function generateReport($system, $time, $options, $battleID = null, $app = null)
    {
        global $mdb, $redis;

        if ($time % 100 != 0 && $app != null) {
            $app->redirect("/related/$system/" . substr($time, 0, strlen("$time") - 2) . "00/"); exit();
        } else if ($time % 100 != 0 && $app == null) { throw new \InvalidArgumentException("Minutes must be 00"); }

        $systemID = (int) $system;
        $relatedTime = (int) $time;
        $unixTime = strtotime($relatedTime);

        if ($redis->get("zkb:reinforced") == true) {
            header('HTTP/1.1 202 Request being processed');
            $app->render('related_reinforced.pug', ['showAds' => false]);
            exit();
        }
        if ($redis->llen("queueRelated") > 25) {
            header('HTTP/1.1 202 Request being processed');
            $app->render('related_notnow.pug', ['showAds' => false, 'solarSystemID' => $systemID, 'unixtime' => $unixTime]);
            exit();
        }

        $json_options = Related::normalizeOptions(json_decode($options, true));
        $systems = Related::getSystems($systemID, $json_options['systems'] ?? []);
        if (!in_array($systemID, $systems['selected'])) $systemID = $systems['selected'][0];
        if (count($systems['selected']) > 1) $json_options['systems'] = $systems['selected'];
        else unset($json_options['systems']);

        $systemInfo = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'solarSystemID', 'id' => $systemID]);
        $systemName = $systemInfo['name'] ?? 'Unknown System';
        $regionInfo = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'regionID', 'id' => $systemInfo['regionID'] ?? 0]);
        $regionName = $regionInfo['name'] ?? 'Unknown Region';
        $time = date('Y-m-d H:i', $unixTime);

        $exHours = 1;
        $startTime = gmdate('Y-m-d H:i', $unixTime - 3600 * ($json_options['hoursBefore'] ?? 1));
        $endTime = gmdate('Y-m-d H:i', $unixTime + 3600 * ($json_options['hoursAfter'] ?? 2));

        $sleeps = 0;
        $key = 'br:'.md5("brq:$systemID:$relatedTime:$exHours:".json_encode($json_options).($battleID != null ? ":$battleID" : ''));
        $queue = new MongoQueue($mdb, 'queueRelatedSet', true);
        $summary = $redis->get($key);
        while (strlen($summary) == 0) {
            $parameters = array('solarSystemID' => $systems['selected'], 'startTime' => $startTime . ' UTC', 'endTime' => $endTime . ' UTC', 'nolimit' => true, 'options' => $json_options, 'key' => $key);
            $serial = serialize($parameters);
            $redis->setex("$key:params", 3600, $serial);
            $queuedKey = "zkb:queueRelatedSet:queued:$key";
            if ($redis->setnx($queuedKey, 1)) {
                $redis->expire($queuedKey, 3600);
                $queue->push($key);
            }

            usleep(100000);
            ++$sleeps;
            if ($sleeps > 25) {
                return ['complete' => false];
            }
            $summary = $redis->get($key);
        }

        $summary = unserialize($summary);
        $selectedSystems = [];
        $adjacentSystems = [];
        foreach ($systems['selected'] as $id) $selectedSystems[$id] = Info::getInfoField('solarSystemID', $id, 'name') ?? "System $id";
        asort($selectedSystems, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($systems['adjacent'] as $id) $adjacentSystems[$id] = Info::getInfoField('solarSystemID', $id, 'name') ?? "System $id";
        asort($adjacentSystems);
        self::normalizeEntityLinks($summary);
        $mc = array('summary' => $summary, 'systemID' => $systemID, 'systemName' => $systemName, 'regionName' => $regionName, 'time' => $time, 'exHours' => $exHours, 'solarSystemID' => $systemID, 'relatedTime' => $relatedTime, 'options' => json_encode($json_options), 'unixtime' => $unixTime);

        if ($battleID > 0) {
            $totals = [];
            foreach ($summary as $team => $data) {
                if ($team == 'excluded') continue;
                $totals[$team] = $data['totals'];
                unset($totals[$team]['groupIDs']);
            }
            $mdb->set('battles', ['battleID' => $battleID], $totals);
        }
		$mc['complete'] = true;
        $mc['selectedSystems'] = $selectedSystems;
        $mc['adjacentSystems'] = $adjacentSystems;
        $mc['startTime'] = $startTime;
        $mc['endTime'] = $endTime;

        return $mc;
    }

    private static function normalizeEntityLinks(&$summary)
    {
        foreach (array_keys($summary) as $team) {
            if (!isset($summary[$team]['entities']) || !is_array($summary[$team]['entities'])) {
                continue;
            }
            foreach ($summary[$team]['entities'] as $entity => $data) {
                if (is_array($data) && isset($data['name']) && isset($data['type'])) {
                    continue;
                }

                $name = is_array($data) && isset($data['name']) ? $data['name'] : $data;
                $type = 'alliance';
                if (Info::getInfoField('allianceID', $entity, 'name') === null) {
                    $type = 'corporation';
                }

                $summary[$team]['entities'][$entity] = ['name' => $name, 'type' => $type];
            }
        }
    }
}

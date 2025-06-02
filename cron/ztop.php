#!/usr/bin/php5
<?php

use cvweiss\redistools\RedisTtlCounter;
use cvweiss\redistools\RedisTimeQueue;

require_once '../init.php';

$redis->del("zkb:websockets"); // clear it on start
$redis->del("zkb:servers"); // clear it on start

$redisQueues = [];
$priorKillLog = 0;
$isMaster = null;

$deltaArray = [];

$lastKillCountSent = null;
$hour = date('H');
while ($hour == date('H')) {
    $curSecond = (int) date('s');

    // primary could change while we're running, to prevent conflicts, periodically check
    if ($isMaster == null || $curSecond % 15 == 0) $isMaster = areWeMaster();

    ob_start();
    $infoArray = [];

    $isHardened = $redis->ttl('zkb:isHardened');
    if ($isHardened > 0) {
        addInfo('seconds remaining in Cached/Hardened Mode', $isHardened);
        addInfo('', 0);
    }

    $queues = $redis->sMembers('queues');
    $queues[] = "queueRelatedSet";
    foreach ($queues as $queue) {
        $redisQueues[$queue] = true;
    }
    ksort($redisQueues);

    foreach ($redisQueues as $queue => $v) {
        if ($queue == 'queueStats') addInfo('queueStats', $redis->scard('queueStatsSet'));
        else if ($queue == 'queueStatsUpdated') addInfo('queueStatsUpdated', $redis->scard('queueStatsUpdated'));
        else if ($queue == 'queueRelatedSet') addInfo('queueRelated', $redis->scard('queueRelatedSet'));
        else addInfo($queue, $redis->lLen($queue));
    }

    addInfo('', 0);

    if (((int) $redis->get("tobefetched")) > 100000) addInfo('Kills remaining to be fetched. *', $redis->get("tobefetched"));
    else addInfo('Kills remaining to be fetched.', $mdb->count("crestmails", ['processed' => false]));

    addInfo('Kills remaining to be parsed.', $redis->zcard("tobeparsed"));
    $killsLastHour = new RedisTtlCounter('killsLastHour', 3600);
    $kCount = $killsLastHour->count();
    addInfo('Kills parsed last hour', $kCount);
    if ($kCount != $lastKillCountSent || (time() % 5 == 0)) {
        $serverStatus = $redis->get('tqStatus');
        $loggedIn = $redis->get('tqCount');
        $redis->publish("public", json_encode(['action' => 'tqStatus', 'tqStatus' => $serverStatus, 'tqCount' => $loggedIn, 'kills' => $kCount]));
        $redis->setex("tqKillCount", 900, $kCount);
        $lastKillCountSent = $kCount;
    }
    $totalKills = $mdb->getCollection("killmails")->count();
    $topKillID = max(1, $mdb->findField('killmails', 'killID', [], ['killID' => -1]));
    addInfo('Total Kills (' . number_format(($totalKills / $topKillID) * 100, 1) . '%)', $totalKills);
    addInfo('Top killID', $topKillID);

    addInfo('', 0);
    $nonApiR = new RedisTtlCounter('ttlc:nonApiRequests', 300);
    addInfo('Visitor page loads in last 5 minutes', $nonApiR->count());
    $uniqueUsers = new RedisTtlCounter('ttlc:unique_visitors', 300);
    addInfo("Visitors in last 5 minutes", $uniqueUsers->count());
    $apiR = new RedisTtlCounter('ttlc:apiRequests', 300);
    addInfo('API requests in last 5 minutes', $apiR->count());

    $ws = $redis->hgetall('zkb:websockets');
    $total = 0;
    foreach ($ws as $s=>$c) {
        $total += (int) $c;
    }
    addInfo('websocket connections', $total);

    addInfo('Successful ESI calls in last 5 minutes', Status::getStatus('esi', true), false);
    addInfo('Failed ESI calls in last 5 minutes', Status::getStatus('esi', false), false);
    addInfo('Successful SSO calls in last 5 minutes', Status::getStatus('sso', true), false);
    addInfo('Failed SSO calls in last 5 minutes', Status::getStatus('sso', false), false);

    $esiChars = new RedisTimeQueue("tqApiESI", 3600);
    $esiCorps = new RedisTimeQueue("tqCorpApiESI", 300);
    $ssoCorps = new RedisTimeQueue("zkb:ssoCorps", 3600);
    addInfo('', 0, false);
    addInfo('Character KillLogs to check', $esiChars->pending(), false);
    addInfo('Unique Character RefreshTokens', $esiChars->size(), false);
    addInfo('Corporation KillLogs to check', $esiCorps->pending(), false);
    addInfo('Unique Corporation RefreshTokens', $esiCorps->size(), false);

    addInfo('', 0, false);
    addInfo('access token avg request time in ms.', getRedisAvg('timer:sso', Status::getStatus('esi', true)), false);
    addInfo('killmail characters avg request time in ms.', getRedisAvg('timer:characters', 1000), false);
    addInfo('killmail corporations avg request time in ms.', getRedisAvg('timer:corporations', 100), false);

    $rtq = new RedisTimeQueue("zkb:characterID", 86400);
    addInfo('', 0, false);
    addInfo("Characters", $rtq->size(), false);
    $rtq = new RedisTimeQueue("zkb:corporationID", 86400);
    addInfo("Corporations", $rtq->size(), false);
    $rtq = new RedisTimeQueue("zkb:allianceID", 86400);
    addInfo("Alliances", $rtq->size(), false);

    addInfo('', 0, false);
    $sponsored = Mdb::group("sponsored", [], ['entryTime' => ['$gte' => $mdb->now(86400 * -7)]], [], 'isk', ['iskSum' => -1]);
    if ($sponsored == null) $sponsored = [['iskSum' => 0]];
    $sponsored = array_shift($sponsored);
    $sponsored = Util::formatIsk($sponsored['iskSum']);
    $balance = Util::formatIsk((double) $mdb->findField("payments", "balance", ['ref_type' => 'player_donation'], ['_id' => -1]));
    addInfo('Sponsored Killmails (inflated)', $sponsored, false, false);
    addInfo('Wallet Balance', $balance, false, false);

    addInfo('', 0, false);
    addInfo('Load Counter', $redis->get("zkb:load"), false);
    addinfo("Reinforced Mode", (int) $redis->get("zkb:reinforced"), false);
    addinfo("420'ed", max(0, $redis->ttl("zkb:420ed")), false);
    addinfo("420 Prone", (int) ($redis->get("zkb:420prone") == "true"), false);

    $info = $redis->info();
    $mem = $info['used_memory_human'];

    $stats = $mdb->getDb()->command(['dbstats' => 1]);
    $dataSize = number_format(($stats['dataSize'] + $stats['indexSize']) / (1024 * 1024 * 1024), 2);
    $storageSize = number_format(($stats['storageSize'] + @$stats['indexStorageSize']) / (1024 * 1024 * 1024), 2);

    $memory = getSystemMemInfo();
    $memTotal = number_format((int) $memory['MemTotal'] / (1024 * 1024), 2);
    $memUsed = number_format(((int) $memory['MemTotal'] - (int) $memory['MemFree'] - (int) $memory['Cached']) / (1024 * 1024), 2);

    $maxLen = 0;
    foreach ($infoArray as $i) {
        foreach ($i as $key => $value) {
            $maxLen = max($maxLen, strlen("$value"));
        }
    }

    $cpu = str_pad(exec("top -d 0.5 -b -n2 | grep \"Cpu(s)\"| tail -n 1 | awk '{print $2 + $4}'"), 5, " ", STR_PAD_LEFT);
    $output = [];
    $load = str_pad(Util::getLoad(), 5, " ", STR_PAD_LEFT);
    $memUsed = str_pad($memUsed, 5, " ", STR_PAD_LEFT);
    $memTotal = str_pad($memTotal, 5, " ", STR_PAD_LEFT);
    $storageSize = str_pad($storageSize, 5, " ", STR_PAD_LEFT);
    $dataSize = str_pad($dataSize, 5, " ", STR_PAD_LEFT);
    $line = exec('date')." CPU: $cpu% Load: $load  Memory: ${memUsed}G/${memTotal}G  Redis: $mem  MongoDB: ${storageSize}G/${dataSize}G";
    //$output[] = $line;
    $redis->hset("zkb:servers", $hostname, $line);
    //$redis->setex("zkb:memused", 300, $memUsed);
    if (!$isMaster) { sleep(1); continue; }

    $ws = $redis->hgetall('zkb:servers');
    foreach ($ws as $s=>$l) {
        echo str_pad($s, 14, " ", STR_PAD_RIGHT) . "$l\n";
    }
    echo "\n";

    $leftCount = 1;
    $rightCount = 1;
    $line = "                                                                                                               ";
    $line = str_repeat(" ", 80);
    foreach ($infoArray as $i) {
        $num = trim($i['num']);
        $text = trim($i['text']);
        $lr = $i['lr'];
        $start = $lr == true ? 15 : 70;
        $leftCount = $lr == true ? $leftCount + 1 : $leftCount;
        $rightCount = $lr == false ? $rightCount + 1 : $rightCount;

        $lineIndex = $lr == true ? $leftCount : $rightCount;
        $nextLine = isset($output[$lineIndex]) ? $output[$lineIndex] : $line;

        if (strlen($text) != '') {
            $nextLine = substr_replace($nextLine, $num, ($start - strlen($num)), strlen($num));
            $nextLine = substr_replace($nextLine, $text, $start + 2, strlen($text));
        }
        $output[$lineIndex] = $nextLine;
    }
    if ($isMaster) foreach($output as $line) echo "$line\n";
    $output = ob_get_clean();

    $redis->publish("ztop", json_encode(['action' => 'ztop', 'message' => $output]));

    while ($curSecond == date('s')) usleep(100000);
}

function addInfo($text, $number, $left = true, $format = true)
{
    global $infoArray, $deltaArray;


    $prevNumber = @$deltaArray[$text];
    $delta = (double) $number - (double) $prevNumber;
    $deltaArray[$text] = $number;

    if ($delta > 0) {
        $delta = "+$delta";
    }
    $dtext = $delta == 0 ? '' : "($delta)";
    $num = $format ? number_format($number, 0) : $number;
    if ($text == '') $num = '';
    $infoArray[] = ['text' => "$text $dtext", 'num' => $num, 'lr' => $left];
}

function getSystemMemInfo()
{
    $data = explode("\n", file_get_contents('/proc/meminfo'));
    $meminfo = array();
    foreach ($data as $line) {
        if ($line == '') {
            continue;
        }
        list($key, $val) = explode(':', $line);
        $meminfo[$key] = trim($val);
    }

    return $meminfo;
}


function getLoad()
{
    $output = array();
    $result = exec('cat /proc/loadavg', $output);

    $split = explode(' ', $result);
    $load = $split[0];

    return $load;
}

function getRedisAvg($list, $maxCount) {
    global $redis;

    while ($redis->llen($list) > $maxCount) $redis->lpop($list);
    $list = $redis->lrange($list, 0, -1);
    $sum = 0; $c = 0;
    foreach ($list as $l) { $sum += $l; $c++; }
    return ($c > 0 ? (round($sum / $c, 0)) : 0);
}

$mmongoClient = null;
$madmin = null;
function areWeMaster() {
    global $mongoConnString, $hostname, $mmongoClient, $madmin;

    $masterHostname = null;

    if ($mmongoClient == null) $mmongoClient = new MongoClient($mongoConnString, ['connectTimeoutMS' => 7200000, 'socketTimeoutMS' => 7200000]);
    if ($madmin == null) $madmin = $mmongoClient->selectDB('admin');
    $r = $madmin->command(['replSetGetStatus' => []]);
    foreach ($r['members'] as $member) {
        $server = (split(':', $member['name']))[0];
        $state = $member['state'];
        if ($state == 1) $masterHostname = $server;
        if ($state == 1 && $server == $hostname) $isMaster = true;
    }
    return ($masterHostname == $hostname);
}

<?php

require_once "../init.php";

$isMaster = false;

$servers = ['server128gb', 'zkbdbs1', 'zkbdbs2'];
$hostname = gethostname();
$masterHostname = null;

$mongoClient = new MongoClient($mongoConnString, ['connectTimeoutMS' => 7200000, 'socketTimeoutMS' => 7200000]);
$admin = $mongoClient->selectDB('admin');
$r = $admin->command(['replSetGetStatus' => []]);
foreach ($r['members'] as $member) {
    $server = (split(':', $member['name']))[0];
    $state = $member['state'];
    if ($state == 1) $masterHostname = $server;
    if ($state == 1 && $server == $hostname) $isMaster = true;
}

file_put_contents("master.lock", $masterHostname);
if ($isMaster) file_put_contents("isMaster.lock", $hostname);
else @unlink("isMaster.lock");

if (!$isMaster) exit();

foreach ($servers as $server) {
    $r = null;
    $client = getRedisClient($server, 6379);
    if ($server == $hostname) {
        setReplicaOf($client, $server, 'no', 'one');
    } else {
        setReplicaOf($client, $server, $hostname, '6379');
    }
}

/* Redis: Update the replicaof only if it doesn't already match */
function setReplicaOf($client, $server, $r1, $r2) {
    $info = $client->info('replication');
    $master = isset($info['master_host']) ? $info['master_host'] : 'no';

    if ($r1 != $master) {
        Util::out("Redis setting $server to be replica of $r1 $r2");
        $client->rawCommand('replicaOf', $r1, $r2);
    }
}

function getRedisClient($server, $port) {
    $redis = new Redis();
    $redis->connect($server, $port, 1, '', 5000);
    return $redis;
}


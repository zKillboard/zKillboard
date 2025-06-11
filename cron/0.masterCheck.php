<?php

require_once "../config.php";
require 'vendor/autoload.php';

$minute = date("Hi");
while ($minute == date("Hi")) {
    $isMaster = false;

    $hostname = gethostname();
    $masterHostname = null;
    $previousMaster = (string) file_get_contents("master.lock");

    $mongoClient = new MongoClient($mongoConnString, ['connectTimeoutMS' => 1000, 'socketTimeoutMS' => 60000]);
    $admin = $mongoClient->selectDB('admin');
    $r = $admin->command(['replSetGetStatus' => []]);
    foreach ($r['members'] as $member) {
        $server = (split(':', $member['name']))[0];
        $servers[] = $server;
        $state = $member['state'];
        if ($state == 1) $masterHostname = $server;
        if ($state == 1 && $server == $hostname) $isMaster = true;
    }

    // Update the lock files
    if (file_get_contents("master.lock") != $masterHostname) file_put_contents("master.lock", $masterHostname);
    if ($isMaster) file_put_contents("isMaster.lock", $hostname);
    else @unlink("isMaster.lock");

    // Do some reporting and then housekeeping, if necessary
    if ($previousMaster != $masterHostname) {
        echo "MongoDB: $masterHostname is PRIMARY\n";
        // If we are NOT the primary mongodb server, kill existing php scripts that are running
        // to prevent any possible conflicts in the scripts executing on different servers
        if ($hostname != $masterHostname) {
            $pid = getmypid();
            // List all php CLI processes, exclude fpm, exclude self
            $cmd = <<<EOC
                ps -eo pid,comm,args | grep 'php' | grep -v 'fpm' | awk '\$1 != $pid { print \$1 }' | xargs -r kill
                EOC;
            shell_exec($cmd);
        }
    }

    // only adjust redis master if we're the server with mongodb primary
    if ($hostname == $masterHostname) {
        foreach ($servers as $server) {
            // connect to each redis server and ensure it is master/slave appropriately
            try {
                $redisClient = new Redis();
                $redisClient->connect($server, 6379, 1.0);
                $nRole = $server == $masterHostname ? "master" : "slave";
                ensureRole($redisClient, $server, $masterHostname, $nRole);
                continue;
            } catch (Exception $ex) {
                // server having issues apparently, ignore it and move on
            }
        }
    }
    sleep(15);    
}


function getRole($redisClient) {
    $info = $redisClient->rawCommand('info', 'replication');
    $lines = explode("\n", $info);
    foreach ($lines as $line) {
        if (stripos($line, 'role:') === 0) {
            $role = trim(substr($line, 5));
            return $role;
        }
    }
}

function ensureRole($redisClient, $server, $mServer, $nRole) {
    $role = getRole($redisClient);
    if ($nRole == "master") $redisClient->rawCommand('replicaof', 'no', 'one');
    else $redisClient->rawCommand('replicaof', $mServer, 6379);
}

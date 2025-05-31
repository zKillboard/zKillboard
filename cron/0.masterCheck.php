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

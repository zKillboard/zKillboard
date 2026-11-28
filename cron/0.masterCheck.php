<?php

require_once "../config.php";
require 'vendor/autoload.php';

$minute = date("Hi");
while ($minute == date("Hi")) {
	$isMaster = (@$env === "development");

	$hostname = gethostname();
	$masterHostname = null;
	$previousMaster = (string) file_get_contents("master.lock");

	$mongoClient = new MongoDB\Client($mongoConnString, [], ['connectTimeoutMS' => 1000, 'socketTimeoutMS' => 60000]);
	$admin = $mongoClient->selectDatabase('admin');
	
	try {
		$r = $admin->command(['replSetGetStatus' => []])->toArray()[0];
	} catch (MongoDB\Driver\Exception\CommandException $e) {
		// Not running with replication, assume we're the master
		if ($e->getCode() == 76 || strpos($e->getMessage(), 'not running with --replSet') !== false) {
			$r = ['members' => [["name" => $hostname . ':27017', 'state' => 1]]];
		} else {
			throw $e;
		}
	}

	// if running standalone (e.g. for testing)
	if ($env == "development" || @$r['code'] == 76) $r['members'] = [["name" => $hostname . ':27017', 'state' => 1]];

	foreach ($r['members'] as $member) {
		$server = (split(':', $member['name']))[0];
		$state = $member['state'];
		if ($state == 1) $masterHostname = $server;
		if ($state == 1 && $server == $hostname) $isMaster = true;
	}

	// Update the lock files
	if ($masterHostname !== null && $masterHostname !== '' && $previousMaster != $masterHostname) {
		$masterLockTemp = "master.lock." . getmypid() . ".tmp";
		if (file_put_contents($masterLockTemp, $masterHostname, LOCK_EX) === false || !rename($masterLockTemp, "master.lock")) {
			@unlink($masterLockTemp);
		}
	}
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

	sleep(15);
}

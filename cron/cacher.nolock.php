<?php

for ($i = 0; $i < 30; ++$i) {
	$pid = pcntl_fork();
	if ($pid == -1) {
		exit();
	}
	if ($pid == 0) {
		break;
	}
}

require_once '../init.php';
$agents = [];
$qServer = new RedisQueue('queueServer');

$timer = new Timer();
while ($timer->stop() <= 90000) {
	$row = $qServer->pop();
	if($row === null) {
		sleep(1);
		continue;
	}

	$uri = @$row['REQUEST_URI'];
	$key = "cache:$uri";
	if ($redis->exists($key)) continue;

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "http://localhost{$uri}");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_USERAGENT, "Load Fetcher for https://$baseAddr");
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
	$contents = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	if ($httpCode == 200) $redis->setex($key, 300, $contents);
	else $redis->setex($key, 300, "reject");
}

function isBot($agent)
{
	if (strpos($agent, 'chrome') !== false) {
		return false;
	}
	if (strpos($agent, 'chrome') !== false) {
		return false;
	}
	if (strpos($agent, 'eve-igb') !== false) {
		return false;
	}

	if ($agent == '') {
		return true;
	}
	if (strpos($agent, 'bot') !== false) {
		return true;
	}
	if (strpos($agent, 'curl') !== false) {
		return true;
	}
	if (strpos($agent, 'evekb') !== false) {
		return true;
	}
	if (strpos($agent, 'ltx71') !== false) {
		return true;
	}
	if (strpos($agent, 'slurp') !== false) {
		return true;
	}
	if (strpos($agent, 'www.admantx.com') !== false) {
		return true;
	}
	if (strpos($agent, 'spider') !== false) {
		return true;
	}
	if (strpos($agent, 'disqus') !== false) {
		return true;
	}
	if (strpos($agent, 'dotlan') !== false) {
		return true;
	}
	if (strpos($agent, 'crawler') !== false) {
		return true;
	}
	if (strpos($agent, 'googledocs') !== false) {
		return true;
	}
	if (strpos($agent, 'mediapartners-google') !== false) {
		return true;
	}

	return false;
}

<?php

$mt = 6; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit(); $pid = $mt;

require_once '../init.php';

$c = $mdb->getCollection('zest3');

$minute = date("i");
while ($minute == date("i")) {
	$next = $redis->spop('queueZest3');
	if ($next == null) {
		usleep(1000);
		continue;
	}

	$lockKey = "lock:zest3:$next";
	$gotLock = $redis->set($lockKey, 1, ['nx', 'ex' => 120]);
	if ($gotLock === false) {
		$redis->sadd('queueZest3', $next);
		usleep(1000);
		continue;
	}

	try {
		$parts = explode(':', $next);
		$type = $parts[0];
		$id = (int) $parts[1];
		if ($id == 0) continue;

		save($c, "/$type/$id/", "/$type/$id/mixed.json", $next);
		save($c, "/$type/$id/kills/", "/$type/$id/kills.json", $next);
		save($c, "/$type/$id/losses/", "/$type/$id/losses.json", $next);
		save($c, "/$type/$id/solo/", "/$type/$id/solo.json", $next);
    } catch (Exception $ex) {
        $redis->sadd($next);
        Util::out(print_r($ex, true));
	} finally {
		// Release the lock after 1 second to prevent possible immediate re-lookup of next
		$redis->expire($lockKey, 1);
	}
}
	
function save($c, $url, $path, $cacheTag)
{
	$arr = transform($url);
	$c->updateOne(
		['path' => $path],
		[
			'$set' => [
				'path' => $path,
				'content' => json_encode($arr, true),
				'mimetype' => 'application/json',
				'ttl' => 3600,
				'lastModified' => new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000)),
				'headers' => [
					'Cache-Tag' => $cacheTag,
				],
			],
		],
		['upsert' => true]
	);
}

function transform($url)
{
	$p = Util::convertUriToParameters($url);
	$p['limit'] = 500;
	$kills = Kills::getKills($p, true, false, false);
	$arr = [];
	foreach ($kills as $kill) {
		$arr[] = $kill['killID'];
	}
	return $arr;
}


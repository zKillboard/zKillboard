<?php

global $baseDir, $mdb, $redis;

$systemID = (int) $system;
$relatedTime = (int) $time;

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
	$app->redirect($url, 302);
	die();
}

$systemInfo = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'solarSystemID', 'id' => $systemID]);
$systemName = $systemInfo['name'];
$regionInfo = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'regionID', 'id' => $systemInfo['regionID']]);
$regionName = $regionInfo['name'];
$unixTime = strtotime($relatedTime);
$time = date('Y-m-d H:i', $unixTime);

$exHours = 1;
if (((int) $exHours) < 1 || ((int) $exHours > 12)) {
	$exHours = 1;
}

$timer = new Timer();
$pushed = false;
$queueRelated = new RedisQueue("queueRelated");
$key = "br:" . md5("brq:$systemID:$relatedTime:$exHours:".json_encode($json_options) . (isset($battleID) ? ":$battleID" : ""));
$summary = null;
while (true)
{
	$summary = $redis->get($key);
	if ($summary != null) break;
	if ($pushed == false) 
	{
		$parameters = array('solarSystemID' => $systemID, 'relatedTime' => $relatedTime, 'exHours' => $exHours, 'nolimit' => true, 'options' => $json_options, 'key' => $key);
		$serial = serialize($parameters);
		$queueRelated->push($serial);
		$pushed = true;
	}
	usleep(100000);
	if ($timer->stop() > 10000000) { $app->redirect('.'); exit(); }
}

$summary = unserialize($summary);
$mc = array('summary' => $summary, 'systemName' => $systemName, 'regionName' => $regionName, 'time' => $time, 'exHours' => $exHours, 'solarSystemID' => $systemID, 'relatedTime' => $relatedTime, 'options' => json_encode($json_options));

if (isset($battleID) && $battleID > 0) {
	$teamA = $summary['teamA']['totals'];
	$teamB = $summary['teamB']['totals'];
	unset($teamA['groupIDs']);
	unset($teamB['groupIDs']);
	$mdb->set("battles", ['battleID' => $battleID], ['teamA' => $teamA]);
	$mdb->set("battles", ['battleID' => $battleID], ['teamB' => $teamB]);
}

$app->render('related.html', $mc);

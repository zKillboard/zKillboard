<?php

function handler($request, $response, $args, $container)
{
	global $mdb, $redis, $uri, $t, $showDailies;

	// Extract route parameters
	$dailyRouteDate = null;
	$dailyRouteSide = null;
	$dailyRouteDays = null;
	if (isset($args['type']) && isset($args['id'])) {
		$key = $args['type'];
		$id = $args['id'];
		$pageType = (string) ($args['pageType'] ?? '');
		$dailyRouteDate = isset($args['dailyDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['dailyDate']) ? $args['dailyDate'] : null;
		$dailyRouteSide = isset($args['dailySide']) && in_array($args['dailySide'], ['kills', 'losses']) ? $args['dailySide'] : null;
		$dailyRouteDays = isset($args['dailyDays']) ? (string) $args['dailyDays'] : null;
	} else {
		$inputString = $args['input'] ?? '';
		$input = explode('/', trim($inputString, '/'));
		$key = $input[0];
		if (!isset($input[1])) {
			return $response->withStatus(302)->withHeader('Location', '/');
		}
		$id = $input[1];
		$pageType = (string) @$input[2];
		if (isset($input[3]) && in_array($input[3], ['kills', 'losses'])) {
			$dailyRouteSide = $input[3];
			$dailyRouteDays = isset($input[4]) ? (string) $input[4] : null;
		} else {
			$dailyRouteDate = isset($input[3]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input[3]) ? $input[3] : null;
			$dailyRouteSide = isset($input[4]) && in_array($input[4], ['kills', 'losses']) ? $input[4] : null;
		}
	}
	if ($key != 'label' && (int) $id == 0) {
		$searchKey = $key;
		if ($key == 'system') {
			$searchKey = 'solarSystem';
		}
		$id = $mdb->findField('information', 'id', ['type' => "${searchKey}ID", 'name' => $id]);
		if ($id > 0) {
			return $response->withStatus(302)->withHeader('Location', "/$key/$id/");
		}
		return $response->withStatus(302)->withHeader('Location', './../');
	}

	if (strlen("$id") > 11) {
		return renderCached404($container, $response, 'Not Found');
	}

	$validPageTypes = array('kills', 'losses', 'solo', 'daily', 'stats', 'wars', 'supers', 'trophies', 'ranks', 'top', 'topalltime', 'streambox', 'recap2025');
	if ($key == 'alliance') {
		$validPageTypes[] = 'corpstats';
	}

	// Handle recap2025 page type
	if ($pageType == 'recap2025' && in_array($key, ['character', 'corporation', 'alliance'])) {
		require_once 'view/recap2025.php';
		return recap2025Handler($request, $response, $args, $container);
	}

	if ($pageType == '')
		$pageType = 'overview';
	else if (!in_array($pageType, $validPageTypes))
		$pageType = 'overview';

	$map = array(
		'corporation' => array('column' => 'corporation', 'mixed' => true),
		'character' => array('column' => 'character', 'mixed' => true),
		'alliance' => array('column' => 'alliance', 'mixed' => true),
		'faction' => array('column' => 'faction', 'mixed' => true),
		'system' => array('column' => 'solarSystem', 'mixed' => true),
		'constellation' => array('column' => 'constellation', 'mixed' => true),
		'region' => array('column' => 'region', 'mixed' => true),
		'group' => array('column' => 'group', 'mixed' => true),
		'ship' => array('column' => 'shipType', 'mixed' => true),
		'location' => array('column' => 'location', 'mixed' => true),
		'label' => array('column' => 'label', 'mixed' => true),
	);
	if (!array_key_exists($key, $map)) {
		return renderCached404($container, $response, 'Not Found');
	}

	if ($key != 'label' && (!is_numeric($id) || $id <= 0)) {
		return renderCached404($container, $response, 'Not Found');
	}

	try {
		$parameterPath = $request->getUri()->getPath();
		if ($pageType == 'daily' && ($dailyRouteDate != null || $dailyRouteSide != null || $dailyRouteDays != null)) {
			$parameterPath = "/$key/$id/daily/";
		}
		$parameters = Util::convertUriToParameters($parameterPath . '?' . $request->getUri()->getQuery());
	} catch (Exception $ex) {
		return renderCached404($container, $response, 'Not Found');
	}

	if (isset($parameters['streambox'])) {
		// Allow streambox to be embedded in iframes from any origin
		$response = $response
			->withHeader('X-Frame-Options', 'ALLOWALL')
			->withHeader('Content-Security-Policy', 'frame-ancestors *')
			->withHeader('Cache-Tag', "www,overview,overview:$id,streambox");
		return $container->get('view')->render($response, 'streambox.pug', []);
	}
	unset($parameters['streambox']);

	$information = $mdb->findDoc('information', ['type' => "${key}ID", 'id' => (int) $id, 'cacheTime' => 3600]);
	$disqualified = ((int) @$information['disqualified']);
	$dqChars = [];
	if ($disqualified > 0 && ($key == 'alliance' || $key == 'corporation')) {
		$dqChars = $mdb->find('information', ['type' => 'characterID', "${key}ID" => (int) $id, 'disqualified' => true]);
	}

	$redis->setex("zkb:overview:$key:$id", 9600, 'true');
	$redis->setex("zkb:overview:${key}ID:$id", 9600, 'true');
	if ($key != 'label')
		$id = (int) $id;

	@$page = max(1, $parameters['page']);
	global $loadGroupShips;  // Can't think of another way to do this just yet
	$loadGroupShips = $key == 'group';

	$limit = 100;
	$parameters['limit'] = $limit;
	$parameters['page'] = $page;
	try {
		$type = $map[$key]['column'];
		$detail = Info::getInfoDetails("${type}ID", $id);
		if (isset($detail['valid']) && $detail['valid'] == false) {
			return renderCached404($container, $response, 'Not Found');
		}
	} catch (Exception $ex) {
		return $container->get('view')->render($response->withHeader('Cache-Tag', "www,error,overview,overview:$id"), 'error.pug', array('message' => "There was an error fetching information for the $key you specified."));
	}

	$pageName = isset($detail[$map[$key]['column'] . 'Name']) ? $detail[$map[$key]['column'] . 'Name'] : '???';
	if ($key != 'label' && ($pageName == '???' && !$mdb->exists('information', ['id' => $id]))) {
		return renderCached404($container, $response, 'This entity is not in our database.');
	}
	$columnName = ($key == 'labels') ? 'labels' : $map[$key]['column'] . 'ID';
	$mixedKills = $pageType == 'overview' && $map[$key]['mixed'];

	$mixed = [];  // $pageType == 'overview' ? Kills::getKills($parameters, true, false, false) : array();
	$kills = [];  // $pageType == 'kills'    ? Kills::getKills($parameters, true, false, false) : array();
	$losses = [];  // $pageType == 'losses'  ? Kills::getKills($parameters, true, false, false) : array();

	if ($pageType != 'solo' || $key == 'faction') {
		$soloKills = array();
	} else {
		$soloParams = $parameters;
		if (!isset($parameters['kills']) || !isset($parameters['losses'])) {
			$soloParams['mixed'] = true;
		}
		$soloKills = [];  // Kills::getKills($soloParams, true, false, false);
	}
	$solo = [];  // Kills::mergeKillArrays($soloKills, array(), $limit, $columnName, $id);

	$validAllTimePages = array('character', 'corporation', 'alliance', 'faction');
	$nextTopRecalc = 0;
	$topLists = [];
	$topKills = [];
	if ($disqualified == 0 && ($pageType == 'top' || $pageType == 'topalltime')) {
		$topParameters = $parameters;
		$topParameters['limit'] = 100;
		$topParameters['npc'] = false;
		$topParameters['labels'] = 'pvp';
		$topParameters['cacheTime'] = 86400;

		if ($pageType == 'topalltime') {
			$useType = $key;
			if ($useType == 'ship') {
				$useType = 'shipType';
			} elseif ($useType == 'system') {
				$useType = 'solarSystem';
			}

			$typeField = $useType == 'label' ? $useType : "{$useType}ID";
			$id = $useType == 'label' ? $id : (int) $id;
			$topLists = $mdb->findField('statistics', 'topAllTime', ['type' => $typeField, 'id' => $id]);
			Info::addInfo($topLists);
			$topKills = $mdb->findField('statistics', 'topIskKills', ['type' => $typeField, 'id' => $id]);
			$topKills = Kills::getDetails($topKills, true);
			$nextTopRecalc = (int) $mdb->findField('statistics', 'nextTopRecalc', ['type' => "{$useType}ID", 'id' => (int) $id]);
			$nextTopRecalc = $nextTopRecalc + 1;
		} else if ($key != 'label') {
			if ($pageType != 'topalltime') {
				if (!isset($topParameters['year'])) {
					$topParameters['year'] = date('Y');
				}
				if (!isset($topParameters['month'])) {
					$topParameters['month'] = date('m');
				}
			}
			if (!array_key_exists('kills', $topParameters) && !array_key_exists('losses', $topParameters)) {
				$topParameters['kills'] = true;
			}

			if ($disqualified == 0)
				$topLists[] = array('type' => 'character', 'data' => Stats::getTop('characterID', $topParameters));
			if ($disqualified == 0)
				$topLists[] = array('type' => 'corporation', 'data' => Stats::getTop('corporationID', $topParameters));
			if ($disqualified == 0)
				$topLists[] = array('type' => 'alliance', 'data' => Stats::getTop('allianceID', $topParameters));
			$topLists[] = array('type' => 'ship', 'data' => Stats::getTop('shipTypeID', $topParameters));
			$topLists[] = array('type' => 'system', 'data' => Stats::getTop('solarSystemID', $topParameters));
			$topLists[] = array('type' => 'location', 'data' => Stats::getTop('locationID', $topParameters));

			if (isset($detail['factionID']) && $detail['factionID'] != 0 && $key != 'faction') {
				$topParameters['!factionID'] = 0;
				$topLists[] = array('name' => 'Top Faction Characters', 'type' => 'character', 'data' => Stats::getTop('characterID', $topParameters));
				$topLists[] = array('name' => 'Top Faction Corporations', 'type' => 'corporation', 'data' => Stats::getTop('corporationID', $topParameters));
				$topLists[] = array('name' => 'Top Faction Alliances', 'type' => 'alliance', 'data' => Stats::getTop('allianceID', $topParameters));
			}
			$p = $topParameters;
			$p['limit'] = 6;
			$topKills = Stats::getTopIsk($p);
		}
	}

	$activity = ['max' => 0];
	if ($pageType == 'overview') {
		$raw = $redis->hget('zkb:activity', $id);
		if ($raw != null)
			$activity = unserialize($raw);
		else
			for ($day = 0; $day <= 6; $day++) {
				for ($hour = 0; $hour <= 23; $hour++) {
					$count = $mdb->count('activity', ['id' => (int) $id, 'day' => $day, 'hour' => $hour]);
					if ($count > 0)
						$activity[$day][$hour] = $count;
					$activity['max'] = max($activity['max'], $count);
				}
			}
		$activity['days'] = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
	}

	$corpList = array();
	if ($pageType == 'api') {
		$corpList = Info::getCorps($id);
	}

	$corpStats = array();
	if ($pageType == 'corpstats') {
		$corpStats = Info::getCorpStats($id, $parameters);
	}

	$onlyHistory = array('character', 'corporation', 'alliance');
	if ($pageType == 'stats' && in_array($key, $onlyHistory)) {
		$months = $mdb->findField('statistics', 'months', ['type' => $key . 'ID', 'id' => $id]);
		if ($months != null) {
			krsort($months);
		}
		$detail['history'] = $months == null ? [] : $months;
	} else {
		$detail['history'] = array();
	}

	// Figure out if the character or corporation has any API keys in the database
	$nextApiCheck = null;

	$extra = array();
	$extra['padSum'] = 0;  // $padSum;
	$extra['vPadSum'] = 0;  // $vPadSum;
	$extra['activity'] = $activity;
	$tracked = false;
	if (User::isLoggedIn()) {
		$trackers = [];
		$t = UserConfig::get("tracker_$type", []);
		$tracked = in_array((int) $id, $t);
	}
	$extra['isTracked'] = $tracked;
	$extra['canTrack'] = true;  // in_array($type, ['character', 'corporation', 'alliance']);

	$cnt = 0;
	$cnid = 0;
	$stats = array();
	$totalcount = ceil(count($detail['stats']) / 4);
	if ($detail['stats'] != null) {
		foreach ($detail['stats'] as $q) {
			if ($cnt == $totalcount) {
				++$cnid;
				$cnt = 0;
			}
			$stats[$cnid][] = $q;
			++$cnt;
		}
	}
	if ($mixedKills) {
		$kills = [];  // Kills::mergeKillArrays($mixed, array(), $limit, $columnName, $id);
	}

	$prevID = null;
	$nextID = null;

	$warID = (int) $id;
	$extra['hasWars'] = false;  // Db::queryField("select count(distinct warID) count from zz_wars where aggressor = $warID or defender = $warID", "count");
	$extra['wars'] = array();
	if (false && $pageType == 'wars' && $extra['hasWars']) {
		$extra['wars'][] = War::getNamedWars('Active Wars - Aggressor', "select * from zz_wars where aggressor = $warID and timeFinished is null order by timeStarted desc");
		$extra['wars'][] = War::getNamedWars('Active Wars - Defending', "select * from zz_wars where defender = $warID and timeFinished is null order by timeStarted desc");
		$extra['wars'][] = War::getNamedWars('Closed Wars - Aggressor', "select * from zz_wars where aggressor = $warID and timeFinished is not null order by timeFinished desc");
		$extra['wars'][] = War::getNamedWars('Closed Wars - Defending', "select * from zz_wars where defender = $warID and timeFinished is not null order by timeFinished desc");
	}

	if ($key == 'system') {
		$statType = 'solarSystemID';
	} elseif ($key == 'ship') {
		$statType = 'shipTypeID';
	} else if ($key == 'label') {
		$statType = 'label';
	} else {
		$statType = "{$key}ID";
		$id = (int) $id;
	}
	$statistics = $mdb->findDoc('statistics', ['type' => $statType, 'id' => $id]);
	$showDailyStats = ($showDailies ?? false) && DailyStats::hasData($statType, $id);
	if ($pageType == 'daily' && !$showDailyStats) {
		return renderCached404($container, $response, 'Not Found');
	}

	$dailyStats = null;
	$dailyDays = [];
	$dailyDate = null;
	$dailySide = 'kills';
	$dailySelectedDays = [];
	$dailySelectedStart = null;
	$dailySelectedEnd = null;
	$dailyGraphStart = null;
	$dailyGraphEnd = null;
	if ($pageType == 'daily') {
		$queryParams = $request->getQueryParams();
		$dailyDate = $dailyRouteDate ?? (isset($queryParams['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $queryParams['date']) ? $queryParams['date'] : null);
		$dailySide = $dailyRouteSide ?? (isset($queryParams['side']) && in_array($queryParams['side'], ['kills', 'losses']) ? $queryParams['side'] : 'kills');
		$selectedDaysInput = $dailyRouteDays ?? ($queryParams['days'] ?? null);
		if ($selectedDaysInput != null && $selectedDaysInput != 'all') {
			if (preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', (string) $selectedDaysInput, $matches)) {
				$startDay = $matches[1];
				$endDay = $matches[2];
				if ($startDay <= $endDay) {
					$dailySelectedStart = $startDay;
					$dailySelectedEnd = $endDay;
				}
			} else {
				foreach (explode(',', (string) $selectedDaysInput) as $day) {
					if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
						$dailySelectedDays[$day] = $day;
					}
				}
				if (count($dailySelectedDays) > 0) {
					$dailySelectedStart = min($dailySelectedDays);
					$dailySelectedEnd = max($dailySelectedDays);
				}
			}
			$dailySelectedDays = array_values($dailySelectedDays);
		} else if ($dailyDate != null) {
			$dailySelectedDays = [$dailyDate];
			$dailySelectedStart = $dailyDate;
			$dailySelectedEnd = $dailyDate;
		}
		$dailyGraphInput = (string) ($queryParams['graph'] ?? $request->getHeaderLine('X-ZKB-Daily-Graph'));
		if ($dailyGraphInput != '' && preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $dailyGraphInput, $matches)) {
			if ($matches[1] <= $matches[2]) {
				$dailyGraphStart = $matches[1];
				$dailyGraphEnd = $matches[2];
			}
		}
		$dailyStats = ['async' => true];
	}

	if ($key == 'corporation' || $key == 'alliance' || $key == 'faction') {
		$extra['hasSupers'] = @$statistics['hasSupers'];
		if ($disqualified == 0 && $pageType == 'supers') {
			$extra['supers'] = @$statistics['supers'];
			Info::addInfo($extra['supers']);
		}
	}

	if ($key == 'character' && $pageType == 'trophies' && $disqualified == 0) {
		$trophies = $mdb->findDoc('trophies', ['id' => (int) $id]);
		$extra['trophies'] = $trophies ? $trophies['trophies'] : Trophies::getTrophies($id);
	}

	if ($pageType == 'ranks') {
		$alltimeRanks = getNearbyRanks($key, 'alltime', 'all', $id, 'Alltime Rank', $statType);
		$day90Ranks = getNearbyRanks($key, 'recent', 'all', $id, '90 Day Rank', $statType);
		$day7Ranks = getNearbyRanks($key, 'weekly', 'all', $id, '7 Day Rank', $statType);
		$extra['allranks'] = ['7day' => $day7Ranks, '90Day' => $day90Ranks, 'alltime' => $alltimeRanks];
	}

	$alltimeRankRow = Ranks::getRow('alltime', 'all', $statType, $id);
	$statistics['shipsDestroyedRank'] = rankRowRank($alltimeRankRow, 'shipsDestroyed');
	$statistics['shipsLostRank'] = rankRowRank($alltimeRankRow, 'shipsLost');
	$statistics['iskDestroyedRank'] = rankRowRank($alltimeRankRow, 'iskDestroyed');
	$statistics['iskLostRank'] = rankRowRank($alltimeRankRow, 'iskLost');
	$statistics['pointsDestroyedRank'] = rankRowRank($alltimeRankRow, 'pointsDestroyed');
	$statistics['pointsLostRank'] = rankRowRank($alltimeRankRow, 'pointsLost');
	$statistics['overallRank'] = rankRowRank($alltimeRankRow, 'overall');

	$statistics['iskDestroyedUsdEurGbp'] = Util::iskToUsdEurGbp($statistics['iskDestroyed'] ?? 0);
	$statistics['iskLostUsdEurGbp'] = Util::iskToUsdEurGbp($statistics['iskLost'] ?? 0);

	if (@$statistics['shipsLost'] > 0) {
		$destroyed = @$statistics['shipsDestroyed'] + @$statistics['pointsDestroyed'];
		$lost = @$statistics['shipsLost'] + @$statistics['pointsLost'];
		if ($destroyed > 0 || $lost > 0) {
			$ratio = floor(($destroyed / ($lost + $destroyed)) * 100);
			$extra['dangerRatio'] = $ratio;
		}
	} else if (@$statistics['shipsDestroyed'] > 0) {
		$extra['dangerRatio'] = 100;
	}
	if (@$extra['dangerRatio'] !== null && date('md') == '0401') {  // Everyone is snuggly on the first day of the fourth month
		$extra['dangerRatio'] = 0;
	}
	if (@$statistics['labels']) {
		$invChecks = ['solo', '#:2+', '#:5+', '#:10+', '#:25+', '#:50+', '#:100+', '#:1000+'];
		$invCounts = [];
		$total = 0;
		$invCountSolo = 0;
		$invCountsSum = 0;
		$invCountsAvgSum = 0;
		foreach ($invChecks as $invCheck) {
			if (isset($statistics['labels'][$invCheck]['shipsDestroyed'])) {
				$invCountsSum += $statistics['labels'][$invCheck]['shipsDestroyed'];
				$num = ($invCheck == 'solo' ? 1 : str_replace('+', '', str_replace('#:', '', $invCheck)));
				$total += $statistics['labels'][$invCheck]['shipsDestroyed'];
				if ($invCheck == 'solo')
					$invCountSolo = $statistics['labels'][$invCheck]['shipsDestroyed'];
				$invCountsAvgSum += ($num * $statistics['labels'][$invCheck]['shipsDestroyed']);
			}
		}
		$avg = ($invCountsSum == 0 ? 0 : round($invCountsAvgSum / $invCountsSum, 1));
		$statistics['labels']['all']['shipsDestroyed'] = $invCountsSum;
		$soloRatio = ($total == 0 ? 0 : round(($invCountSolo / $total) * 100, 1));
		$restRatio = 100 - $soloRatio;

		$extra['involvedLabels'] = [['label' => $avg . ' avg', 'ratio' => $restRatio, 'count' => $avg], ['label' => 'solo', 'ratio' => $soloRatio, 'count' => $invCountSolo]];
	}
	if (@$statistics['soloKills'] > 0 && @$statistics['shipsDestroyed'] > 0) {
		$gangFactor = 100 - floor(100 * ($statistics['soloKills'] / $statistics['shipsDestroyed']));
		$extra['gangFactor'] = $gangFactor;
	} else if (@$statistics['shipsDestroyed'] > 0) {
		$gangFactor = floor(@$statistics['pointsDestroyed'] / @$statistics['shipsDestroyed'] * 10 / 2);
		$gangFactor = max(0, min(100, 100 - $gangFactor));
		$extra['gangFactor'] = $gangFactor;
	}

	$recentRankRow = Ranks::getRow('recent', 'all', $statType, $id);
	$statistics['recentShipsDestroyed'] = rankRowMetric($recentRankRow, 'shipsDestroyed');
	$statistics['recentShipsDestroyedRank'] = rankRowRank($recentRankRow, 'shipsDestroyed');
	$statistics['recentShipsLost'] = (int) rankRowMetric($recentRankRow, 'shipsLost');
	$statistics['recentShipsLostRank'] = rankRowRank($recentRankRow, 'shipsLost');
	$statistics['recentIskDestroyed'] = rankRowMetric($recentRankRow, 'iskDestroyed');
	$statistics['recentIskDestroyedRank'] = rankRowRank($recentRankRow, 'iskDestroyed');
	$statistics['recentIskLost'] = rankRowMetric($recentRankRow, 'iskLost');
	$statistics['recentIskLostRank'] = rankRowRank($recentRankRow, 'iskLost');
	$statistics['recentPointsDestroyed'] = rankRowMetric($recentRankRow, 'pointsDestroyed');
	$statistics['recentPointsDestroyedRank'] = rankRowRank($recentRankRow, 'pointsDestroyed');
	$statistics['recentPointsLost'] = rankRowMetric($recentRankRow, 'pointsLost');
	$statistics['recentPointsLostRank'] = rankRowRank($recentRankRow, 'pointsLost');
	$statistics['recentOverallRank'] = rankRowRank($recentRankRow, 'overall');

	if (@$statistics['recentShipsLost'] > 0 || @$statistics['recentShipsDestroyed'] > 0) {
		$destroyed = @$statistics['recentShipsDestroyed'] + @$statistics['recentPointsDestroyed'];
		$lost = @$statistics['recentShipsLost'] + @$statistics['recentPointsLost'];
		if ($destroyed > 0 || $lost > 0) {
			$ratio = floor(($destroyed / ($lost + $destroyed)) * 100);
			$extra['recentDangerRatio'] = $ratio;
		}
	}

	$getSoloStats = true;
	if ($type == 'label') {
		$getSoloStats = false;
	} else if ($type == 'shipType') {
		$groupID = $mdb->findField('information', 'groupID', ['type' => 'shipTypeID', 'id' => (int) $id]);
		$catID = $mdb->findField('information', 'groupID', ['type' => 'groupID', 'id' => (int) $groupID]);
		$getStats = $catID == 6;  // Only get stats for ships
	} else if ($type == 'group') {
		$catID = $mdb->findField('information', 'groupID', ['type' => 'groupID', 'id' => (int) $id]);
		$getStats = $catID == 6;  // Only get stats for groups of ships
	}

	$recentSoloKills = 0;
	if ($getSoloStats) {
		if ($recentSoloKills === '')
			$recentSoloKills = MongoFilter::getCount(['isVictim' => false, "${type}ID" => (int) $id, 'solo' => true, 'pastSeconds' => 7776000]);
		else
			$recentSoloKills = (int) $recentSoloKills;
		if ($recentSoloKills > 0 && $statistics['recentShipsDestroyed'] > 0) {
			$gangFactor = 100 - floor(100 * ($recentSoloKills / ($recentSoloKills + $statistics['recentShipsDestroyed'])));
			$extra['recentGangFactor'] = $gangFactor;
		} else if (@$statistics['shipsDestroyed'] > 0) {
			$extra['recentGangFactor'] = 100;
		}
	}
	$statistics['recentSoloKills'] = $recentSoloKills;

	$weeklyRankRow = Ranks::getRow('weekly', 'all', $statType, $id);
	$statistics['weeklyShipsDestroyed'] = rankRowMetric($weeklyRankRow, 'shipsDestroyed');
	$statistics['weeklyShipsDestroyedRank'] = rankRowRank($weeklyRankRow, 'shipsDestroyed');
	$statistics['weeklyShipsLost'] = (int) rankRowMetric($weeklyRankRow, 'shipsLost');
	$statistics['weeklyShipsLostRank'] = rankRowRank($weeklyRankRow, 'shipsLost');
	$statistics['weeklyIskDestroyed'] = rankRowMetric($weeklyRankRow, 'iskDestroyed');
	$statistics['weeklyIskDestroyedRank'] = rankRowRank($weeklyRankRow, 'iskDestroyed');
	$statistics['weeklyIskLost'] = rankRowMetric($weeklyRankRow, 'iskLost');
	$statistics['weeklyIskLostRank'] = rankRowRank($weeklyRankRow, 'iskLost');
	$statistics['weeklyPointsDestroyed'] = rankRowMetric($weeklyRankRow, 'pointsDestroyed');
	$statistics['weeklyPointsDestroyedRank'] = rankRowRank($weeklyRankRow, 'pointsDestroyed');
	$statistics['weeklyPointsLost'] = rankRowMetric($weeklyRankRow, 'pointsLost');
	$statistics['weeklyPointsLostRank'] = rankRowRank($weeklyRankRow, 'pointsLost');
	$statistics['weeklyOverallRank'] = rankRowRank($weeklyRankRow, 'overall');

	if (@$statistics['weeklyShipsLost'] > 0 || @$statistics['weeklyShipsDestroyed'] > 0) {
		$destroyed = @$statistics['weeklyShipsDestroyed'] + @$statistics['weeklyPointsDestroyed'];
		$lost = @$statistics['weeklyShipsLost'] + @$statistics['weeklyPointsLost'];
		if ($destroyed > 0 || $lost > 0) {
			$ratio = floor(($destroyed / ($lost + $destroyed)) * 100);
			$extra['weeklyDangerRatio'] = $ratio;
		}
	}

	$weeklySoloKills = 0;
	if ($getSoloStats) {
		if ($weeklySoloKills === '')
			$weeklySoloKills = MongoFilter::getCount(['isVictim' => false, "${type}ID" => (int) $id, 'solo' => true, 'pastSeconds' => 604800]);
		else
			$weeklySoloKills = (int) $weeklySoloKills;
		if ($weeklySoloKills > 0 && $statistics['weeklyShipsDestroyed'] > 0) {
			$gangFactor = 100 - floor(100 * ($weeklySoloKills / ($weeklySoloKills + $statistics['weeklyShipsDestroyed'])));
			$extra['weeklyGangFactor'] = $gangFactor;
		} else if ($statistics['weeklyShipsDestroyed'] > 0) {
			$extra['weeklyGangFactor'] = 100;
		}
	}
	$statistics['weeklySoloKills'] = $weeklySoloKills;

	// Get previous rankings
	$previousTime = time() - (14 * 86400);
	$previousDate = date('Ymd');
	$previousRank = null;
	do {
		$previousDate = date('Ymd', $previousTime);
		$previousRank = Ranks::rank('alltime', 'all', $statType, $id, 'overall', $previousDate);
		if ($previousRank === null) {
			$previousTime += 86400;
		}
	} while ($previousRank === null && $previousTime < time());
	$prevRanks = ['overallRank' => $previousRank, 'date' => date('Y-m-d', $previousTime)];
	$prevRanks['recentOverallRank'] = Ranks::rank('recent', 'all', $statType, $id, 'overall', $previousDate);
	$statistics['prevRanks'] = $prevRanks;

	$groups = @$statistics['groups'];
	if ($pageType == 'stats' && is_array($groups) and sizeof($groups) > 0) {
		Info::addInfo($groups);
		$g = [];
		foreach ($groups as $group) {
			@$g[$group['groupName']] = $group;
		}
		ksort($g);

		// Divide the stats into 4 columns...
		$chunkSize = ceil(sizeof($g) / 4);
		$statistics['groups'] = array_chunk($g, $chunkSize);
	} else {
		$statistics['groups'] = null;
	}

	$months = @$statistics['months'];
	// Ensure the months are sorted in descending order
	if (is_array($months) && sizeof($months) > 0) {
		krsort($months);
		$statistics['months'] = array_values($months);
	} else {
		$statistics['months'] = null;
	}

	// Collect active PVP stats
	if ($key == 'label')
		$activePvP = [];
	else
		$activePvP = Stats::getActivePvpStats($parameters);

	$hasPager = in_array($pageType, ['overview', 'kills', 'losses', 'solo']);

	$gold = 0;
	if ($type == 'character') {
		$user = $mdb->findDoc('users', ['userID' => "user:$id"]);
		if (@$user['adFreeUntil'] >= time()) {
			$gold = 1 + floor(($user['adFreeUntil'] - time()) / (86400 * 365));
		}
		if ($mdb->find('sponsored', ['characterID' => (int) $id])) {
			$extra['hasSponsored'] = true;
		}
		if (@$user['monocle'] == true)
			$extra['hasMonocle'] = true;
		if (@$user['supermonocle'] == true)
			$extra['hasSuperMonocle'] = true;
	}

	// Sponsored killmails
	if ($pageType == 'overview' || $pageType == 'losses') {
		$sponsoredKey = "victim.${type}ID";
		$result = Mdb::group('sponsored', ['killID'], [$sponsoredKey => (int) $id, 'entryTime' => ['$gte' => $mdb->now(86400 * -7)]], [], 'isk', ['iskSum' => -1], 6);
		$sponsored = [];
		foreach ($result as $kill) {
			if ($kill['iskSum'] <= 0)
				continue;
			$killmail = $mdb->findDoc('killmails', ['killID' => $kill['killID']]);
			Info::addInfo($killmail);
			if (isset($killmail['involved']) && isset($killmail['involved'][0])) {
				$killmail['victim'] = $killmail['involved'][0];
				$killmail['zkb']['totalValue'] = $kill['iskSum'];

				$sponsored[$kill['killID']] = $killmail;
			}
		}
		$extra['sponsoredMails'] = $sponsored;
	}

	$extra['statsRecalced'] = $redis->llen('queueStats');

	$extra['recentkills'] = $type == 'character' && $redis->get("recentKillmailActivity:char:$id") == true;

	global $templates;
	$templates->addGlobal('year', (isset($parameters['year']) ? $parameters['year'] : date('Y')));
	$templates->addGlobal('month', (isset($parameters['month']) ? $parameters['month'] : date('m')));

	if ($type == 'label') {
		$detail = ['label' => $id];
		$pageName = $id;
	}

	global $uri;
	$vics = ['characterID' => 'character', 'corporationID' => 'corporation', 'allianceID' => 'alliance', 'shipTypeID' => 'ship', 'groupID' => 'group', 'factionID' => 'faction'];
	$kills = addVics($vics, $kills);
	$losses = addVics($vics, $losses);
	$mixedKills = addVics($vics, $mixedKills);
	$soloKills = addVics($vics, $soloKills);

	if ($key == 'label')
		$kills = [];
	if ($key == 'location') {
		$locationInfo = $mdb->findDoc('information', ['type' => 'locationID', 'id' => (int) $id]);
		$detail['typeID'] = (int) @$locationInfo['typeID'];
		$detail['solarSystemID'] = (int) @$locationInfo['solarSystemID'];
		if ($detail['solarSystemID'] <= 0) {
			$detail['solarSystemID'] = (int) $mdb->findField('locations_calced', 'solar_system_id', ['$or' => [['id' => (int) $id], ['entityID' => (int) $id]]]);
		}
		if ($detail['solarSystemID'] <= 0) {
			$detail['solarSystemID'] = (int) $mdb->findField('killmails', 'system.solarSystemID', ['locationID' => (int) $id], ['killID' => -1]);
		}
		Info::addInfo($detail);
	}
	if ($key == 'region') {
		$detail['constellations'] = $mdb->find('information', ['type' => 'constellationID', 'regionID' => (int) $id], ['name' => 1], null, ['id' => 1, 'name' => 1]);
	}
	if ($key == 'constellation') {
		$detail['systems'] = $mdb->find('information', ['type' => 'solarSystemID', 'constellationID' => (int) $id], ['name' => 1], null, ['id' => 1, 'name' => 1]);
	}

	$renderParams = array('pageName' => $pageName, 'kills' => $kills, 'losses' => $losses, 'detail' => $detail, 'page' => $page, 'topKills' => $topKills, 'mixed' => $mixedKills, 'key' => $key, 'id' => $id, 'pageType' => $pageType, 'solo' => $solo, 'topLists' => $topLists, 'corps' => $corpList, 'corpStats' => $corpStats, 'summaryTable' => $stats, 'pager' => $hasPager, 'datepicker' => true, 'nextApiCheck' => $nextApiCheck, 'apiVerified' => false, 'apiCorpVerified' => false, 'prevID' => $prevID, 'nextID' => $nextID, 'extra' => $extra, 'statistics' => $statistics, 'activePvP' => $activePvP, 'nextTopRecalc' => $nextTopRecalc, 'showDailyStats' => $showDailyStats, 'dailyStats' => $dailyStats, 'dailyDays' => $dailyDays, 'dailyDate' => $dailyDate, 'dailySide' => $dailySide, 'dailySelectedDays' => $dailySelectedDays, 'dailySelectedStart' => $dailySelectedStart, 'dailySelectedEnd' => $dailySelectedEnd, 'dailyGraphStart' => $dailyGraphStart, 'dailyGraphEnd' => $dailyGraphEnd, 'entityID' => $id, 'entityType' => $key, 'gold' => $gold, 'disqualified' => $disqualified, 'dqChars' => $dqChars);

	$overviewResponse = $response->withHeader('Cache-Tag', "www,overview,overview:$id" . ($pageType == 'daily' ? ',daily-v2' : ''));
	if ($pageType == 'daily') {
		$overviewResponse = $overviewResponse->withHeader('Cache-Control', 'no-store');
	}
	return $container->get('view')->render($overviewResponse, 'overview.pug', $renderParams);
}

function addVics($vics, $kills = [])
{
	if ($kills === false || $kills === true)
		$kills = [];
	foreach ($kills as $kid => $kill) {
		$vic = [];
		foreach ($vics as $kkey => $uri) {
			if (isset($kill['victim'][$kkey]))
				$vic[] = $kill['victim'][$kkey];
		}
		if ($uri == '/alliance/99005338/losses/')
			Util::zout(implode(',', $vic));
		$kill['vics'] = implode(',', $vic);
		$kills[$kid] = $kill;
	}
	return $kills;
}

function renderCached404($container, $response, $message = 'Not Found')
{
	$cacheControl = 'public, max-age=3600, s-maxage=3600';
	$cached404Response = $response
		->withStatus(404)
		->withHeader('Cache-Control', $cacheControl)
		->withHeader('CDN-Cache-Control', $cacheControl)
		->withHeader('Cloudflare-CDN-Cache-Control', $cacheControl)
		->withHeader('Cache-Tag', 'www,error,404,overview');

	return $container->get('view')->render($cached404Response, '404.pug', array('message' => $message));
}

function getNearbyRanks($key, $epoch, $scope, $id, $title, $statType)
{
	$array = [];
	$rank = Ranks::rank($epoch, $scope, $statType, $id);
	if ($rank !== null) {
		$array['data'] = Ranks::nearby($epoch, $scope, $statType, $id);
		if (sizeof($array['data']) > 0) Info::addInfo($array);
		$title = $title . ' #' . number_format($rank, 0);
	}
	$array['title'] = $title;
	$array['type'] = $key;

	return $array;
}

function rankRowMetric($row, $metric)
{
	return $row['metrics'][$metric] ?? 0;
}

function rankRowRank($row, $metric)
{
	return $row['ranks'][$metric] ?? null;
}

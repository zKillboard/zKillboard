<?php

function handler($request, $response, $args, $container)
{
	global $mdb;

	$stats = [];
	$rows = $mdb->find(
		'statistics',
		['type' => 'label'],
		['shipsDestroyed' => -1],
		null,
		['_id' => 0, 'id' => 1, 'shipsDestroyed' => 1, 'shipsLost' => 1, 'iskDestroyed' => 1, 'iskLost' => 1]
	);
	foreach ($rows as $row) {
		$label = (string) ($row['id'] ?? '');
		if ($label == '' || $label == 'all') continue;
		$stats[$label] = [
			'count' => (int) ($row['shipsDestroyed'] ?? 0) + (int) ($row['shipsLost'] ?? 0),
			'isk' => (double) ($row['iskDestroyed'] ?? 0) + (double) ($row['iskLost'] ?? 0),
		];
	}

	$labels = [];
	$order = 0;
	foreach (AdvancedSearch::$labels as $configuredLabels) {
		foreach ($configuredLabels as $label => $name) {
			$labels[$label] = ['name' => $name, 'order' => $order++];
		}
	}
	foreach ($stats as $label => $unused) {
		if (!isset($labels[$label]) && preg_match('/^cat:\d+$/', $label)) $labels[$label] = ['name' => '', 'order' => $order++];
	}

	$groupMeta = [
		'location' => ['name' => 'Locations', 'icon' => 'fa-map-marker-alt', 'color' => '#285c00', 'description' => 'Security bands and special regions of space.'],
		'timezone' => ['name' => 'Activity timezones', 'icon' => 'fa-clock', 'color' => '#24536f', 'description' => 'Broad UTC windows based on when the killmail occurred.'],
		'engagement' => ['name' => 'Engagement size', 'icon' => 'fa-users', 'color' => '#665a1f', 'description' => 'The number and type of attackers on the killmail.'],
		'type' => ['name' => 'Killmail types', 'icon' => 'fa-tags', 'color' => '#6b2f45', 'description' => 'Special classifications applied during killmail processing.'],
		'isk' => ['name' => 'ISK bands', 'icon' => 'fa-coins', 'color' => '#6e331f', 'description' => 'Mutually exclusive bands based on the victim total value.'],
		'category' => ['name' => 'Victim categories', 'icon' => 'fa-crosshairs', 'color' => '#3f5f2a', 'description' => 'The EVE inventory category of the victim.'],
		'fw' => ['name' => 'Faction warfare', 'icon' => 'fa-shield-alt', 'color' => '#294f6b', 'description' => 'Faction warfare matchups and the winning faction.'],
		'special' => ['name' => 'Special ships', 'icon' => 'fa-rocket', 'color' => '#57285e', 'description' => 'Ship classifications that do not belong to another label family.'],
		'other' => ['name' => 'Other labels', 'icon' => 'fa-tag', 'color' => '#3f3f3f', 'description' => 'Additional labels found in killmail statistics.'],
	];
	$groups = [];
	foreach ($groupMeta as $group => $meta) $groups[$group] = $meta + ['labels' => []];

	$nameOverrides = [
		'loc:highsec' => 'Highsec', 'loc:lowsec' => 'Lowsec', 'loc:nullsec' => 'Nullsec', 'loc:w-space' => 'W-Space',
		'tz:au' => 'AU / China · 08:00–13:59 UTC', 'tz:ru' => 'Russia · 14:00–16:59 UTC', 'tz:eu' => 'Europe · 17:00–21:59 UTC',
		'tz:use' => 'USA East · 22:00–03:59 UTC', 'tz:usw' => 'USA West · 04:00–07:59 UTC',
		'solo' => 'Solo PvP', '#:1' => '1 attacker', '#:2+' => '2–4 attackers', '#:5+' => '5–9 attackers',
		'#:10+' => '10–24 attackers', '#:25+' => '25–49 attackers', '#:50+' => '50–99 attackers',
		'#:100+' => '100–999 attackers', '#:1000+' => '1,000+ attackers',
		'pvp' => 'PvP', 'npc' => 'PvE', 'ganked' => 'Highsec gank',
		'isk:under1b' => '<1b ISK', 'isk:1b+' => '1–5b ISK', 'isk:5b+' => '5–10b ISK',
		'isk:10b+' => '10–100b ISK', 'isk:100b+' => '100b–1t ISK', 'isk:1t+' => '1t+ ISK',
	];
	$descriptionOverrides = [
		'solo' => 'A PvP killmail with exactly one player attacker.',
		'#:1' => 'One attacker, excluding killmails classified as solo PvP.',
		'pvp' => 'Player-versus-player combat that is not classified as padding.',
		'npc' => 'A kill attributed to NPC activity rather than PvP.',
		'awox' => 'The attacker and victim share a corporation or alliance affiliation.',
		'ganked' => 'A killmail classified as a high-security-space gank.',
		'padding' => 'A killmail identified as likely killboard padding.',
		'capital' => 'The victim ship belongs to a capital market group.',
		'atShip' => 'An Alliance Tournament prize ship was involved.',
		'fw:calgal' => 'Caldari–Gallente faction warfare combat.',
		'fw:amamin' => 'Amarr–Minmatar faction warfare combat.',
	];
	$labelColors = [
		'loc:highsec' => '#285c00', 'loc:lowsec' => '#804000', 'loc:nullsec' => '#781500', 'loc:w-space' => '#4f246b',
		'loc:pochven' => '#3f3f3f', 'loc:drifter' => '#24536f', 'loc:abyssal' => '#6b2f45',
		'tz:au' => '#24536f', 'tz:ru' => '#6b2f45', 'tz:eu' => '#3f5f2a', 'tz:use' => '#604515', 'tz:usw' => '#4a356a',
		'solo' => '#24536f', '#:1' => '#2f5f55', '#:2+' => '#3f5f2a', '#:5+' => '#665a1f', '#:10+' => '#604515',
		'#:25+' => '#6e331f', '#:50+' => '#6b2f45', '#:100+' => '#57285e', '#:1000+' => '#3f3f3f',
		'isk:under1b' => '#24536f', 'isk:1b+' => '#285c00', 'isk:5b+' => '#3f5f2a', 'isk:10b+' => '#665a1f',
		'isk:100b+' => '#6e331f', 'isk:1t+' => '#6b2f45',
		'pvp' => '#285c00', 'npc' => '#3f3f3f', 'awox' => '#781500', 'ganked' => '#6b2f45', 'padding' => '#604515',
		'fw:calgal' => '#24536f', 'fw:caldari' => '#294f6b', 'fw:gallente' => '#285c40', 'fw:amamin' => '#604515',
		'fw:amarr' => '#6b541e', 'fw:minmatar' => '#73331f', 'capital' => '#57285e', 'atShip' => '#6b2f45',
	];
	$categoryColors = ['#24536f', '#2f5f55', '#3f5f2a', '#665a1f', '#604515', '#6e331f', '#6b2f45', '#57285e', '#3f3f3f'];

	foreach ($labels as $label => $labelData) {
		if (str_starts_with($label, 'loc:')) $group = 'location';
		else if (str_starts_with($label, 'tz:')) $group = 'timezone';
		else if (str_starts_with($label, '#:') || $label == 'solo') $group = 'engagement';
		else if (in_array($label, ['pvp', 'npc', 'awox', 'ganked', 'padding'], true)) $group = 'type';
		else if (str_starts_with($label, 'isk:')) $group = 'isk';
		else if (str_starts_with($label, 'cat:')) $group = 'category';
		else if (str_starts_with($label, 'fw:')) $group = 'fw';
		else if (in_array($label, ['capital', 'atShip'], true)) $group = 'special';
		else $group = 'other';

		$name = $nameOverrides[$label] ?? $labelData['name'];
		if ($group == 'category' && preg_match('/^cat:(\d+)$/', $label, $matches)) {
			$categoryName = Info::getInfoField('categoryID', (int) $matches[1], 'name');
			if ($categoryName != null && $categoryName != '') $name = $categoryName;
		}
		if ($name == '') {
			$parts = explode(':', $label, 2);
			$name = ucwords(str_replace(['-', '_'], ' ', $parts[1] ?? $parts[0]));
		}
		$color = $labelColors[$label] ?? '#3f3f3f';
		if ($group == 'category' && preg_match('/^cat:(\d+)$/', $label, $matches)) $color = $categoryColors[(int) $matches[1] % count($categoryColors)];

		if (isset($descriptionOverrides[$label])) $description = $descriptionOverrides[$label];
		else if ($group == 'location') $description = "Killmails occurring in $name.";
		else if ($group == 'timezone') $description = "Killmails occurring during the $name activity window.";
		else if ($group == 'engagement') $description = "$name on the killmail.";
		else if ($group == 'isk') $description = "Victim total value in the $name band.";
		else if ($group == 'category') $description = "Victim inventory category: $name.";
		else if ($group == 'fw') $description = "$name faction warfare victories.";
		else $description = $name;

		$searchButtons = ['togglefilters', 'week', 'rolling', 'attackers-and', 'either-and', 'victims-and', 'sort-date', 'sort-desc', 'page1', 'allinvolved', "label-$label"];
		$groups[$group]['labels'][] = [
			'id' => $label,
			'name' => $name,
			'description' => $description,
			'color' => $color,
			'count' => (int) ($stats[$label]['count'] ?? 0),
			'isk' => (double) ($stats[$label]['isk'] ?? 0),
			'order' => $labelData['order'],
			'viewHref' => '/label/' . rawurlencode($label) . '/',
			'searchHref' => AdvancedSearch::getLabelGroup($label) == null ? '' : '/asearch/#' . rawurlencode(json_encode(['buttons' => $searchButtons], JSON_UNESCAPED_SLASHES)),
		];
	}
	foreach ($groups as $group => $groupData) {
		usort($groups[$group]['labels'], function ($a, $b) {
			return ($a['order'] <=> $b['order']) ?: strcmp($a['name'], $b['name']);
		});
	}

	$cacheControl = 'public, max-age=3600, s-maxage=3600';
	$response = $response
		->withHeader('Cache-Control', $cacheControl)
		->withHeader('CDN-Cache-Control', $cacheControl)
		->withHeader('Cloudflare-CDN-Cache-Control', $cacheControl)
		->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT')
		->withHeader('Cache-Tag', 'www,labels');

	return $container->get('view')->render($response, 'labels.pug', [
		'groups' => $groups,
		'labelCount' => count($labels),
		'showAds' => true,
	]);
}

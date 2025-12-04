<?php

require_once '../init.php';

$ninetyDaysFirst = $mdb->findField('ninetyDays', 'killID', [], ['killID' => 1]);
$oneWeekFirst = $mdb->findField('oneWeek', 'killID', [], ['killID' => 1]);

$killmails = $mdb->getCollection('killmails')->find(); //->sort(['killID' => -1]);
foreach ($killmails as $killmail) {
	$fwFaction = fwFaction($killmail);
	if ($fwFaction === null)
		continue;

	$labels = [$fwFaction];
	if ($fwFaction == 'fw:caldari' || $fwFaction == 'fw:gallente') {
		$labels[] = 'fw:calgal';
	} else if ($fwFaction == 'fw:amarr' || $fwFaction == 'fw:minmatar') {
		$labels[] = 'fw:amamin';
	}
	echo "Updating " . $killmail['killID'] . " with labels: " . implode(", ", $labels) . "\n";
	$mdb->getCollection("killmails")->updateOne(['killID' => $killmail['killID']], ['$addToSet' => ['labels' => ['$each' => $labels]]]);
	if ($killmail['killID'] >= $ninetyDaysFirst)
		$mdb->getCollection("ninetyDays")->updateOne(['killID' => $killmail['killID']], ['$addToSet' => ['labels' => ['$each' => $labels]]]);
	if ($killmail['killID'] >= $oneWeekFirst)
		$mdb->getCollection("oneWeek")->updateOne(['killID' => $killmail['killID']], ['$addToSet' => ['labels' => ['$each' => $labels]]]);
}

function fwFaction($kill)
{
	$involved = $kill['involved'];
	$factions = [];
	foreach ($involved as $attacker) {
		$characterID = (int) @$attacker['characterID'];
		if ($characterID == 0) continue;

		if (isset($attacker['factionID']) == false) continue;
		$attackerFactionID = (int) @$attacker['factionID'];
		$factions[$attackerFactionID] = true;
	}
	// Caldari and Gallente?
	if (isset($factions[500001]) && isset($factions[500004])) {
		$victim = $kill['involved'][0];
		$victimFactionID = (int) @$victim['factionID'];
		if ($victimFactionID == 500001)
			return 'fw:gallente';  // Gallente won the fight
		if ($victimFactionID == 500004)
			return 'fw:caldari';  // Caldari won the fight
		return null;
	}
	// Amarr and Minmatar?
	if (isset($factions[500003]) && isset($factions[500002])) {
		$victim = $kill['involved'][0];
		$victimFactionID = (int) @$victim['factionID'];
		if ($victimFactionID == 500003)
			return 'fw:minmatar';  // Minmatar won the fight
		if ($victimFactionID == 500002)
			return 'fw:amarr';  // Amarr won the fight
		return null;
	}
	return null;
}

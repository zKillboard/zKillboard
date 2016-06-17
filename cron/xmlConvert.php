<?php

require_once "../init.php";

$xmlmails = $mdb->getCollection("xmlmails")->find()->sort(['_id' => 1]);

foreach ($xmlmails as $xmlRow) {
	$killID = $xmlRow['killID'];
	$crestRow = $mdb->findDoc("crestmails", ['killID' => $killID]);
	$rawRow = $mdb->findDoc("rawmails", ['killID' => $killID]);
	if ($crestRow['processed'] === false) continue;
	if ($crestRow['processed'] === true || $rawRow != null) {
		$mdb->remove("xmlmails", $xmlRow);
		continue;
	}
	Util::out("XML->CREST conversion for $killID completed");
	$raw = convert2CREST($xmlRow['data']);

	$mdb->save("rawmails", $raw);
	$mdb->set("crestmails", ['killID' => $killID], ['processed' => false]);
        $mdb->removeField("crestmails", ['killID' => $killID], "error");
        $mdb->removeField("crestmails", ['killID' => $killID], "errorCode");

}

function convert2CREST($killmail) {
	$crest = [];
	$crest['solarSystem']['id'] = $killmail['solarSystemID'];
	$crest['solarSystem']['name'] = Info::getInfoField('solarSystemID', $killmail['solarSystemID'], 'name');
	$crest['killID'] = $killmail['killID'];
	$crest['killTime'] = str_replace("-", '.', $killmail['killTime']);
	$attackers = [];
	foreach ($killmail['attackers'] as $a) {
		$aa = [];
		getID($a, $aa, "factionID");
		getID($a, $aa, "allianceID");
		getID($a, $aa, "shipTypeID");
		getID($a, $aa, "corporationID");
		getID($a, $aa, "characterID");
		$aa['finalBlow'] = $a['finalBlow'] == 1 ? true : false;
		$aa['damageDone'] = $a['damageDone'];
		$aa['securityStatus'] = $a['securityStatus'];
		$attackers[] = $aa;
	}
	$crest['attackers'] = $attackers;
	$crest['attackerCount'] = sizeof($attackers);

	$v = $killmail['victim'];
	$vv = [];
	$vv['damageTaken'] = $v['damageTaken'];
	getID($v, $vv, "factionID");
	getID($v, $vv, "allianceID");
	getID($v, $vv, "corporationID");
	getID($v, $vv, "characterID");
	getID($v, $vv, "shipTypeID");
	$vv['position'] = ['x' => $v['x'], 'y' => $v['y'], 'z' => $v['z']];

	$items = getItems($killmail['items']);
	$vv['items'] = $items;

	$crest['victim'] = $vv;
	$crest['war']['id'] = 0;
	$crest['converted'] = true;

	return $crest;
}

function getItems($items) {
	$retVal = [];
	foreach ($items as $i) {
		$ii = [];
		$ii['singleton'] = $i['singleton'];
		$ii['itemType']['id'] = $i['typeID'];
		$ii['itemType']['name'] = Info::getInfoField('typeID', $i['typeID'], 'name');
		$ii['flag'] = $i['flag'];
		if (@$i['qtyDropped'] > 0) $ii['quantityDropped'] = $i['qtyDropped'];
		if (@$i['qtyDestroyed'] > 0) $ii['quantityDestroyed'] = $i['qtyDestroyed'];
		if (isset($i['items'])) $ii['items'] = getItems($i['items']);
		$retVal[] = $ii;
	}
	return $retVal;
}

function getID($from, &$to, $type) {
	$name = substr($type, 0, strlen($type) - 2);
	$value = $from[$type];
	$to[$name]['id'] = $value;
	$to[$name]['name'] = Info::getInfoField($type, $value, 'name');

}

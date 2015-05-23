<?php

require_once "../../init.php";

$dbUser = $argv[2];
$dbPassword = $argv[3];

$tables = array("dgmAttributeCategories", "dgmAttributeTypes", "dgmEffects", "dgmTypeEffects", "invFlags", "invGroups", "invTypes", "mapDenormalize", "mapRegions", "mapSolarSystems");

foreach ($tables as $table)
{
	$sdeDb = $argv[1];
	$ourTable = $table;
	if ($ourTable == "mapRegions") $ourTable = "regions";
	if ($ourTable == "mapSolarSystems") $ourTable = "systems";
	$ourTable = "ccp_$ourTable";
	echo "$ourTable\n";
	if ($table != "invTypes") Db::execute("truncate $ourTable");
	Db::execute("replace into $ourTable select * from $sdeDb.$table");
}

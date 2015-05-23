<?php

class Subdomains
{
	public static function getSubdomainParameters($serverName)
	{
		global $app, $twig, $baseAddr, $fullAddr, $mdb;

		// Are we looking at an aliased subdomain?
		$alias = Db::queryField("select alias from zz_subdomains where subdomain = :serverName", "alias", array(":serverName" => $serverName), 60);
		if ($alias)
		{
			header("Location: http://$alias") ;
			exit();
		}
		if ($serverName != $baseAddr && strlen(str_replace(".$baseAddr", "", $serverName)) > 5) 
		{
			$serverName = Db::queryField("select subdomain from zz_subdomains where alias = :serverName", "subdomain", array(":serverName" => $serverName));
			if (strlen($serverName) == 0)
			{
				header("Location: http://$baseAddr") ;
				exit();
			}
		}
		$adfree = Db::queryField("select count(*) count from zz_subdomains where adfreeUntil >= now() and subdomain = :serverName", "count", array(":serverName" => $serverName));

		$board = str_replace(".$baseAddr", "", $serverName);
		$board = str_replace("_", " ", $board);
		$board = preg_replace('/^dot\./i', '.', $board);
		$board = preg_replace('/\.dot$/i', '.', $board);
		try {
			if ($board == "www") $app->redirect($fullAddr, 302);
		} catch (Exception $e) {
			return;
		}
		if ($board == $baseAddr) return [];
		$numDays = 7;

		$faction = null; //Db::queryRow("select * from ccp_zfactions where ticker = :board", array(":board" => $board), 3600);
		$alli = $mdb->findDoc("information", ['cacheTime' => 3600, 'type' => 'allianceID', 'ticker' => strtoupper($board)], ['memberCount' => -1]);
		$corp = $mdb->findDoc("information", ['cacheTime' => 3600, 'type' => 'corporationID', 'ticker' => strtoupper($board)], ['memberCount' => -1]);

		$columnName = null;
		$id = null;
		if ($faction) {
			$p = array("factionID" => (int) $faction["factionID"]);
			$twig->addGlobal("statslink", "/faction/" . $faction["factionID"] . "/");
		} else if ($alli) {
			$p = array("allianceID" => (int) $alli["id"]);
			$twig->addGlobal("statslink", "/alliance/" . $alli["id"] . "/");
		} else if ($corp) {
			$p = array("corporationID" => (int) $corp["id"]);
			$twig->addGlobal("statslink", "/corporation/" . $corp["id"] . "/");
		} else $p = array();

		return $p;
	}

}

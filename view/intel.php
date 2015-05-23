<?php

$months = 3;

$data = array();
$parameters = ['groupID' => 30, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
$data["titans"]["data"] = Stats::getTop("characterID", $parameters);
$data["titans"]["title"] = "Titans";

$parameters = ['groupID' => 659, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
$data["moms"]["data"] = Stats::getTop("characterID", $parameters);
$data["moms"]["title"] = "Supercarriers";	

$app->render("intel.html", array("data" => $data));

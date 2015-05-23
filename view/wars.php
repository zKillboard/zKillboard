<?php

global $mdb;

$timeStarted = date("Y-m-dTH:m:s", time() - (86400 * 90));

$wars = array();
$wars[] = ["name" => "Recent Declared Wars - Open to Allies", "wars" => $mdb->find("information", ['cacheTime' => 1800, 'type' => 'warID', 'openForAll25ies' => true], ['timeStarted' => -1], 50)];
$wars[] = ["name" => "Recent Declared Wars - Mutual", "wars" => $mdb->find("information", ['cacheTime' => 1800, 'type' => 'warID', 'mutual' => true], ['timeStarted' => -1], 50)];
$wars[] = ["name" => "Recently Declared Wars", "wars" => $mdb->find("information", ['cacheTime' => 1800, 'type' => 'warID'], ['timeStarted' => -1], 25)];
$wars[] = ["name" => "Recently Finished Wars", "wars" => $mdb->find("information", ['cacheTime' => 1800, 'type' => 'warID'], ['timeFinished' => -1], 25)];
//$wars[] = ["name" => "Recent Active Wars by Kills", "wars" => $mdb->find("information", ['type' => 'warID', 'timeStarted' => ['$gte' => $timeStarted]], ["aggressor.shipsKilled" => -1, "defender.shipsKilled" => -1], 10)];
//$wars[] = ["name" => "Alltime Active Wars by Kills",  "wars" => $mdb->find("information", ['type' => 'warID'] ,["aggressor.shipsKilled" => -1, "defender.shipsKilled" => -1], 10)];
/*$wars[] = War::getNamedWars("Recent Active Wars by ISK", "select warID from zz_wars where timeStarted > date_sub(now(), interval 90 day) and timeFinished is null order by (agrIskKilled + dfdIskKilled) desc limit 10");
$wars[] = War::getNamedWars("Alltime Active Wars by ISK", "select warID from zz_wars where timeFinished is null order by (agrIskKilled + dfdIskKilled) desc limit 10");
$wars[] = War::getNamedWars("Recent Closed Wars by Kills", "select warID from zz_wars where timeStarted > date_sub(now(), interval 90 day) and timeFinished is not null order by (agrShipsKilled + dfdShipsKilled) desc limit 10");
$wars[] = War::getNamedWars("Alltime Closed Wars by Kills", "select warID from zz_wars where timeFinished is not null order by (agrShipsKilled + dfdShipsKilled) desc limit 10");
$wars[] = War::getNamedWars("Recent Closed Wars by ISK", "select warID from zz_wars where timeStarted > date_sub(now(), interval 90 day) and timeFinished is not null order by (agrIskKilled + dfdIskKilled) desc limit 10");
$wars[] = War::getNamedWars("Alltime Closed Wars by ISK", "select warID from zz_wars where timeFinished is not null order by (agrIskKilled + dfdIskKilled) desc limit 10");*/

$app->render("wars.html", array("warTables" => $wars));

<?php 

require_once "../init.php";

$_SERVER = [];
$_SERVER['REQUEST_URI'] = '/character/692995129/kills/';
$parameters = Util::convertUriToParameters($_SERVER['REQUEST_URI']);
$query = MongoFilter::buildQuery($parameters);

print_r($query);
exit();

$r = $mdb->find("killmails", $query, [], 999999, ['killID' => 1]);
$count = 0;
$total = count($r);
foreach ($r as $row) {
    $killID = $row['killID'];
    $count++;
    echo "$count  / $total : $killID\n";
    Killmail::deleteKillmail($killID);
}

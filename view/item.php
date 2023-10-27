<?php

global $mdb;

if (!is_numeric($id)) {
    header('Location: /');
}
$id = (int) $id;

$info = $mdb->findDoc('information', ['type' => 'typeID', 'id' => (int) $id, 'cacheTime' => 3600]);
if ($info == null) {
    $app->render('404.html', ['message' => 'Item not found']);

    return;
}
$info['typeName'] = $info['name'];
$info['description'] = str_replace('<br>', "\n", @$info['description']);
$info['description'] = strip_tags(@$info['description']);
$info['price'] = Price::getItemPrice($id, date('Ymd'));

global $mdb;
$cursor = $mdb->getCollection('killmails')->find(['involved.shipTypeID' => (int) $id]);
$hasKills = $cursor->hasNext();

$info['attributes'] = array();

//$info['market'] = Db::query('select * from zz_item_price_lookup where typeID = :typeID order by priceDate desc limit 30', array(':typeID' => $id));
$market = $mdb->findDoc('prices', ['typeID' => $id]);
if ($market == null) {
    $market = [];
}
unset($market['typeID']);
unset($market['_id']);
krsort($market);
$market = array_slice($market, 0, 30);
$info['market'] = $market;

$kills = $mdb->find('itemmails', ['typeID' => (int) $id], ['killID' => -1], 50);
if ($kills == null) $kills = [];
$victims = [];
foreach ($kills as $row) {
    $kill = $mdb->findDoc('killmails', ['killID' => $row['killID']]);
    if ($kill && @$kill['involved']) {
        $victim = $kill['involved'][0];
        $victim['destroyed'] = $row['killID'];
        $victims[] = $victim;
    }
}
Info::addInfo($victims);

$app->render('item.html', array('info' => $info, 'hasKills' => $hasKills, 'kills' => $victims));

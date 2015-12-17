<?php

require_once '../init.php';

$key = "zkb:siteMapsCreated:" . date('Ymd');
if ($redis->get($key) == true) exit();

$siteMapsDir = "$baseDir/public/sitemaps/";
if (!file_exists($siteMapsDir)) mkdir($siteMapsDir);
$locations = array();

$types = array('character', 'corporation', 'alliance', 'faction');

foreach ($types as $type) {
    $result = $mdb->getCollection('oneWeek')->distinct("involved.{$type}ID");
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd"/>');
    $count = 0;
    foreach ($result as $id) {
        ++$count;
        if ($count > 50000) {
            break;
        }
        $url = $xml->addChild('url');
        $loc = $url->addChild('loc', "https://$baseAddr/${type}/$id/");
    }
    file_put_contents("$siteMapsDir/${type}s.xml", $xml->asXML());
    $locations[] = "https://$baseAddr/sitemaps/${type}s.xml";
}

$killIDs = $mdb->find('oneWeek', [], ['killID' => -1], 50000);
$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd"/>');
foreach ($killIDs as $row) {
    $killID = $row['killID'];
    $url = $xml->addChild('url');
    $loc = $url->addChild('loc', "https://$baseAddr/kill/$killID/");
}
file_put_contents("$siteMapsDir/kills.xml", $xml->asXML());
$locations[] = "https://$baseAddr/sitemaps/kills.xml";
$xml = new SimpleXmlElement('<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.google.com/schemas/sitemap/0.84"/>');
foreach ($locations as $location) {
    $sitemap = $xml->addChild('sitemap');
    $sitemap->addChild('loc', $location);
}
file_put_contents("$siteMapsDir/sitemaps.xml", $xml->asXML());
file_get_contents("http://www.google.com/webmasters/sitemaps/ping?sitemap=https://$baseAddr/sitemaps/sitemaps.xml");

$redis->setex($key, 86400, true);

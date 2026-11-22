<?php

require_once "../init.php";

if ($kvc->get("zkb:noapi") == "true") exit();

if (date('Hi') < 1200) exit();
$rkey = "zkb:factions:" . date('Ymd');
if ($kvc->get($rkey) == "true") exit();

try {
    $manifest = SDE::manifest();
    foreach (SDE::rows($manifest, 'factions') as $faction) {
        $mdb->insertUpdate("information", ['type' => 'factionID', 'id' => $faction['factionID']], ['name' => $faction['name']['en']]);
    }
    $kvc->setex($rkey, 86400, "true");
} catch (Throwable $ex) {
    Util::out("SDE faction import failed: " . $ex->getMessage());
    exit(1);
}

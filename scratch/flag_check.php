<?php

require_once "../init.php";

$collection = $mdb->getCollection("esimails");
$flags = $collection->distinct("victim.items.flag");

$distinctFlags = [];
foreach ($flags as $flag) {
    if ($flag === null || $flag === "") {
        continue;
    }
    $distinctFlags[(string) $flag] = true;
}

$distinctFlags = array_keys($distinctFlags);
usort($distinctFlags, function ($a, $b) {
    return (int) $a <=> (int) $b;
});

$knownFlags = array_keys(Info::$effectToSlot);
$missingFlags = [];
foreach ($distinctFlags as $flag) {
    $resolved = Info::getFlagName((int) $flag);
    if ($resolved == null) {
        $missingFlags[] = $flag;
    }
}

Util::out("Distinct victim.items.flag count: " . sizeof($distinctFlags));
Util::out("Mapped flags in Info::\$effectToSlot: " . sizeof($knownFlags));

if (empty($missingFlags)) {
    Util::out("All distinct victim.items.flag values resolve via Info::getFlagName (including inferno grouped ranges).");
    return;
}

Util::out("Missing flag mappings (not resolvable by Info::getFlagName): " . implode(", ", $missingFlags));
Util::out("Suggested entries:");
foreach ($missingFlags as $flag) {
    Util::out("    '$flag' => 'UNKNOWN',");
}



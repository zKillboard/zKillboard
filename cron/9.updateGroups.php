<?php

require_once '../init.php';

$groupsPopulated = $mdb->findField('storage', 'contents', ['locker' => 'groupsPopulated']);
if ($groupsPopulated === true && date('H') % 12 != 0 && date('i') != 25) {
    exit;
}

$groups = CrestTools::getJSON('https://public-crest.eveonline.com/inventory/groups/');
$newGroups = 0;
$newItems = 0;

foreach ($groups['items'] as $group) {
    $href = $group['href'];
    $groupID = (int) getGroupID($href);
    $name = $group['name'];

    $exists = $mdb->count('information', ['type' => 'groupID', 'id' => $groupID]);
    if ($exists == 0) {
        $newGroups++;
    }
    $mdb->insertUpdate('information', ['type' => 'groupID', 'id' => $groupID], ['name' => $name, 'lastCrestUpdate' => $mdb->now()]);

    $types = CrestTools::getJSON($href);
    if (@$types['types'] != null) {
        foreach ($types['types'] as $type) {
            $typeID = (int) getTypeID($type['href']);
            $name = $type['name'];

            $exists = $mdb->count('information', ['type' => 'typeID', 'id' => $typeID]);
            if ($exists > 0) {
                continue;
            }

            Util::out("Discovered item: $name");
            ++$newItems;

            $mdb->insertUpdate('information', ['type' => 'typeID', 'id' => $typeID], ['name' => $name, 'groupID' => $groupID, 'lastCrestUpdate' => new MongoDate(1)]);
        }
    }
}
$mdb->insertUpdate('storage', ['locker' => 'groupsPopulated'], ['contents' => true]);
if ($newGroups > 0) {
    Log::irc("Added $newGroups new groupIDs");
}
if ($newItems > 0) {
    Log::irc("Added $newItems new typeIDs");
}

function getTypeID($href)
{
    $ex = explode('/', $href);

    return $ex[4];
}
function getGroupID($href)
{
    $ex = explode('/', $href);

    return $ex[5];
}

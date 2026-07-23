<?php

function handler($request, $response, $args, $container)
{
    global $mdb, $redis;

    $hash = strtolower((string) ($args['hash'] ?? ''));
    if (!preg_match('/^[0-9a-f]{16}$/', $hash)) {
        $response->getBody()->write('Invalid fit.');
        return $response->withStatus(404)->withHeader('Cache-Tag', 'www,fits');
    }

    $runID = $redis->get('zkb:fitKillers:runID');
    $query = ['hash' => $hash];
    if ($runID != null) $query['runID'] = $runID;

    $fit = $mdb->findDoc('fitkillers', $query, [], ['_id' => 0]);
    if ($fit == null) {
        $fit = $mdb->findDoc('fitkillers', ['hash' => $hash], ['updated' => -1], ['_id' => 0]);
    }
    if ($fit == null) {
        $response->getBody()->write('Fit not found.');
        return $response->withStatus(404)->withHeader('Cache-Tag', 'www,fits');
    }

    $killID = (int) ($fit['sampleLossID'] ?? 0);
    $esimail = $killID > 0 ? Kills::getEsiKill($killID) : null;
    if ($esimail == null || !isset($esimail['victim']['items'])) {
        $response->getBody()->write('Sample fit is not available.');
        return $response->withStatus(404)->withHeader('Cache-Tag', 'www,fits');
    }

    $shipTypeID = (int) ($fit['shipTypeID'] ?? $esimail['victim']['ship_type_id'] ?? 0);
    $items = [];
    collectFitKillerDetailItems($esimail['victim']['items'], $items);

    $fittingWheel = Detail::eftarray($items);
    $victim = [
        'shipTypeID' => $shipTypeID,
        'shipName' => Info::getInfoField('typeID', $shipTypeID, 'name'),
        'groupID' => Info::getInfoField('typeID', $shipTypeID, 'groupID'),
        'pip' => Info::getInfoField('typeID', $shipTypeID, 'pip'),
    ];
    $charName = Info::getInfoField('characterID', (int) ($esimail['victim']['character_id'] ?? 0), 'name');
    $fitName = $charName == "" ? $victim['shipName'] : "$charName's " . $victim['shipName'];
    if (strlen($fitName) > 50) $fitName = substr($fitName, 0, 50);
    $extra = [
        'fittingwheel' => $fittingWheel,
        'slotCounts' => Info::getSlotCounts($shipTypeID),
        'crest' => ['killID' => $killID],
    ];
    $eftText = '[' . $victim['shipName'] . ', ' . $fitName . ']' . "\n" . Fitting::EFT($fittingWheel);

    $response = $response
        ->withHeader('Content-Type', 'text/html; charset=utf-8')
        ->withHeader('Cache-Tag', "www,fits,kill:$killID");

    return $container->get('view')->render(
        $response,
        'fits_detail.pug',
        [
            'fit' => $fit,
            'killdata' => ['victim' => $victim],
            'extra' => $extra,
            'eftText' => $eftText,
            'hideFittingWheelActions' => true,
            'sampleLossID' => $killID,
        ]
    );
}

function collectFitKillerDetailItems($items, &$itemArray, $inContainer = 0, $parentFlag = 0)
{
    foreach ((array) $items as $item) {
        $typeID = (int) ($item['item_type_id'] ?? 0);
        if ($typeID <= 0) continue;

        $subItems = $item['items'] ?? null;
        unset($item['items']);
        unset($item['_stringValue']);

        $item['typeID'] = $typeID;
        $item['inContainer'] = $inContainer;
        if ($inContainer) $item['flag'] = $parentFlag;
        Info::addInfo($item);
        $itemArray[] = $item;

        if ($subItems != null) {
            collectFitKillerDetailItems($subItems, $itemArray, 1, $item['flag']);
        }
    }
}

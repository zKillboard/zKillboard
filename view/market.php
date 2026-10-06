<?php

function handler($request, $response, $args, $container)
{
    global $mdb;

    $item = max(1, (int) ($args['item'] ?? 44992));
    $info = $mdb->findDoc('information', ['type' => 'typeID', 'id' => $item], [], ['_id' => 0, 'name' => 1, 'groupID' => 1, 'categoryID' => 1]);
    if ($info === null) {
        return $container->get('view')->render(
            $response->withStatus(404)->withHeader('Cache-Tag', "www,error,404,market,type:$item"),
            '404.pug',
            ['message' => 'Market item not found']
        );
    }

    $name = (string) ($info['name'] ?? "Item $item");
    $categoryID = (int) ($info['categoryID'] ?? Info::getInfoField('groupID', (int) ($info['groupID'] ?? 0), 'categoryID'));
    return $container->get('view')->render(
        $response->withHeader('Cache-Tag', "www,market,type:$item"),
        'market.pug',
        [
            'marketItemID' => $item,
            'marketItemName' => $name,
            'marketItemPath' => ($categoryID === 6 ? '/ship/' : '/item/') . $item . '/',
            'pageTitle' => "$name | EVEconomy",
            'description' => "Browse live EVE Online market orders for $name across New Eden.",
            'showAds' => false,
        ]
    );
}

<?php

function handler($request, $response, $args, $container)
{
    try {
        if ($request->getHeaderLine('X-Requested-With') !== 'XMLHttpRequest') throw new Exception('Invalid save request.');
        if (!User::getUserID()) throw new Exception('Please log into zKillboard to save a fit to your character.');
        $body = (string) $request->getBody();
        if (strlen($body) > 100000) throw new Exception('This fit is too large to save.');
        $fit = json_decode($body, true);
        if (!is_array($fit) || !is_string($fit['name'] ?? null) || trim($fit['name']) === '' || strlen($fit['name']) > 200) throw new Exception('Invalid fit name.');
        if (!is_int($fit['ship_type_id'] ?? null) || (int) Info::getInfoField('typeID', $fit['ship_type_id'], 'categoryID') !== 6) throw new Exception('Only ship fittings can be saved.');
        if ((int) Info::getInfoField('typeID', $fit['ship_type_id'], 'groupID') === 29) throw new Exception('ESI does not accept capsule fittings.');
        if (!is_array($fit['items'] ?? null) || count($fit['items']) < 1 || count($fit['items']) > 255) throw new Exception('A fit must contain between 1 and 255 item entries.');
        $items = [];
        foreach ($fit['items'] as $item) {
            if (!is_array($item) || !is_int($item['type_id'] ?? null) || $item['type_id'] < 1 || !is_int($item['quantity'] ?? null) || $item['quantity'] < 1 || $item['quantity'] > 1000000) throw new Exception('Invalid fitting item.');
            if (!is_string($item['flag'] ?? null) || !preg_match('/^(Cargo|DroneBay|HiSlot[0-7]|MedSlot[0-7]|LoSlot[0-7]|RigSlot[0-2]|SubSystemSlot[0-3])$/D', $item['flag'])) throw new Exception('Invalid fitting slot.');
            if (!in_array($item['flag'], ['Cargo', 'DroneBay']) && $item['quantity'] !== 1) throw new Exception('Only one module can occupy a slot.');
            $items[] = ['flag' => $item['flag'], 'type_id' => $item['type_id'], 'quantity' => $item['quantity']];
        }
        $result = ESI::saveFitting(0, 0, [
            'name' => mb_substr($fit['name'], 0, 50),
            'description' => 'Saved from https://zkillboard.com/simulate/',
            'ship_type_id' => $fit['ship_type_id'],
            'items' => $items,
        ]);
        $message = $result['message'] ?? 'Unable to save this fit.';
    } catch (Exception $ex) {
        $message = htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8');
    }
    $response->getBody()->write($message);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
}

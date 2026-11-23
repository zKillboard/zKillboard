<?php

require_once __DIR__ . '/../classes/Detail.php';
require_once __DIR__ . '/../classes/Fitting.php';

$items = [
    ['typeID' => 2873, 'typeName' => '125mm Gatling AutoCannon II', 'flag' => 27, 'flagName' => 'High Slots', 'fittable' => 1, 'quantity_dropped' => 0, 'quantity_destroyed' => 1],
    ['typeID' => 12608, 'typeName' => 'Hail S', 'flag' => 27, 'flagName' => 'High Slots', 'fittable' => 0, 'quantity_dropped' => 0, 'quantity_destroyed' => 1],
    ['typeID' => 2456, 'typeName' => 'Hobgoblin I', 'flag' => 87, 'flagName' => 'Drone Bay', 'fittable' => 0, 'quantity_dropped' => 2, 'quantity_destroyed' => 3],
    ['typeID' => 12625, 'typeName' => 'Barrage S', 'flag' => 5, 'flagName' => 'Cargo Hold', 'fittable' => 0, 'quantity_dropped' => 1000, 'quantity_destroyed' => 2000],
];

$fit = Detail::eftarray($items);
$eft = Fitting::EFT($fit);
if (strpos($eft, '125mm Gatling AutoCannon II,Hail S') === false) throw new Exception('EFT did not preserve the loaded charge');
if (strpos($eft, 'Hobgoblin I x5') === false) throw new Exception('EFT did not preserve drones');
if (strpos($eft, 'Barrage S x3000') === false) throw new Exception('EFT did not include cargo');

$dna = Fitting::DNA($items, 22460);
if (strpos($dna, '12625;3000:') === false) throw new Exception('DNA did not include cargo');
$link = '<url=fitting:' . $dna . '>Eris</url>';
if ($link !== '<url=fitting:22460:2873;1:12608;1:2456;5:12625;3000::>Eris</url>') throw new Exception('DNA link was not formatted for EVE');

class Kills
{
    public static function getEsiKill($id)
    {
        return $id === 123 ? ['victim' => ['items' => [
            ['flag' => 89, 'item_type_id' => 3084],
            ['flag' => 89, 'item_type_id' => 3084],
            ['flag' => 5, 'item_type_id' => 34],
        ]]] : null;
    }
}

class Info
{
    public static function getInfoField($field, $id, $key)
    {
        return in_array($id, [12608, 12625]) ? 8 : 7;
    }
}

function protobufFields($data)
{
    $fields = [];
    $offset = 0;
    $readInteger = function () use ($data, &$offset) {
        $value = 0;
        $shift = 0;
        do {
            if ($offset >= strlen($data) || $shift > 63) throw new Exception('Invalid varint');
            $byte = ord($data[$offset++]);
            $value |= ($byte & 127) << $shift;
            $shift += 7;
        } while ($byte & 128);
        return $value;
    };
    while ($offset < strlen($data)) {
        $tag = $readInteger();
        $value = $readInteger();
        if (($tag & 7) === 2) {
            $length = $value;
            $value = substr($data, $offset, $length);
            $offset += $length;
            if ($offset > strlen($data)) throw new Exception('Truncated record');
        } elseif (($tag & 7) !== 0) throw new Exception('Unexpected wire type');
        $fields[$tag >> 3][] = $value;
    }
    return $fields;
}

foreach ([0, 123, 456] as $capsuleID) {
    $url = Fitting::eveShipFitURL(587, 'Test "fit"', $items, $capsuleID);
    if (!str_starts_with($url, 'https://eveship.fit/?fit=esf1:')) throw new Exception('Wrong ESF link format');
    parse_str(parse_url($url, PHP_URL_QUERY), $params);
    $data = base64_decode(strtr(substr($params['fit'], 5), '-_', '+/'), true);
    $fit = protobufFields(protobufFields($data)[1][0]);
    if ($fit[1][0] !== 1 || $fit[2][0] !== 587 || $fit[3][0] !== 'Test "fit"') throw new Exception('Invalid ESF header');
    $entries = [];
    foreach ($fit[5] as $entry) {
        $entry = protobufFields($entry);
        $entries[$entry[1][0]] = $entry;
    }
    if ($entries[2873][4][0] !== 12608 || $entries[2873][5][0] !== 1) throw new Exception('Loaded charge lost');
    if ($entries[2456][2][0] !== 5 || $entries[2456][10][0] !== 8) throw new Exception('Drone bay lost');
    if ($entries[12625][2][0] !== 3000 || $entries[12625][10][0] !== 7) throw new Exception('Cargo lost');
    if (isset($entries[3084]) !== ($capsuleID === 123)) throw new Exception('Related implants incorrect');
}

$url = Fitting::eveShipFitURL(35825, 'Structure', [
    ['typeID' => 35891, 'flag' => 166, 'quantity_destroyed' => 1],
    ['typeID' => 40557, 'flag' => 159, 'quantity_destroyed' => 1],
    ['typeID' => 40557, 'flag' => 158, 'quantity_destroyed' => 9],
]);
parse_str(parse_url($url, PHP_URL_QUERY), $params);
$fit = protobufFields(protobufFields(base64_decode(strtr(substr($params['fit'], 5), '-_', '+/')))[1][0]);
$entries = array_map('protobufFields', $fit[5]);
if ($fit[2][0] !== 35825 || $entries[0] !== [10 => [6]] || $entries[1] !== [10 => [6]] || $entries[2][1][0] !== 35891) throw new Exception('Structure service slot lost');
if ($entries[3][1][0] !== 40557 || $entries[3][2][0] !== 1) throw new Exception('Single deployed fighter became a full squadron');
if ($entries[4][1][0] !== 40557 || $entries[4][2][0] !== 9 || $entries[4][10][0] !== 8) throw new Exception('Fighter bay lost');

echo "Fitting export checks passed: ESF header, charges, drones, cargo, implants, structures and fighters.\n";

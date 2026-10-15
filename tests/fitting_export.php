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

echo "Fitting export checks passed.\n";

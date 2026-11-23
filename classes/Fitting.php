<?php

class Fitting
{
    private static function arrayToEFT($items)
    {
        if ($items == null) {
            return '';
        }
        $text = '';
        $line = '';
        foreach ($items as $flags) {
            $cnt = 0;
            foreach ($flags as $i) {
                if ($cnt == 0) {
                    $line = $i['typeName'];
                } else {
                    $line .= ','.$i['typeName'];
                }
                ++$cnt;
            }
            $text .= "$line\n";
        }

        return "$text\n";
    }

    public static function EFT($array)
    {
        $eft = self::arrayToEft(@$array['low']);
        $eft .= self::arrayToEft(@$array['mid']);
        $eft .= self::arrayToEft(@$array['high']);
        $eft .= self::arrayToEft(@$array['rig']);
        $eft .= self::arrayToEft(@$array['sub']);

        $item = '';
        if (isset($array['drone'])) {
            foreach ($array['drone'] as $flags) {
                foreach ($flags as $items) {
                    $item .= $items['typeName'].' x'.$items['qty']."\n";
                }
                $eft .= $item;
            }
        }

        if (isset($array['cargo'])) {
            $eft .= "\n";
            foreach ($array['cargo'] as $flags) {
                foreach ($flags as $items) {
                    $eft .= $items['typeName'].' x'.$items['qty']."\n";
                }
            }
        }

        return trim($eft);
    }

    public static function eveShipFitURL($shipTypeID, $name, $items, $capsuleID = 0)
    {
        // esf/1 protobuf fields: https://github.com/EVEShipFit/esf-format/blob/main/esf.proto
        $varint = function ($value) {
            $bytes = '';
            do {
                $bytes .= chr(($value & 127) | ($value > 127 ? 128 : 0));
                $value >>= 7;
            } while ($value);
            return $bytes;
        };
        $field = function ($number, $value) use ($varint) {
            return is_string($value) ? $varint(($number << 3) | 2) . $varint(strlen($value)) . $value
                : $varint($number << 3) . $varint($value);
        };
        $fit = $field(1, 1) . $field(2, (int) $shipTypeID);
        if ($name !== '') $fit .= $field(3, $name);
        if ($capsuleID > 0) {
            $capsule = Kills::getEsiKill($capsuleID);
            foreach ($capsule['victim']['items'] ?? [] as $item) {
                if ((int) $item['flag'] !== 89) continue;
                $items[] = ['typeID' => (int) $item['item_type_id'], 'flag' => 89, 'quantity_destroyed' => 1];
            }
        }
        $racks = [];
        $stored = [];
        $implants = [];
        foreach ($items as $item) {
            if (!empty($item['inContainer'])) continue;
            $typeID = (int) $item['typeID'];
            $quantity = (int) ($item['quantity_dropped'] ?? 0) + (int) ($item['quantity_destroyed'] ?? 0);
            if ($typeID < 1 || $quantity < 1) continue;
            $flag = (int) $item['flag'];
            if (in_array($flag, [88, 89])) {
                $implants[$typeID] = $field(1, $typeID);
                continue;
            }
            $rack = null;
            foreach ([[11, 18, 3], [19, 26, 2], [27, 34, 1], [92, 99, 4], [125, 132, 5], [159, 163, 28], [164, 171, 6]] as [$first, $last, $location]) {
                if ($flag >= $first && $flag <= $last) {
                    $rack = $location;
                    $slot = $flag - $first;
                    break;
                }
            }
            if ($rack !== null) {
                if ((int) Info::getInfoField('typeID', $typeID, 'categoryID') === 8) {
                    $racks[$rack][$slot]['charge'] = $field(4, $typeID) . $field(5, $quantity);
                } else {
                    $racks[$rack][$slot]['module'] = $field(1, $typeID) . ($rack === 28 ? $field(2, $quantity) : '');
                }
            } else {
                $stored[] = $field(1, $typeID) . ($quantity !== 1 ? $field(2, $quantity) : '') . $field(10, in_array($flag, [87, 158]) ? 8 : 7);
            }
        }
        foreach ([5, 1, 2, 3, 4, 6, 28] as $rack) {
            $slots = $racks[$rack] ?? [];
            if (!$slots) continue;
            for ($slot = 0; $slot <= max(array_keys($slots)); $slot++) {
                $entry = isset($slots[$slot]['module']) ? $slots[$slot]['module'] . ($slots[$slot]['charge'] ?? '') : $field(10, $rack);
                $fit .= $field(5, $entry);
            }
        }
        foreach (array_merge($stored, array_values($implants)) as $entry) $fit .= $field(5, $entry);
        return 'https://eveship.fit/?fit=esf1:' . rtrim(strtr(base64_encode($field(1, $fit)), '+/', '-_'), '=');
    }

    public static function DNA($array = array(), $ship)
    {
        $goodspots = array('High Slots', 'SubSystems', 'Rigs', 'Low Slots', 'Mid Slots', 'Drone Bay', 'Fuel Bay', 'Cargo Hold');
        $fitArray = array();
        $fitString = $ship.':';

        foreach ($array as $item) {
            if (isset($item['flagName']) && in_array($item['flagName'], $goodspots)) {
                if (isset($fitArray[$item['typeID']])) {
                    $fitArray[$item['typeID']]['count'] = $fitArray[$item['typeID']]['count'] + (@$item['quantity_dropped'] + @$item['quantity_destroyed']);
                } else {
                    $fitArray[$item['typeID']] = array('count' => (@$item['quantity_dropped'] + @$item['quantity_destroyed']));
                }
            }
        }

        foreach ($fitArray as $key => $item) {
            $fitString .= "$key;".$item['count'].':';
        }
        $fitString .= ':';

        return $fitString;
    }
}

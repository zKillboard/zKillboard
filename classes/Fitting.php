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

        return trim($eft);
    }

    public static function simulationPayload($fit)
    {
        $ship = (string) (int) $fit['ship_type_id'];
        if (!empty($fit['name'])) $ship .= ':' . rawurlencode($fit['name']);
        $payload = [$ship];
        foreach ($fit['items'] as $item) {
            $flag = (int) $item['flag'];
            if ($flag != 87 && $flag != 158 && !($flag >= 11 && $flag <= 34) && !($flag >= 92 && $flag <= 99) && !($flag >= 125 && $flag <= 132) && !($flag >= 164 && $flag <= 171)) continue;
            $quantity = (int) $item['quantity'];
            if ($quantity < 1) continue;
            $value = $flag . ':' . (int) $item['type_id'];
            if ($quantity != 1) $value .= ':' . $quantity;
            $payload[] = $value;
        }

        return implode(';', $payload);
    }

    public static function DNA($array = array(), $ship)
    {
        $goodspots = array('High Slots', 'SubSystems', 'Rigs', 'Low Slots', 'Mid Slots', 'Drone Bay', 'Fuel Bay');
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

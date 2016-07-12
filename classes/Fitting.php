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

    public static function DNA($array = array(), $ship)
    {
        $goodspots = array('High Slots', 'SubSystems', 'Rigs', 'Low Slots', 'Mid Slots', 'Drone Bay', 'Fuel Bay');
        $fitArray = array();
        $fitString = $ship.':';

        foreach ($array as $item) {
            if (isset($item['flagName']) && in_array($item['flagName'], $goodspots)) {
                if (isset($fitArray[$item['typeID']])) {
                    $fitArray[$item['typeID']]['count'] = $fitArray[$item['typeID']]['count'] + (@$item['quantityDropped'] + @$item['quantityDestroyed']);
                } else {
                    $fitArray[$item['typeID']] = array('count' => (@$item['quantityDropped'] + @$item['quantityDestroyed']));
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

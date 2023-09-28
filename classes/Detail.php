<?php

class Detail
{
    public static function involvedships($array)
    {
        $involved = array();
        foreach ($array as $inv) {
            if (isset($involved[$inv['shipTypeID']]) && isset($inv['shipName'])) {
                $involved[$inv['shipTypeID']] = array('shipName' => $inv['shipName'], 'shipTypeID' => $inv['shipTypeID'], 'count' => $involved[$inv['shipTypeID']]['count'] + 1);
            } elseif (isset($inv['shipTypeID']) && isset($inv['shipName'])) {
                $involved[$inv['shipTypeID']] = array('shipName' => $inv['shipName'], 'shipTypeID' => $inv['shipTypeID'], 'count' => 1);
            } else {
                continue;
            }
        }

        usort($involved, 'sortByOrder');

        return $involved;
    }

    public static function sortByOrder($a, $b)
    {
        return $a['count'] < $b['count'];
    }

    public static function usdeurgbp($totalprice)
    {
        $usd = 17;
        $eur = 13;
        $gbp = 10;
        $plex = 500 * Price::getItemPrice(44992, date('Ymd'));
        $usdval = $plex / $usd;
        $eurval = $plex / $eur;
        $gbpval = $plex / $gbp;

        return array('usd' => $totalprice / $usdval, 'eur' => $totalprice / $eurval, 'gbp' => $totalprice / $gbpval);
    }

    public static function eftarray($items)
    {
        // EFT / Fitting Wheel
        $eftarray = array();
        $eftarray['high'] = array(); // high
        $eftarray['mid'] = array(); // mid
        $eftarray['low'] = array(); // low
        $eftarray['rig'] = array(); // rig
        $eftarray['drone'] = array(); // drone
        $eftarray['sub'] = array(); // sub
        $subSlot = 125;

        foreach ($items as $itm) {
            if (!isset($itm['inContainer'])) {
                $itm['inContainer'] = 0;
            }
            if (!isset($itm['flagName'])) {
                $itm['flagName'] = Info::getFlagName($itm['flag']);
                if ($itm['flagName'] == null || $itm['flagName'] == '') {
                    $itm['flagName'] = 'Unknown flag ' . $itm['flag'];
                    Log::log('Unknown flag '.$itm['flag'] . " : " .  @$_SERVER['REQUEST_URI']);
                }
            }

            if ($itm['flagName'] == 'Infantry Modules') {
                $itm['flagName'] = 'Mid Slots';
            }
            if ($itm['flagName'] == 'Infantry Weapons') {
                $itm['flagName'] = 'High Slots';
            }
            if ($itm['flagName'] == 'Infantry Equipment') {
                $itm['flagName'] = 'Low Slots';
            }
            if ($itm['flag'] == 89) {
                $slot = Info::getInfoField('typeID', $itm['typeID'], 'implantSlot');
                if ($slot <= 5 && $slot >= 1) {
                    $itm['flagName'] = 'High Slots';
                    $itm['flag'] = 27 + ($slot - 1);
                } elseif ($slot > 5 && $slot <= 10) {
                    $itm['flagName'] = 'Low Slots';
                    $itm['flag'] = 11 + ($slot - 6);
                }
                $itm['fittable'] = 1;
            }

            if (!isset($itm['flag']) || $itm['flag'] == 0) {
                if ($itm['flagName'] == 'High Slots') {
                    $itm['flag'] = 27;
                }
                if ($itm['flagName'] == 'Mid Slots') {
                    $itm['flag'] = 19;
                }
                if ($itm['flagName'] == 'Low Slots') {
                    $itm['flag'] = 11;
                }
            }

            $key = $itm['typeName'].'|'.$itm['flagName'];
            if (isset($itm['flagName'])) {
                if ($itm['flagName'] == 'Structure Service Slots' || $itm['flagName'] == 'SubSystems' || $itm['flagName'] == 'Rigs' || ($itm['fittable'] && $itm['inContainer'] == 0)) {
                    // not ammo or whatever

                    $repeats = @$itm['quantity_dropped'] + @$itm['quantity_destroyed'];
                    $i = 0;
                    while ($i < $repeats) {
                        if ($itm['flagName'] == 'High Slots') {
                            high:
                            if (isset($eftarray['high'][$itm['flag']])) {
                                $itm['flag'] = $itm['flag'] + 1;
                                goto high;
                            }
                            $eftarray['high'][$itm['flag']][] = array('typeName' => $itm['typeName'], 'typeID' => $itm['typeID']);
                        }
                        if ($itm['flagName'] == 'Mid Slots') {
                            mid:
                            if (isset($eftarray['mid'][$itm['flag']])) {
                                $itm['flag'] = $itm['flag'] + 1;
                                goto mid;
                            }
                            $eftarray['mid'][$itm['flag']][] = array('typeName' => $itm['typeName'], 'typeID' => $itm['typeID']);
                        }
                        if ($itm['flagName'] == 'Low Slots') {
                            low:
                            if (isset($eftarray['low'][$itm['flag']])) {
                                $itm['flag'] = $itm['flag'] + 1;
                                goto low;
                            }
                            $eftarray['low'][$itm['flag']][] = array('typeName' => $itm['typeName'], 'typeID' => $itm['typeID']);
                        }
                        if ($itm['flagName'] == 'Rigs') {
                            rigs:
                            if (isset($eftarray['rig'][$itm['flag']])) {
                                $itm['flag'] = $itm['flag'] + 1;
                                goto rigs;
                            }
                            $eftarray['rig'][$itm['flag']][] = array('typeName' => $itm['typeName'], 'typeID' => $itm['typeID']);
                        }
                        if ($itm['flagName'] == 'SubSystems' || $itm['flagName'] == 'Structure Service Slots') {
                            subs:
                            if (isset($eftarray['sub'][$itm['flag']])) {
                                $itm['flag'] = $itm['flag'] + 1;
                                goto subs;
                            }
                            if ($itm['flagName'] == 'Structure Service Slots') {
                                $itm['flag'] = $subSlot;
                                $subSlot++;
                            }
                            $eftarray['sub'][$itm['flag']][] = array('typeName' => $itm['typeName'], 'typeID' => $itm['typeID']);
                        }
                        ++$i;
                    }
                } else {
                    if ($itm['flagName'] == 'Drone Bay') {
                        $eftarray['drone'][$itm['flag']][] = array('typeName' => $itm['typeName'], 'typeID' => $itm['typeID'], 'qty' => @$itm['quantity_dropped'] + @$itm['quantity_destroyed']);
                    }
                }
            }
        }

        // Ammo shit
        foreach ($items as $itm) {
            if (!isset($itm['inContainer'])) {
                $itm['inContainer'] = 0;
            }
            if ($itm['inContainer'] == 0 && !$itm['fittable'] && isset($itm['flagName'])) {
                // possibly ammo

                if ($itm['flagName'] == 'High Slots') { // high slot ammo
                    $eftarray['high'][$itm['flag']][] = array('typeName' => $itm['typeName'], 'typeID' => $itm['typeID'], 'charge' => true);
                }
                if ($itm['flagName'] == 'Mid Slots') { // mid slot ammo
                    $eftarray['mid'][$itm['flag']][] = array('typeName' => $itm['typeName'], 'typeID' => $itm['typeID'], 'charge' => true);
                }
                if ($itm['flagName'] == 'Low Slots') { // mid slot ammo
                    $eftarray['low'][$itm['flag']][] = array('typeName' => $itm['typeName'], 'typeID' => $itm['typeID'], 'charge' => true);
                }
            }
        }
        foreach ($eftarray as $key => $value) {
            if (sizeof($value)) {
                asort($value);
                $eftarray[$key] = $value;
            } else {
                unset($eftarray[$key]);
            }
        }

        return $eftarray;
    }

    public static function combineditems($md5, $items)
    {
        // Create the new item array with combined items and whatnot
        $itemList = array();
        foreach ($items as $itm) {
            if (!isset($itm['inContainer'])) {
                $itm['inContainer'] = 0;
            }
            if ($itm['inContainer'] == 1) {
                $itm['flag'] = 0;
            }
            if (!isset($itm['flagName'])) {
                $itm['flagName'] = Info::getFlagName($itm['flag']);
            }
            for ($i = 0; $i <= 1; ++$i) {
                $mItem = $itm;
                if ($i == 0) {
                    @$mItem['quantity_dropped'] = 0;
                }
                if ($i == 1) {
                    @$mItem['quantity_destroyed'] = 0;
                }
                if (@$mItem['quantity_dropped'] == 0 && @$mItem['quantity_destroyed'] == 0) {
                    continue;
                }
                $key = static::buildItemKey($mItem);

                if (!isset($itemList[$key])) {
                    $itemList[$key] = $mItem;
                    $itemList[$key]['price'] = $mItem['price'] * ($mItem['quantity_dropped'] + $mItem['quantity_destroyed']);
                } else {
                    $itemList[$key]['quantity_dropped'] += @$mItem['quantity_dropped'];
                    $itemList[$key]['quantity_destroyed'] += @$mItem['quantity_destroyed'];
                    $itemList[$key]['price'] += $mItem['price'] * (@$mItem['quantity_dropped'] + @$mItem['quantity_destroyed']);
                }
            }
        }

        return $itemList;
    }

    public static function fullCombinedItems($md5, $items)
    {
        // Create the new item array with combined items and whatnot
        $itemList = array();
        foreach ($items as $itm) {
            if ($itm['fittable'] != 1) {
                continue;
            }
            if (!isset($itm['inContainer'])) {
                $itm['inContainer'] = 0;
            }
            if ($itm['inContainer'] == 1) {
                $itm['flag'] = 0;
            }
            if (!isset($itm['flagName'])) {
                $itm['flagName'] = Info::getFlagName($itm['flag']);
            }

            $mItem = $itm;
            if ($mItem['quantity_dropped'] == 0 && $mItem['quantity_destroyed'] == 0) {
                continue;
            }
            $key = $itm['typeID'];

            if (!isset($itemList[$key])) {
                $itemList[$key] = $mItem;
                $itemList[$key]['price'] = $mItem['price'] * ($mItem['quantity_dropped'] + $mItem['quantity_destroyed']);
            } else {
                $itemList[$key]['quantity_dropped'] += $mItem['quantity_dropped'];
            }
            $itemList[$key]['quantity_dropped'] += $mItem['quantity_destroyed'];
            $mItem['quantity_destroyed'] = 0;
            $itemList[$key]['price'] += $mItem['price'] * ($mItem['quantity_dropped'] + $mItem['quantity_destroyed']);
        }

        return $itemList;
    }

    public static function buildItemKey($itm)
    {
        $key = $itm['typeName'].($itm['singleton'] == 2 ? ' (Copy)' : '');
        $key .= '|'.($itm['quantity_dropped'] > 0 ? 'dropped' : 'destroyed');
        if (!isset($itm['flagName'])) {
            $itm['flagName'] = Info::getFlagName($itm['flag']);
        }
        $key .= '|'.$itm['flagName'];
        if (in_array($itm['groupID'], array(340, 649)) && isset($itm['items'])) {
            $key .= microtime().rand(0, 10000);
        }

        return $key;
    }

    public static function involvedCorpsAndAllis($md5, $involved)
    {
        $involvedAlliCount = 0;
        $involvedCorpCount = 0;
        // Create the involved corps / alliances list
        $invAll = array();
        foreach ($involved as $inv) {
            $allianceID = $inv['allianceID'];
            $corporationID = $inv['corporationID'];
            if (!isset($invAll["$allianceID"])) {
                ++$involvedAlliCount;
                $invAll["$allianceID"] = array();
                if ($allianceID != 0) {
                    $invAll["$allianceID"]['allianceName'] = $inv['allianceName'];
                }
                if ($allianceID != 0) {
                    $invAll["$allianceID"]['name'] = $inv['allianceName'];
                }
                if ($allianceID != 0) {
                    $invAll["$allianceID"]['allianceID'] = $allianceID;
                }
                $invAll["$allianceID"]['corporations'] = array();
                $invAll["$allianceID"]['involved'] = 0;
            }
            $involvedCount = $invAll["$allianceID"]['involved'];
            $invAll["$allianceID"]['involved'] = $involvedCount + 1;

            if (!isset($invAll["$allianceID"]['corporations']["$corporationID"])) {
                ++$involvedCorpCount;
                $invAll["$allianceID"]['corporations']["$corporationID"] = array();
                $invAll["$allianceID"]['corporations']["$corporationID"]['corporationName'] = isset($inv['corporationName']) ? $inv['corporationName'] : '';
                $invAll["$allianceID"]['corporations']["$corporationID"]['name'] = isset($inv['corporationName']) ? $inv['corporationName'] : '';
                $invAll["$allianceID"]['corporations']["$corporationID"]['corporationID'] = $corporationID;
                $invAll["$allianceID"]['corporations']["$corporationID"]['involved'] = 0;
            }
            $involvedCount = $invAll["$allianceID"]['corporations']["$corporationID"]['involved'];
            $invAll["$allianceID"]['corporations']["$corporationID"]['involved'] = $involvedCount + 1;
        }
        uasort($invAll, 'involvedSort');
        foreach ($invAll as $id => $alliance) {
            $corps = $alliance['corporations'];
            uasort($corps, 'involvedSort');
            $invAll["$id"]['corporations'] = $corps;
        }
        if ($involvedCorpCount <= 1 && $involvedAlliCount <= 1) {
            $invAll = array();
        }

        return $invAll;
    }

    public static function involvedSort($field1, $field2)
    {
        if ($field1['involved'] == $field2['involved'] && isset($field1['name']) && isset($field2['name'])) {
            return strcasecmp($field1['name'], $field2['name']);
        }

        return $field2['involved'] - $field1['involved'];
    }

    public static function droppedIsk($md5, $items)
    {
        $droppedisk = 0;
        foreach ($items as $dropped) {
            $droppedisk += $dropped['price'] * ($dropped['singleton'] ? $dropped['quantity_dropped'] / 100 : $dropped['quantity_dropped']);
        }

        return $droppedisk;
    }

    public static function fittedIsk($md5, $items)
    {
        $fittedIsk = 0;
        $flags = array('High Slots', 'Mid Slots', 'Low Slots', 'SubSystems', 'Rigs', 'Drone Bay', 'Fuel Bay', 'Structure Service Slots');
        foreach ($items as $item) {
            if (isset($item['flagName']) && in_array($item['flagName'], $flags)) {
                $qty = isset($item['quantity_dropped']) ? $item['quantity_dropped'] : 0;
                $qty += isset($item['quantity_destroyed']) ? $item['quantity_destroyed'] : 0;
                $fittedIsk = $fittedIsk + ($item['price'] * $qty);
            }
        }

        return $fittedIsk;
    }
}

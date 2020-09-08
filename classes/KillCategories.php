<?php


class KillCategories
{

    public static function getKills($type, $page, $isApi = false)
    {
        $limit = 50;
        if (!$isApi) $maxPage = 20;
        else $maxPage = 1000;
        if ($page > $maxPage && $type == '') {
            return [];
        }
        if ($page > $maxPage && $type != '') {
            return [];
        }

        switch ($type) {
            case '5b':
                $kills = Kills::getKills(['iskValue' => 5000000000, 'page' => $page, 'cacheTime' => 60]);
                break;
            case '10b':
                $kills = Kills::getKills(['iskValue' => 10000000000, 'page' => $page, 'cacheTime' => 60]);
                break;
            case 'bigkills':
                $kills = Kills::getKills(array('groupID' => array(547, 485, 1538, 513, 902, 30, 659, 883), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'citadels':
                $kills = Kills::getKills(array('groupID' => array(1657, 1404, 1406), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'awox':
                $kills = Kills::getKills(['awox' => true, 'page' => $page]);
                break;
            case 't1':
                $kills = Kills::getKills(array('groupID' => array(419, 27, 29, 547, 26, 420, 25, 28, 941, 463, 237, 31), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 't2':
                $kills = Kills::getKills(array('groupID' => array(324, 898, 906, 540, 830, 893, 543, 541, 833, 358, 894, 831, 902, 832, 900, 834, 380), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 't3':
                $kills = Kills::getKills(array('groupID' => array(963), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'frigates':
                $kills = Kills::getKills(array('groupID' => array(324, 893, 25, 831, 237), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'destroyers':
                $kills = Kills::getKills(array('groupID' => array(420, 541), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'cruisers':
                $kills = Kills::getKills(array('groupID' => array(906, 26, 833, 358, 894, 832, 963), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'battlecruisers':
                $kills = Kills::getKills(array('groupID' => array(419, 540), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'battleships':
                $kills = Kills::getKills(array('groupID' => array(27, 898, 900), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'solo':
                $kills = Kills::getKills(array('losses' => true, 'solo' => true, 'limit' => $limit, '!shipTypeID' => 670, '!groupID' => array(237, 31), 'cacheTime' => 3600, 'page' => $page));
                break;
            case 'capitals':
                $kills = Kills::getKills(array('groupID' => array(547, 485, 1538), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'freighters':
                $kills = Kills::getKills(array('groupID' => array(513, 902, 941), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'rorquals':
                $kills = Kills::getKills(array('groupID' => array(883), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'supers':
                $kills = Kills::getKills(array('groupID' => array(30, 659), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
                break;
            case 'lowsec':
                $kills = Kills::getKills(array('lowsec' => true, 'page' => $page));
                break;
            case 'highsec':
                $kills = Kills::getKills(array('highsec' => true, 'page' => $page));
                break;
            case 'nullsec':
                $kills = Kills::getKills(array('nullsec' => true, 'page' => $page));
                break;
            case 'w-space':
                $kills = Kills::getKills(array('w-space' => true, 'page' => $page));
                break;
            case 'ganked':
                $kills = Kills::getKills(array('ganked' => true, 'page' => $page));
                break;
            case 'abyssalpvp':
                $kills = Kills::getKills(['solarSystemID' => ['$gte' => 32000000], 'npc' => false, 'page' => $page]);
                break;
            case 'abyssal':
                $kills = Kills::getKills(['solarSystemID' => ['$gte' => 32000000], 'page' => $page]);
                break;
            case 'abyssal1b':
                $kills = Kills::getKills(['solarSystemID' => ['$gte' => 32000000], 'page' => $page, 'iskValue' => 1000000000]);
                break;
            case 'padding':
                $kills = Kills::getKills(['labels' => 'padding']);
                break;
            default:
                $kills = Kills::getKills(array('combined' => true, 'page' => $page));
                break;
        }

        return $kills;
    }
}

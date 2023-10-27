<?php

use cvweiss\redistools\RedisCache;
use cvweiss\redistools\RedisTtlCounter;

class Util
{
    public static function getCrest($url)
    {
        \Perry\Setup::$fetcherOptions = ['connect_timeout' => 15, 'timeout' => 30];

        return \Perry\Perry::fromUrl($url);
    }

    public static function pluralize($string)
    {
        if (!self::endsWith($string, 's')) {
            return $string.'s';
        } else {
            return $string.'es';
        }
    }

    /**
     * @param string $haystack
     * @param string $needle
     */
    public static function startsWith($haystack, $needle)
    {
        $length = strlen($needle);

        return substr($haystack, 0, $length) === $needle;
    }

    public static function endsWith($haystack, $needle)
    {
        return substr($haystack, -strlen($needle)) === $needle;
    }

    private static $formatIskIndexes = array('', 'k', 'm', 'b', 't', 'tt', 'ttt');

    public static function formatIsk($value, $int = false)
    {
        $numDecimals = ($int || (((int) $value) == $value) && $value < 10000) ? 0 : 2;
        if ($value == 0) {
            return number_format(0, $numDecimals);
        }
        if ($value < 10000) {
            return number_format($value, $numDecimals);
        }
        $iskIndex = 0;
        while ($value > 999.99) {
            $value /= 1000;
            ++$iskIndex;
        }

        return number_format($value, $numDecimals).self::$formatIskIndexes[$iskIndex];
    }

    public static function convertUriToParameters()
    {
        global $isApiRequest;
        $parameters = array();
        $entityRequiredSatisfied = false;
        $entityType = null;

        $uri = $_SERVER['REQUEST_URI'];
        $split = explode('/', $uri);
        $splitSize = sizeof($split);

        // Remove the first and last keys since they are always empty
        array_shift($split);
        if (sizeof($split) > 1) unset($split[count($split) - 1]);

        $multi = false;
        $paginated = false;
        $startEndTiminated = false;
        $legalLargePagination = false;

        while (sizeof($split)) {
            $key = array_shift($split);
            switch ($key) {
                case '':
                    die("Please remove the double slash // from the call");
                    break;
                case 'top':
                case 'topalltime':
                case 'stats':
                case 'ranks':
                case 'trophies':
                case 'wars':
                case 'supers':
                case 'corpstats':
                    // These parameters can be safely ignored
                    break;
                case 'reset':
                case 'api':
                case 'kills':
                case 'losses':
                case 'w-space':
                case 'lowsec':
                case 'nullsec':
                case 'highsec':
                case 'solo':
                case 'pretty':
                case 'zkbOnly':
                case 'abyssal':
                case 'ganked':
                    $parameters[$key] = true;
                    break;
                case 'character':
                case 'characterID':
                case 'corporation':
                case 'corporationID':
                case 'alliance':
                case 'allianceID':
                case 'faction':
                case 'factionID':
                case 'ship':
                case 'shipID':
                case 'shipTypeID':
                case 'group':
                case 'groupID':
                case 'system':
                case 'solarSystemID':
                case 'systemID':
                case 'constellation':
                case 'constellationID':
                case 'region':
                case 'regionID':
                case 'location':
                case 'locationID':
                case 'warID':
                    $value = array_shift($split);
                    $intValue = (int) $value;
                    if ($value != null) {
                        if (strpos($key, 'ID') === false) {
                            global $isApiRequest;
                            if ($isApiRequest) die("$key is invalid for API calls, please use ${key}ID");
                            $key = $key.'ID';
                        }
                        $legalLargePagination = ($key == 'characterID' || $key == 'corporationID' || $key == 'allianceID');
                        if ($key == 'systemID') {
                            $key = 'solarSystemID';
                        } elseif ($key == 'shipID') {
                            $key = 'shipTypeID';
                        }
                        $exploded = explode(',', $value);
                        if (sizeof($exploded) > 1) {
                            die("Due to exccessive abuse, multiple values separated by commas are no longer supported");
                        }
                        $multi = sizeof($exploded) > 1;
                        $ints = [];
                        foreach ($exploded as $ex) {
                            if ("$ex" != (string) (int) $ex) die("$ex is not an integer");
                            if (is_numeric($ex)) $ints[] = (int) $ex;
                            else $ints[] = (string) $ex;
                        }
                        if (sizeof($ints) > 1) {
                            asort($ints);
                            if (implode(",", $ints) != $value) {
                                die("multiple IDs must be in sequential order (sorry, but some people were abusing the ordering to avoid the cache)");
                            }
                        }

                        if (sizeof($ints) == 0) {
                            die("Client requesting too few parameters.");
                        }
                        $parameters[$key] = $ints;
                        $entityRequiredSatisfied = true;
                        $entityType = $value;
                    }
                    break;
                case 'npc':
                case 'awox':
                    $value = array_shift($split);
                    if ($value != '0' && $value != '1') {
                        die("Only values of 0 or 1 allowed with the $key filter");
                    }
                    $parameters[$key] = $value;
                    break;
                case 'finalblow-only':
                    self::checkEntityRequirement($entityRequiredSatisfied, "Please provide an entity filter first.");
                    $parameters[$key] = true;
                    break;
                case 'page':
                    self::checkEntityRequirement($entityRequiredSatisfied, "Please provide an entity filter first.");
                    //if ($startEndTiminated == true) throw new Exception("Cannot mix page and (startTime or endTime)");
                    $value = array_shift($split);
                    $value = (int) $value;
                    if ($value < 1) {
                        die("page value <= 1 not allowed");
                    }
                    $parameters[$key] = (int) $value;
                    $paginated = true;
                    break;
                case 'orderDirection':
                    die("orderDirection is no longer supported - sort it yourself :)");
                case 'pastSeconds':
                    self::checkEntityRequirement($entityRequiredSatisfied, "Please provide an entity filter first.");
                    $value = array_shift($split);
                    $value = (int) $value;
                    if (($value / 86400) > 7) {
                        die('pastSeconds is limited to a max of 7 days');
                    }
                    $parameters[$key] = (int) $value;
                    break;
                case 'startTime':
                case 'endTime':
                    if ($isApiRequest) die("startTime/endTime no longer supported in api because of abuse");
                    self::checkEntityRequirement($entityRequiredSatisfied, "Please provide an entity filter first.");
                    $value = array_shift($split);
                    $time = strtotime($value);
                    if (strpos($uri, "region") !== false) {
                        die("Cannot use startTime/endTime with this entity, use the /api/history/ or RedisQ intead");
                    }
                    if ($time < 0) {
                        die("$value is not a valid time format");
                    }
                    if (($time % 3600) != 0) {
                        die("startTime and endTime must end with 00");
                    }
                    $parameters[$key] = $value;
                    $startEndTiminated = true;
                    break;
                case 'limit':
                    die("Due to abuse of the limit parameter to avoid caches the ability to modify limit has been revoked for all users");
                case 'no-attackers':
                case 'no-items':
                case 'asc':
                case 'desc':
                case 'json':
                    die("$key has been permanently disabled.");
                    break;
                case 'beforeKillID':
                case 'afterKillID':
                    die("$key has been permanently disabled - please use page, RedisQ, the websocket, or the history endpoint instead.");
                    break;
                case 'killID':
                    $value = array_shift($split);
                    if (!is_numeric($value)) {
                        die("$value is not a valid entry for $key");
                    }
                    $parameters[$key] = (int) $value;
                    break;
                case 'iskValue':
                    $value = (int) array_shift($split);
                    if ($value == 0 || $value % 500000000 != 0) {
                        die("$value is not a valid multiple of 5b ISK");
                    }
                    $parameters[$key] = (int) $value;
                    break;
                case 'nolimit':
                    // This can and should be ignored since its a parameter that will remove limits for battle eeports
                    break;
                case 'year':
                    self::checkEntityRequirement($entityRequiredSatisfied, "Please provide an entity filter first.");
                    $value = array_shift($split);
                    $value = (int) $value;
                    if ($value < 2007) die("$value is not a valid entry for $key");
                    if ($value > date('Y')) die("$value is not a valid entry for $key");
                    $parameters[$key] = $value;
                    break;
                case 'month':
                    self::checkEntityRequirement($entityRequiredSatisfied, "Please provide an entity filter first.");
                    $value = array_shift($split);
                    $value = (int) $value;
                    if ($value < 1 || $value > 12) die("$value is not a valid entry for $key");
                    $parameters[$key] = $value;
                    break;
                case 'xml':
                    die("xml formatting has been deprecated and is no longer supported");
                default:
                    if (substr($uri, 0, 5) == "/api/") {
                        die("$key is an invalid parameter");
                    }
                    header("Location: ..");
                    exit();
            }
        }

        if ($multi && $paginated) die("Combining multiple IDs with pagination is no longer supported");
        if ($paginated && !$legalLargePagination && $parameters['page'] > 10) {
            throw new Exception("Pages over 10 only supported for characters, corporations, and alliances");
        }

        return $parameters;
    }

    private static function checkEntityRequirement($entityRequiredSatisfied, $message)
    {
        if ($entityRequiredSatisfied == false) {
            throw new Exception($message);
        }
    }

    public static function pageTimer()
    {
        global $pageLoadMS;

        return (microtime(true) - $pageLoadMS) * 1000;
    }

    public static function isActive($pageType, $currentPage, $retValue = 'active')
    {
        return strtolower($pageType) == strtolower($currentPage) ? $retValue : '';
    }

    private static $months = array('', 'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC');

    public static function getMonth($month)
    {
        return self::$months[$month];
    }

    private static $longMonths = array('', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August',
            'September', 'October', 'November', 'December', );

    public static function getLongMonth($month)
    {
        return self::$longMonths[(int) $month];
    }

    public static function isValidCallback($subject)
    {
        $identifier_syntax = '/^[$_\p{L}][$_\p{L}\p{Mn}\p{Mc}\p{Nd}\p{Pc}\x{200C}\x{200D}]*+$/u';

        $reserved_words = array('break', 'do', 'instanceof', 'typeof', 'case',
                'else', 'new', 'var', 'catch', 'finally', 'return', 'void', 'continue',
                'for', 'switch', 'while', 'debugger', 'function', 'this', 'with',
                'default', 'if', 'throw', 'delete', 'in', 'try', 'class', 'enum',
                'extends', 'super', 'const', 'export', 'import', 'implements', 'let',
                'private', 'public', 'yield', 'interface', 'package', 'protected',
                'static', 'null', 'true', 'false', );

        return preg_match($identifier_syntax, $subject) && !in_array(mb_strtolower($subject, 'UTF-8'), $reserved_words);
    }

    /**
     * @param string $haystack
     */
    public static function strposa($haystack, $needles = array(), $offset = 0)
    {
        $chr = array();
        foreach ($needles as $needle) {
            $res = strpos($haystack, $needle, $offset);
            if ($res !== false) {
                $chr[$needle] = $res;
            }
        }
        if (empty($chr)) {
            return false;
        }

        return min($chr);
    }

    /**
     * @param string $url
     *
     * @return string|null $result
     */
    public static function getData($url, $cacheTime = 3600)
    {
        global $ipsAvailable, $baseAddr;

        $md5 = md5($url);
        $result = $cacheTime > 0 ? RedisCache::get($md5) : null;

        if (!$result) {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                        CURLOPT_USERAGENT => "zKillboard dataGetter for site: {$baseAddr}",
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_POST => false,
                        CURLOPT_FORBID_REUSE => false,
                        CURLOPT_ENCODING => '',
                        CURLOPT_URL => $url,
                        CURLOPT_HTTPHEADER => array('Connection: keep-alive', 'Keep-Alive: timeout=10, max=1000'),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FAILONERROR => true,
                        )
                    );

            if (count($ipsAvailable) > 0) {
                $ip = $ipsAvailable[time() % count($ipsAvailable)];
                curl_setopt($curl, CURLOPT_INTERFACE, $ip);
            }
            $result = curl_exec($curl);
            if ($cacheTime > 0) {
                RedisCache::set($md5, $result, $cacheTime);
            }
        }

        return $result;
    }

    /**
     * @param string $url
     * @param array
     * @param array
     *
     * @return array $result
     */
    public static function postData($url, $postData = array(), $headers = array())
    {
        global $ipsAvailable, $baseAddr;
        $userAgent = "zKillboard dataGetter for site: {$baseAddr}";
        if (!isset($headers)) {
            $headers = array('Connection: keep-alive', 'Keep-Alive: timeout=10, max=1000');
        }

        $curl = curl_init();
        $postLine = '';

        if (!empty($postData)) {
            foreach ($postData as $key => $value) {
                $postLine .= $key.'='.$value.'&';
            }
        }

        rtrim($postLine, '&');

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        if (!empty($postData)) {
            curl_setopt($curl, CURLOPT_POST, count($postData));
            curl_setopt($curl, CURLOPT_POSTFIELDS, $postLine);
        }

        if (count($ipsAvailable) > 0) {
            $ip = $ipsAvailable[time() % count($ipsAvailable)];
            curl_setopt($curl, CURLOPT_INTERFACE, $ip);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

        $result = curl_exec($curl);

        curl_close($curl);

        return $result;
    }

    /**
     * Gets post data, and returns it.
     *
     * @param string $var The variable you can to return
     *
     * @return string|null
     */
    public static function getPost($var)
    {
        return isset($_POST[$var]) ? $_POST[$var] : null;
    }

    public static function out($text)
    {
        echo date('Y-m-d H:i:s')." > $text\n";
    }

    public static function exitNow()
    {
        return date('s') == 59;
    }

    public static function availableStyles()
    {
        return ['cyborg'];
        //return ['cerulean', 'cyborg', 'journal', 'readable', 'simplex', 'slate', 'spacelab', 'united'];
    }

    public static function rankCheck($rank)
    {
        return $rank === false || $rank === null ? '-' : (1 + (int) $rank);
    }

    public static function getQueryCount()
    {
        global $mdb;

        return $mdb->getQueryCount();
    }

    public static function get3dDistance($position, $locationID, $solarSystemID = 0)
    {
        global $redis, $mdb;

        $x = $position['x'];
        $y = $position['y'];
        $z = $position['z'];

        $row = $mdb->findDoc("locations", ['id' => $solarSystemID]);
        if ($row == null || !isset($row['locations'])) return 0;
        foreach ($row['locations'] as $location) {
            if ($location['itemid'] != $locationID) continue;
            return sqrt(pow($location['x'] - $x, 2) + pow($location['y'] - $y, 2) + pow($location['z'] - $z, 2));
        }

        return 0;
    }

    public static function getAuDistance($position, $locationID, $solarSystemID = 0)
    {
        $distance = self::get3dDistance($position, $locationID, $solarSystemID);

        $au = round($distance / (149597870700), 2);

        return $au;
    }

    public static function getSequence($mdb, $redis)
    {
        $sem = sem_get(3175);
        try {
            sem_acquire($sem);
            do {
                self::populate($mdb, $redis);
                $i = (int) $redis->lpop("sequence");
            } while ($i > 0 && $mdb->exists("killmails", ['sequence' => $i]));
            if ($i <= 0) throw new \Exception("Invalid sequence number received: $i");
            return $i;
        } finally {
            sem_release($sem);
        }
    }

    private static function populate($mdb, $redis) {
        if ($redis->llen("sequence") == 0) {
		Util::out("populating sequences");
            $sequenceStart = 1+ $mdb->findField("killmails", "sequence", [], ['sequence' => -1]);
            $max = $sequenceStart + 10000;
            for ($i = $sequenceStart; $i <= $max; $i++) {
                $redis->rpush("sequence", $i);
            }
        }
    }
    public static function eliminateBetween($base, $delim1, $delim2) {
        $split1 = explode($delim1, $base);
        $split2 = explode($delim2, $base);

        if (sizeof($split1) == 2 && sizeof($split2) == 2) {
            return $split1[0] . $delim2 . $split2[1];
        } else {
            return $base;
        }
    }

    public static function sendEveMail($characterID, $subject, $message)
    {
        global $mdb;

        $mdb->insert("evemails", ['sent' => false, 'subject' =>  $subject, 'body' => $message, 'recipients' => [['recipient_id' => $characterID, 'recipient_type' => 'character']]]);
    }

    public static function getLoad()
    {
        $output = array();
        $result = exec('cat /proc/loadavg', $output);

        $split = explode(' ', $result);
        $load = $split[0];

        return $load;
    }
}

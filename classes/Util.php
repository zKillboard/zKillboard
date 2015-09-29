<?php

class Util
{
    public static function getMaintenanceReason()
    {
        return Storage::retrieve('MaintenanceReason', '');
    }

    public static function getNotification()
    {
        return Storage::retrieve('notification', null);
    }

    public static function is904Error()
    {
        $stop904 = Db::queryField("select count(*) count from zz_storage where locker = 'ApiStop904' and contents > now()", 'count', array(), 1);

        return $stop904 > 0;
    }

    public static function getCrest($url)
    {
        \Perry\Setup::$fetcherOptions = ['connect_timeout' => 15, 'timeout' => 30];

        return \Perry\Perry::fromUrl($url);
    }

    /**
     * @param int    $keyID
     * @param string $vCode
     */
    public static function getPheal($keyID = null, $vCode = null, $overRide = false)
    {
        if ($overRide == false) {
            return;
        }
        global $apiServer, $baseAddr, $ipsAvailable;

        if (!$overRide && static::is904Error()) {
            if (php_sapi_name() == 'cli') {
                exit();
            }

            return; // Web requests shouldn't be hitting the API...
        }

        \Pheal\Core\Config::getInstance()->http_method = 'curl';
        \Pheal\Core\Config::getInstance()->http_user_agent = "API Fetcher for http://$baseAddr";
        if (!empty($ipsAvailable)) {
            $max = count($ipsAvailable) - 1;
            $ipID = mt_rand(0, $max);
            \Pheal\Core\Config::getInstance()->http_interface_ip = $ipsAvailable[$ipID];
        }
        \Pheal\Core\Config::getInstance()->http_post = false;
        \Pheal\Core\Config::getInstance()->http_keepalive = true; // default 15 seconds
        \Pheal\Core\Config::getInstance()->http_keepalive = 10; // KeepAliveTimeout in seconds
        \Pheal\Core\Config::getInstance()->http_timeout = 30;
        \Pheal\Core\Config::getInstance()->api_customkeys = true;
        \Pheal\Core\Config::getInstance()->api_base = $apiServer;

        if ($keyID !== null && $vCode !== null) {
            $pheal = new \Pheal\Pheal($keyID, $vCode);
        } else {
            $pheal = new \Pheal\Pheal();
        }

        return $pheal;
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

        return (substr($haystack, 0, $length) === $needle);
    }

    public static function endsWith($haystack, $needle)
    {
        return substr($haystack, -strlen($needle)) === $needle;
    }

    private static $formatIskIndexes = array('', 'k', 'm', 'b', 't', 'tt', 'ttt');

    public static function formatIsk($value)
    {
        $numDecimals = (((int) $value) == $value) && $value < 10000 ? 0 : 2;
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

    public static function convertUriToParameters($additionalParameters = array(), $addExtraParameters = true)
    {
        $parameters = array();
        @$uri = $_SERVER['REQUEST_URI'];
        $split = explode('/', $uri);
        $currentIndex = 0;
        foreach ($split as $key) {
            $value = $currentIndex + 1 < count($split) ? $split[$currentIndex + 1] : null;
            switch ($key) {
                case 'kills':
                case 'losses':
                case 'w-space':
                case 'lowsec':
                case 'nullsec':
                case 'highsec':
                case 'solo':
                case 'pretty':
                case 'xml':
                case 'zkbOnly':
                case 'awox':
                case 'no-attackers':
                case 'no-items':
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
                case 'region':
                case 'regionID':
                    if ($value != null) {
                        if (strpos($key, 'ID') === false) {
                            $key = $key.'ID';
                        }
                        if ($key == 'systemID') {
                            $key = 'solarSystemID';
                        } elseif ($key == 'shipID') {
                            $key = 'shipTypeID';
                        }
                        $exploded = explode(',', $value);
                        foreach ($exploded as $aValue) {
                            if ($aValue != (int) $aValue || ((int) $aValue) == 0) {
                                die();
                            } //throw new Exception("Invalid ID passed: $aValue");
                        }
                        if (sizeof($exploded) > 10) {
                            throw new Exception('Too many IDs! Max: 10');
                        }
                        $ints = [];
                        foreach ($exploded as $ex) {
                            $ints[] = (int) $ex;
                        }
                        $parameters[$key] = $ints;
                    }
                break;
                case 'page':
                    $value = (int) $value;
                    if ($value < 1) {
                        $value = 1;
                    }
                    $parameters[$key] = (int) $value;
                break;
                case 'orderDirection':
                    if (!($value == 'asc' || $value == 'desc')) {
                        throw new Exception('Invalid orderDirection!  Allowed: asc, desc');
                    }
                    $parameters[$key] = 'desc';
                    $parameters[$key] = $value;
                break;
                case 'pastSeconds':
                    $value = (int) $value;
                    if (($value / 86400) > 7) {
                        throw new Exception('pastSeconds is limited to a max of 7 days');
                    }
                    $parameters[$key] = (int) $value;
                break;
                case 'startTime':
                case 'endTime':
                    $time = strtotime($value);
                    if ($time < 0) {
                        throw new Exception("$value is not a valid time format");
                    }
                    $parameters[$key] = $value;
                break;
                case 'limit':
                    $value = (int) $value;
                    if ($value < 200) {
                        $parameters['limit'] = $value;
                    } elseif ($value > 200) {
                        $parameters['limit'] = 200;
                    } elseif ($value <= 0) {
                        $parameters['limit'] = 1;
                    }
                break;
                case 'beforeKillID':
                case 'afterKillID':
                case 'killID':
                    if (!is_numeric($value)) {
                        throw new Exception("$value is not a valid entry for $key");
                    }
                    $parameters[$key] = (int) $value;
                break;
                case 'iskValue':
                    if (!is_numeric($value)) {
                        throw new Exception("$value is not a valid entry for $key");
                    }
                    $parameters[$key] = (int) $value;
                break;
                default:
                    if ($addExtraParameters == true) {
                        if (is_numeric($value) && $value < 0) {
                            continue;
                        } //throw new Exception("$value is not a valid entry for $key");
                        if ($key != '' && $value != '') {
                            $parameters[$key] = $value;
                        }
                    }

                    // Add more parameters to the $parameters array
                    if (!empty($additionalParameters)) {
                        foreach ($additionalParameters as $extra) {
                            if ($extra == $key) {
                                $parameters[$key] = $value;
                            }
                        }
                    }
                break;
            }
            ++$currentIndex;
        }

        return $parameters;
    }

    public static function pageTimer()
    {
        global $timer;

        return $timer->stop();
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
        return ['amelia', 'cerulean', 'cyborg', 'default', 'journal', 'readable', 'simplex', 'slate', 'spacelab', 'united'];
    }
}

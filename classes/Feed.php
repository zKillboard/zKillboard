<?php

class Feed
{
    /**
     * Returns kills in json format according to the specified parameters.
     *
     * @static
     *
     * @param array $parameters
     *
     * @return array
     */
    public static function getKills($parameters = array())
    {
        global $debug;

        if (isset($parameters['limit']) && $parameters['limit'] > 200) {
            $parameters['limit'] = 200;
        }
        if (isset($parameters['page'])) {
            $parameters['limit'] = 200;
        }
        if (!isset($parameters['limit'])) {
            $parameters['limit'] = 200;
        }

        $kills = Kills::getKills($parameters, true, false);

        return self::getJSON($kills, $parameters);
    }

    /**
     * Groups the kills together based on specified parameters.
     *
     * @static
     *
     * @param array|null $kills
     * @param array      $parameters
     *
     * @return array
     */
    public static function getJSON($kills, $parameters)
    {
        if ($kills == null) {
            return array();
        }
        $retValue = array();

        foreach ($kills as $kill) {
            $killID = $kill['killID'];
            $json = Crest2Api::convert($killID);
            if (array_key_exists('no-items', $parameters)) {
                unset($json['items']);
            }
            if (isset($json['_stringValue'])) {
                unset($json['_stringValue']);
            }
            if (array_key_exists('finalblow-only', $parameters)) {
                $involved = count($json['attackers']);
                $json['zkb']['involved'] = $involved;
                if (!isset($json['attackers'])) {
                    continue;
                }
                $data = $json['attackers'];
                unset($json['attackers']);
                foreach ($data as $attacker) {
                    if ($attacker['finalBlow'] == '1') {
                        $json['attackers'][] = $attacker;
                    }
                }
            } elseif (array_key_exists('no-attackers', $parameters)) {
                $involved = count($json['attackers']);
                $json['zkb']['involved'] = $involved;
                unset($json['attackers']);
            }

            $retValue[] = json_encode($json);
        }

        return $retValue;
    }
}

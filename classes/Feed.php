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
        global $mdb;

        if ($kills == null) {
            return array();
        }
        $retValue = array();

        foreach ($kills as $kill) {
            $killID = $kill['killID'];
            $json = $mdb->findDoc("esimails", ['killmail_id' => $killID]);
            $kill = $mdb->findDoc("killmails", ['killID' => $killID]);
            $json['zkb'] = $kill['zkb'];
            unset($json['_id']);
            if (array_key_exists('no-items', $parameters)) {
                unset($json['victim']['items']);
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
                    if ($attacker['final_blow'] == '1') {
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

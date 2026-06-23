<?php

function handler($request, $response, $args, $container) {
    global $mdb, $baseDir;
    
    $battleID = (int) $args['battleID'];
    $cacheTag = "www,battle,battle:$battleID";

    $battle = $mdb->findDoc('battles', ['battleID' => $battleID]);
    if ($battle) {
        $battle['battleID'] = (int) $battle['battleID'];
    } else if (!$mdb->exists('battles', ['battleID' => $battleID])) {
        // Create new battle record if it doesn't exist
        $battle = ['battleID' => $battleID];
        $mdb->save('battles', $battle);
    }

    $system = @$battle['solarSystemID'];
    $time = @$battle['dttm'];
    $options = @$battle['options'];
    $showBattleOptions = false;

    // Create args for the related handler
    $relatedArgs = [
        'system' => $system,
        'time' => $time,
        'options' => $options
    ];
    
    // Handle query parameters like the related handler does
    $queryParams = $request->getQueryParams();
    if (!empty($queryParams)) {
        // Build the redirect URL with options parameter
        $json_options = [];
        if ($options) {
            $json_options = json_decode(urldecode($options), true) ?: [];
        }
        
        $redirect = false;
        if (isset($queryParams['left'])) {
            $entity = $queryParams['left'];
            if (!isset($json_options['A'])) {
                $json_options['A'] = array();
            }
            if (isset($json_options['B']) && ($key = array_search($entity, $json_options['B'])) !== false) {
                unset($json_options['B'][$key]);
            }
            if (!in_array($entity, $json_options['A'])) {
                $json_options['A'][] = $entity;
            }
            $redirect = true;
        }
        if (isset($queryParams['right'])) {
            $entity = $queryParams['right'];
            if (!isset($json_options['B'])) {
                $json_options['B'] = array();
            }
            if (isset($json_options['A']) && ($key = array_search($entity, $json_options['A'])) !== false) {
                unset($json_options['A'][$key]);
            }
            if (!in_array($entity, $json_options['B'])) {
                $json_options['B'][] = $entity;
            }
            $redirect = true;
        }
        
        if ($redirect) {
            $json = urlencode(json_encode($json_options));
            $url = "/br/$battleID/o/$json/";
            return $response->withHeader('Location', $url)->withStatus(302);
        }
    }
    
    global $battleID;
    
    try {
        $mc = RelatedReport::generateReport($system, $time, $options, $battleID, null);
        if (is_array($mc) && !empty($mc)) {
            return $container->get('view')->render($response->withHeader('Cache-Tag', $cacheTag), 'related.pug', $mc);
        } else {
            // Empty array means report is being generated
            return $container->get('view')->render($response->withStatus(202)->withHeader('Cache-Tag', $cacheTag), 'related_wait.pug', ['showAds' => false]);
        }
    } catch (\InvalidArgumentException $ex) {
        // Invalid time format - redirect to rounded time
        $roundedTime = substr($time, 0, strlen("$time") - 2) . "00";
        return $response->withHeader('Location', "/br/$battleID/")->withStatus(302);
    } catch (\RuntimeException $ex) {
        // System reinforced or queue busy
        $systemID = (int) $system;
        $unixTime = strtotime($time);
        if ($ex->getMessage() === "System is reinforced") {
            return $container->get('view')->render($response->withStatus(202)->withHeader('Cache-Tag', $cacheTag), 'related_reinforced.pug', ['showAds' => false]);
        } else if (str_contains($ex->getMessage(), "Queue is too busy")) {
            return $container->get('view')->render($response->withStatus(202)->withHeader('Cache-Tag', $cacheTag), 'related_notnow.pug', ['showAds' => false, 'solarSystemID' => $systemID, 'unixtime' => $unixTime]);
        } else {
            return $container->get('view')->render($response->withStatus(202)->withHeader('Cache-Tag', $cacheTag), 'related_wait.pug', ['showAds' => false]);
        }
    } catch (Exception $ex) {
        return $container->get('view')->render($response->withStatus(202)->withHeader('Cache-Tag', $cacheTag), 'related_wait.pug', ['showAds' => false]);
    }
}

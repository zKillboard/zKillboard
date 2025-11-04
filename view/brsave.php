<?php

function handler($request, $response, $args, $container) {
    global $mdb;
    
    // Get query parameters from the request
    $queryParams = $request->getQueryParams();
    $sID = $queryParams['sID'] ?? null;
    $dttm = $queryParams['dttm'] ?? null;
    $options = $queryParams['options'] ?? null;

    $battleID = $mdb->findField('battles', 'battleID', ['$and' => [['solarSystemID' => $sID], ['dttm' => $dttm], ['options' => $options]]], ['battleID' => -1]);
    while ($battleID === null) {
        $battleID = $mdb->findField('battles', 'battleID', [], ['battleID' => -1]);
        ++$battleID;
        try {
            $mdb->insert('battles', ['battleID' => (int) $battleID]);
        } catch (Exception $ex) {
            $battleID = null;
            sleep(1);
        }
    }
    $battle = $mdb->findDoc('battles', ['battleID' => $battleID]);
    $battle['solarSystemID'] = $sID;
    $battle['dttm'] = $dttm;
    $battle['options'] = $options;
    $mdb->save('battles', $battle);

    return $response->withHeader('Location', "/br/$battleID/")->withStatus(302);
}

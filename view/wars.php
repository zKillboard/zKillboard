<?php

function handler($request, $response, $args, $container) {
    global $mdb;

    $timeStarted = date('Y-m-dTH:m:s', time() - (86400 * 90));

    $wars = array();
    $wars[] = ['name' => 'Recent Declared Wars - Open to Allies', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID', 'open_for_allies' => true], ['timeStarted' => -1], 50)];
    $wars[] = ['name' => 'Recent Declared Wars - Mutual', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID', 'mutual' => true], ['timeStarted' => -1], 50)];
    $wars[] = ['name' => 'Recently Declared Wars', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID'], ['started' => -1], 25)];
    $wars[] = ['name' => 'Recently Finished Wars', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID'], ['finished' => -1], 25)];
    Info::addInfo($wars);

    return $container->get('view')->render($response, 'wars.html', array('warTables' => $wars));
}

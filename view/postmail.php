<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis;

    $error = '';
    $method = $request->getMethod();

    if ($method === 'POST') {
        $parsedBody = $request->getParsedBody();
        $killmailurl = $parsedBody['killmailurl'] ?? '';

        if ($killmailurl) {
            $timer = new Timer();
            // Looks like http://public-crest.eveonline.com/killmails/30290604/787fb3714062f1700560d4a83ce32c67640b1797/
            $exploded = explode('/', $killmailurl);

            if (count($exploded) == 8) array_shift($exploded);
            if (count($exploded) < 5 || count($exploded) > 7) {
                $error = 'Invalid killmail link.';
            } else {
                if ((int) $exploded[4] <= 0) {
                    $error = 'Invalid killmail link';
                } elseif (strlen($exploded[5]) != 40) {
                    $error = 'Invalid killmail link';
                } else {
                    $killID = (int) $exploded[4];
                    $exists = $mdb->exists('killmails', ['killID' => $killID]);
                    if ($exists) {
                        return $response->withHeader('Location', "/kill/$killID/")->withStatus(302);
                    }
                    $hash = (string) $exploded[5];
                    $exists = $mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash]);
                    if (!$exists) {
                        $in = ['killID' => $killID, 'hash' => $hash, 'processed' => false, 'source' => 'postmail.php', 'delay' => 0];
                        $mdb->save('crestmails', $in);
                        $newCrest = true;
                    } else {
                        // update the delay to 0, manual posts always take priority
                        $mdb->set('crestmails', ['killID' => $killID, 'hash' => $hash], ['delay' => 0]);
                    }

                    $timer = new Timer();
                    do {
                        $error = '';
                        // Has the kill been processed?
                        $kill = $mdb->findDoc('killmails', ['killID' => $killID]);
                        if ($kill != null) {
                            $loops = 0;
                            while (@$kill['processed'] != true && $loops < 100) {
                                $loops++;
                                usleep(100000);
                                $kill = $mdb->findDoc('killmails', ['killID' => $killID]);
                            } 
                            return $response->withHeader('Location', "/kill/$killID/")->withStatus(302);
                        }
                        $crest = $mdb->findDoc('crestmails', ['killID' => $killID, 'hash' => $hash]);
                        if ($crest === null) {
                            $error = "Our processing queue deleted your submission. Was it even a valid killmail?";
                        } else if (@$crest['errorCode'] !== null) {
                            $error = "CCP's ESI server threw an errorCode ".$crest['errorCode'].' for your killmail. We cannot retrieve the information to post your killmail at this time until CCP fixes this error.';
                        } elseif ($crest['processed'] === null) {
                            Util::zout("$killID $hash failing, will keep trying");
                            $mdb->set('crestmails', ['killID' => $killID, 'hash' => $hash], ['processed' => false]);
                            $error = '';                        
                        } elseif ($redis->get("zkb:universeLoaded") == "false") {
                            $error = "The universe is currently being updated. Your killmail will be processed later.";
                        }

                        if ($error == '') {
                            sleep(1);
                        }
                    } while ($timer->stop() < 20000 && $error == '');
                    if ($error == '') {
                        $error = 'We waited 20 seconds for the kill to be processed but the server must be busy atm, please wait!';
                    }
                }
            }
        }
    }

    if (!is_array($error)) {
        $error = array($error);
    }

    return $container->get('view')->render($response, 'postmail.html', array('message' => $error));
}

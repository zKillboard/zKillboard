<?php

for ($i = 0; $i < 30; ++$i) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        exit();
    }
    if ($pid == 0) {
        break;
    }
}
if ($pid != 0) {
    exit();
}

require_once '../init.php';

$timer = new Timer();
$tqApiChars = new RedisTimeQueue('tqApiChars', 3600);

$numApis = $tqApiChars->size();
if ($i >= ($numApis / 100) + 1) exit();

$noApiCount = 0;
while ($timer->stop() <= 59000) {
    $row = $tqApiChars->next();
    if ($row !== null) {
        $charID = $row['characterID'];
        $keyID = $row['keyID'];
        $vCode = $row['vCode'];
        $type = $row['type'];
        $userID = $row['userID'];
        if ($userID != 0) {
	    $multi = $redis->multi();
	    $multi->hSet("userID:api:$userID", $charID, true);
	    $multi->expire("userID:api:$userID", 86400);
            $multi->setex("userID:api:$userID:$charID", 86400, serialize(['charID' => $charID, 'keyID' => $keyID, 'time' => time(), 'type' => $type]));
	    $multi->exec();
        }
        $charCorp = $type == 'Corporation' ? 'corp' : 'char';
        $killsAdded = 0;

        \Pheal\Core\Config::getInstance()->http_method = 'curl';
        \Pheal\Core\Config::getInstance()->http_user_agent = "API Fetcher for https://$baseAddr";
        \Pheal\Core\Config::getInstance()->http_post = false;
        \Pheal\Core\Config::getInstance()->http_keepalive = 30; // KeepAliveTimeout in seconds
        \Pheal\Core\Config::getInstance()->http_timeout = 60;
        \Pheal\Core\Config::getInstance()->api_customkeys = true;
        \Pheal\Core\Config::getInstance()->api_base = 'https://api.eveonline.com/';
        $pheal = new \Pheal\Pheal($keyID, $vCode);

        $charCorp = ($type == 'Corporation' ? 'corp' : 'char');
        $pheal->scope = $charCorp;
        $result = null;

        $params = array();
        $params['characterID'] = $charID;
        $result = null;

        try {
            $result = $pheal->KillMails($params);
        } catch (Exception $ex) {
            $errorCode = $ex->getCode();
            if ($errorCode == 904) {
                Util::out("(apiConsumer) 904'ed...");
                exit();
            }
            if ($errorCode == 28) {
                Util::out('(apiConsumer) API Server timeout');
                exit();
            }
            $tqApiChars->remove($row);
            sleep(3);
            continue;
        }

        $nextCheck = $result->cached_until_unixtime;
        $tqApiChars->setTime($row, $nextCheck);

        $newMaxKillID = 0;
        foreach ($result->kills as $kill) {
            $killID = (int) $kill->killID;

            $newMaxKillID = (int) max($newMaxKillID, $killID);

            $json = json_encode($kill->toArray());
            $killmail = json_decode($json, true);
            $killmail['killID'] = (int) $killID;

            $victim = $killmail['victim'];
            $victimID = $victim['characterID'] == 0 ? 'None' : $victim['characterID'];

            $attackers = $killmail['attackers'];
            $attacker = null;
            if ($attackers != null) {
                foreach ($attackers as $att) {
                    if ($att['finalBlow'] != 0) {
                        $attacker = $att;
                    }
                }
            }
            if ($attacker == null) {
                $attacker = $attackers[0];
            }
            $attackerID = $attacker['characterID'] == 0 ? 'None' : $attacker['characterID'];

            $shipTypeID = $victim['shipTypeID'];

            $dttm = (strtotime($killmail['killTime']) * 10000000) + 116444736000000000;

            $string = "$victimID$attackerID$shipTypeID$dttm";

            $hash = sha1($string);

            $killInsert = ['killID' => (int) $killID, 'hash' => $hash];
            $exists = $mdb->exists('crestmails', $killInsert);
            if (!$exists) {
                try {
                    $mdb->getCollection('crestmails')->save(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'api', 'added' => $mdb->now()]);
                    ++$killsAdded;
                } catch (MongoDuplicateKeyException $ex) {
                    // ignore it *sigh*
                }
            }
            if (!$exists && $debug) {
                Util::out("Added $killID from API");
            }
        }

        // helpful info for output if needed
        $info = $mdb->findDoc('information', ['type' => 'characterID', 'id' => $charID], [], ['name' => 1, 'corporationID' => 1]);
        $corpInfo = $mdb->findDoc('information', ['type' => 'corporationID', 'id' => @$info['corporationID']], [], ['name' => 1]);

        $apiVerifiedSet = new RedisTtlSortedSet('ttlss:apiVerified', 86400);
        $apiVerifiedSet->add(time(), ($type == 'Corporation' ? @$info['corporationID'] : $charID));
        if ($newMaxKillID == 0) {
            $tqApiChars->setTime($row, time() + rand(72000, 86400));
        }

        // If we got new kills tell the log about it
        if ($killsAdded > 0) {
            if ($type == 'Corporation') {
                $name = 'corp '.@$corpInfo['name'];
            } else {
                $name = 'char '.@$info['name'];
            }
            while (strlen("$killsAdded") < 3) {
                $killsAdded = ' '.$killsAdded;
            }
            Util::out("$killsAdded kills added by $name");
        }
    } else {
	$noApiCount++;
	if ($noApiCount >= 5) exit();
    }

    sleep(1);
}

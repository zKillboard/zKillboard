<?php

global $mdb;

$error = '';

if ($_POST) {
	$keyid = Util::getPost('keyid');
	$vcode = Util::getPost('vcode');
	$killmailurl = Util::getPost('killmailurl');

	// Apikey stuff
	if ($keyid || $vcode) {
		$ip = IP::get();
		Log::log("$ip $keyid $vcode");
		$error = Api::addKey($keyid, $vcode);
	}

	if ($killmailurl) {
		$timer = new Timer();
		// Looks like http://public-crest.eveonline.com/killmails/30290604/787fb3714062f1700560d4a83ce32c67640b1797/
		$exploded = explode('/', $killmailurl);

		if (count($exploded) != 7) {
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
					$app->redirect("/kill/$killID/");
					exit();
				}
				$hash = (string) $exploded[5];
				$exists = $mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash]);
				if (!$exists) {
					$mdb->getCollection('crestmails')->save(['killID' => $killID, 'hash' => $hash, 'processed' => false, 'source' => 'user', 'added' => $mdb->now()]);
					$newCrest = true;
				}

				$timer = new Timer();
				do {
					$error = '';
					// Has the kill been processed?
					$exists = $mdb->exists('killmails', ['killID' => $killID]);
					if ($exists) {
						$ip = IP::get();
						Log::log("New CRESTmail $killID");
						$app->redirect("/kill/$killID/");
						exit();
					}
					$crest = $mdb->findDoc('crestmails', ['killID' => $killID, 'hash' => $hash]);
					if (@$crest['errorCode'] !== null) {
						$error = "CCP's CREST server threw an errorCode ".$crest['errorCode'].' for your killmail. We cannot retrieve the information to post your killmail at this time until CCP fixes this error.';
					} elseif ($crest['processed'] === null) {
						Log::log("$killID $hash failing, will keep trying");
						$mdb->set('crestmails', ['killID' => $killID, 'hash' => $hash], ['processed' => false]);
						$error = '';
						//$error = "There is an error with the killmail at the CREST endpoint (aka it is CCP's fault). We'll let CCP know. Your kill has still been submitted and we'll process it as soon as the error has been fixed. Thank you.";
					}
					if (@$crest['npcOnly']) {
						$error = 'This is an NPC kill and therefore has not been processed';
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

$app->render('postmail.html', array('message' => $error));

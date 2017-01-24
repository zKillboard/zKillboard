<?php

require_once "../init.php";

$mail = $mdb->findDoc("evemails", ['sent' => false]);

if ($mail == null) exit();

$refreshToken = $mdb->findField("scopes", "refreshToken", ['characterID' => $evemailCharID, 'scope' => 'esi-mail.send_mail.v1']);
$accessToken = CrestSSO::getAccessToken($evemailCharID, null, $refreshToken);

$name = Info::getInfoField('characterID', (int) $mail['recipients'][0]['recipient_id'], 'name');

Util::out("Sending evemail to $name");

$mail['approved_cost'] = 10000;
$url = "$esiServer/v1/characters/$evemailCharID/mail/";
$response = ESI::curl($url, $mail, $accessToken, 'POST_JSON');
$json = json_decode($response, true);

$mail['sent'] = isset($json['error']) ? 'error' : true;
$mail['error'] = isset($json['error']) ? $response : null;

$mdb->save("evemails", $mail);

<?php

require_once "../init.php";

/*$em = $mdb->find('evemails');
foreach ($em as $mail) {
    if (isset($mail['error'])) {
        $error = json_decode($mail['error'], true);
        $code = $error['httpCode'];
        if ($code == 0 || $code == 502) {
            $mdb->set("evemails", $mail, ['sent' => false]);
        }
    }
}*/

$minute = date('Hi');
while ($minute == date('Hi')) {
    $mail = $mdb->findDoc("evemails", ['sent' => false], ['_id' => -1]);

    if ($mail != null) {
        $refreshToken = $mdb->findField("scopes", "refreshToken", ['characterID' => $evemailCharID, 'scope' => 'esi-mail.send_mail.v1']);
        $accessToken = CrestSSO::getAccessToken($evemailCharID, null, $refreshToken);

        if ($accessToken == null) {
            Util::out("evemails to send, cannot obtain accessToken");
            return;
        }

        $name = Info::getInfoField('characterID', (int) $mail['recipients'][0]['recipient_id'], 'name');

        if ($redis->get("zkb:evemail:". $mail['recipients'][0]['recipient_id']) == "sent") {
            $mail['sent'] = 'spam-prevention';
            $mail['error'] = null;
            $mdb->save("evemails", $mail);
            continue;
        }
        Util::out("Sending evemail to $name");

        $mail['approved_cost'] = 10000;
        $url = "$esiServer/v1/characters/$evemailCharID/mail/";
        $response = ESI::curl($url, $mail, $accessToken, 'POST_JSON');
        $json = json_decode($response, true);

        $mail['sent'] = isset($json['error']) ? 'error' : true;
        $mail['error'] = isset($json['error']) ? $response : null;

        $mdb->save("evemails", $mail);

        if (isset($json['error'])) {
            Util::out("Failed sending evemail to $name, http code: " . @$json['httpCode']);
        } else {
            $redis->setex("zkb:evemail:". $mail['recipients'][0]['recipient_id'], 7200, "sent");
        }
        sleep(12);
    }
    sleep(1);
}

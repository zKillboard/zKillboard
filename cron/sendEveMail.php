<?php

require_once "../init.php";

//$mdb->set("evemails", ['error' => ['$ne' => null]], ['sent' => false], ['multi' => true]);

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

        Util::out("Sending evemail to $name");

        $mail['approved_cost'] = 10000;
        $url = "$esiServer/v1/characters/$evemailCharID/mail/";
        $response = ESI::curl($url, $mail, $accessToken, 'POST_JSON');
        $json = json_decode($response, true);

        $mail['sent'] = isset($json['error']) ? 'error' : true;
        $mail['error'] = isset($json['error']) ? $response : null;

        $mdb->save("evemails", $mail);

        if (isset($json['error'])) {
            Util::out("Error sending evemail: " . print_r($json, true));
            return;
        }
    }
    sleep(13);
}

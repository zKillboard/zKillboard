<?php

require_once "../init.php";

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

        $charID = (int) $mail['recipients'][0]['recipient_id'];
        $name = Info::getInfoField('characterID', $charID, 'name');

        if ($redis->get("zkb:evemail:$charID" ) == "sent") {
            $mail['sent'] = 'spam-prevention';
            $mail['error'] = null;
            $mdb->save("evemails", $mail);
            continue;
        }
    
        ZLog::add("Sending evemail to $name", $charID); 

        $mail['approved_cost'] = 10000;
        $url = "$esiServer/v1/characters/$evemailCharID/mail/";
        $response = ESI::curl($url, $mail, $accessToken, 'POST_JSON');
        $json = json_decode($response, true);

        $mail['sent'] = isset($json['error']) ? 'error' : true;
        $mail['error'] = isset($json['error']) ? $response : null;

        $mdb->save("evemails", $mail);

        if (isset($json['error'])) {
            ZLog::add("Failed sending evemail to $name, http code: " . @$json['httpCode'], $charID);
        } else {
            $redis->setex("zkb:evemail:$charID", 7200, "sent");
        }
        sleep(12);
    }
    sleep(1);
}

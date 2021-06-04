<?php

class Discord {
    public static function webhook($hook, $url) {
        $payload = [
            'content' => $url
        ];

        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $hook);
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
    }
}

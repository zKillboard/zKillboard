<?php

class EveMail
{
    public static function send($characterID, $subject, $message)
    {
        global $mdb;

        $mdb->insert("evemails", ['sent' => false, 'subject' =>  $subject, 'body' => $message, 'recipients' => [['recipient_id' => $characterID, 'recipient_type' => 'character']]]);
    }
}

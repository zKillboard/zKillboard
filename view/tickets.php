<?php

global $mdb;
global $baseAddr;

$message = array();

if ($_POST) {
    $email = Util::getPost('email');
    $subject = Util::getPost('subject');
    $ticket = Util::getPost('ticket');

    $info = User::getUserInfo();
    $charID = User::getUserId();
    $name = $info['username'];

    if ($charID > 0 && isset($ticket)) {
        $insert = ['subject' => $subject, 'content' => $ticket, 'dttm' => time(), 'parentID' => null, 'email' => $email, 'characterID' => $charID, 'status' => 1];
        $mdb->insert("tickets", $insert);

        $id = $insert['_id']; 

        $app->redirect("/tickets/view/$id/");
        exit();
    } else {
        $message = array('type' => 'error', 'message' => 'Ticket was not posted, there was an error');
    }
}

$info = User::getUserInfo();
if (@$info['moderator'] == true) {
    $open_tickets = $mdb->find("tickets", ['parentID' => null, 'status' => 1], ['dttm' => -1]);
    $closed_tickets = $mdb->find("tickets", ['parentID' => null, 'status' => ['$ne' => 1]], ['dttm' => -1]);
    $tickets = array_merge($open_tickets, $closed_tickets);
} else {
    $tickets = $mdb->find("tickets", ['$and' => [['characterID' => User::getUserID()], ['parentID' => null]]], ['dttm' => -1]);
}
Info::addInfo($tickets);

$userInfo = User::getUserInfo();
$app->render('tickets.html', array('userInfo' => $userInfo, 'tickets' => $tickets, 'message' => $message));

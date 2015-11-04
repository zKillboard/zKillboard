<?php

$message = array();

if ($_POST) {
    $tags = Util::getPost('hidden-tags');
    $ticket = Util::getPost('ticket');

    $info = User::getUserInfo();
    $name = $info['username'];
    //$email = $info['email'];

    if (isset($name) && isset($tags) && isset($ticket)) {
        $check = Db::query('SELECT * FROM zz_tickets WHERE ticket = :ticket', array(':ticket' => $ticket), 0);
        if (!$check) {
            Db::execute('INSERT INTO zz_tickets (userid, name, tags, ticket) VALUES (:userid, :name, :tags, :ticket)', array(':userid' => User::getUserID(), ':name' => $name, ':tags' => $tags, ':ticket' => $ticket));
            $id = Db::queryField('SELECT id FROM zz_tickets WHERE userid = :userid AND name = :name AND tags = :tags AND ticket = :ticket', 'id', array(':userid' => User::getUserID(), ':name' => $name, ':tags' => $tags, ':ticket' => $ticket));
            global $baseAddr;
            Log::irc("|g|New ticket from $name:|n| https://$baseAddr/moderator/tickets/$id/");
            $subject = 'zKillboard Ticket';
            $message = "$name, you can find your ticket here, we will reply to your ticket asap. https://$baseAddr/tickets/view/$id/";
            //Email::send($email, $subject, $message);
            $app->redirect("/tickets/view/$id/");
        } else {
            $message = array('type' => 'error', 'message' => 'Ticket already posted');
        }
    } else {
        $message = array('type' => 'error', 'message' => 'Ticket was not posted, there was an error');
    }
}

$tickets = Db::query('SELECT * FROM zz_tickets WHERE userid = :userid ORDER BY datePosted DESC', array(':userid' => User::getUserID()), 0);
foreach ($tickets as $key => $val) {
    if ($val['tags']) {
        $tickets[$key]['tags'] = explode(',', $val['tags']);
    }
}

$userInfo = User::getUserInfo();
$app->render('tickets.html', array('userInfo' => $userInfo, 'tickets' => $tickets, 'message' => $message));

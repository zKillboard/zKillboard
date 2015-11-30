<?php

$message = array();
$info = User::getUserInfo();
$ticket = Db::queryRow('SELECT * FROM zz_tickets WHERE id = :id', array(':id' => $id), 0);
if ($ticket == null or sizeof($ticket) == 0) {
    $message = array('status' => 'error', 'message' => 'Ticket does not exist.');
} elseif ($ticket['status'] == 0) {
    $message = array('status' => 'error', 'message' => 'Ticket has been closed, you cannot post, only view it');
} elseif ($ticket['userid'] != User::getUserID() && @$info['moderator'] == 0 && @$info['admin'] == 0) {
    $app->notFound();
}

if ($_POST) {
    $reply = Util::getPost('reply');

    if ($reply && $ticket['status'] != 0) {
        $name = $info['username'];
        $moderator = @$info['moderator'] == true;
        $check = Db::query('SELECT * FROM zz_tickets_replies WHERE reply = :reply AND userid = :userid AND belongsTo = :id', array(':reply' => $reply, ':userid' => User::getUserID(), ':id' => User::getUserID()), 0);
        if (!$check) {
            Db::execute('INSERT INTO zz_tickets_replies (userid, belongsTo, name, reply, moderator) VALUES (:userid, :belongsTo, :name, :reply, :moderator)', array(':userid' => User::getUserID(), ':belongsTo' => $id, ':name' => $name, ':reply' => $reply, ':moderator' => $moderator));
            global $baseAddr;
            if (!$moderator) {
                Log::irc("|g|Ticket response from $name|n|: https://$baseAddr/moderator/tickets/$id/");
            }
            $app->redirect("/tickets/view/$id/");
            exit();
        }
    } else {
        $message = array('status' => 'error', 'message' => 'No...');
    }
}

$replies = Db::query('SELECT * FROM zz_tickets_replies WHERE belongsTo = :id', array(':id' => $id), 0);

$app->render('tickets_view.html', array('page' => $id, 'message' => $message, 'ticket' => $ticket, 'replies' => $replies));

<?php

global $mdb, $baseAddr;

$message = array();
$info = User::getUserInfo();
$ticket = $mdb->findDoc("tickets", ['_id' => new MongoId($id), 'parentID' => null]);
if ($ticket == null or sizeof($ticket) == 0) {
	$message = array('status' => 'error', 'message' => 'Ticket does not exist.');
} elseif ($ticket['status'] == 0) {
	$message = array('status' => 'error', 'message' => 'Ticket has been closed, you cannot post, only view it');
} elseif ($ticket['characterID'] != User::getUserID() && @$info['moderator'] == 0 && @$info['admin'] == 0) {
	$app->notFound();
}

if ($_POST) {
	$reply = Util::getPost('reply');

	if ($reply && $ticket['status'] != 0) {
		$charID = User::getUserId();
		$name = $info['username'];
		$moderator = @$info['moderator'] == true;
		$mdb->insert("tickets", ['parentID' => $id, 'content' => $reply, 'characterID' => $charID, 'dttm' => time(), 'moderator' => $moderator]);
		if (!$moderator) {
			Log::irc("|g|Ticket response from $name|n|: https://$baseAddr/moderator/tickets/$id/");
		}
		if ($moderator && isset($ticket['email']) && strlen($ticket['email']) > 0) {
			Email::send($ticket['email'], "zKillboard Ticket Response", "You have received a response to a ticket you submitted. To view the response, please click $baseAddr/tickets/view/$id/");
		}
		$app->redirect("/tickets/view/$id/");
		exit();
	} else {
		$message = array('status' => 'error', 'message' => 'No...');
	}
}

$replies = $mdb->find("tickets", ['parentID' => $id], ['dttm' => 1]);

Info::addInfo($ticket);
Info::addInfo($replies);
array_unshift($replies, $ticket);

$app->render('tickets_view.html', array('page' => $id, 'message' => $message, 'ticket' => $ticket, 'replies' => $replies));

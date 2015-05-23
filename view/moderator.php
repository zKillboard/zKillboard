<?php

$message = "";
if (!User::isLoggedIn()) {
    $app->render("login.html");
    die();
}
$info = User::getUserInfo();
if (!User::isModerator()) $app->redirect("/");

if($_POST)
{
	$status = Util::getPost("status");
	$reply = Util::getPost("reply");
	$report = Util::getPost("report");
	$delete = Util::getPost("delete");
	$deleteapi = Util::getPost("deleteapi");
	$manualpull = Util::getPost("manualpull");

	if(isset($status))
	{
		Db::execute("UPDATE zz_tickets SET status = :status WHERE id = :id", array(":status" => $status, ":id" => $id));
		if ($status == 0) $app->redirect("..");
	}
	if(isset($reply))
	{
		$name = $info["username"];
		$moderator = $info["moderator"];
		$check = Db::query("SELECT * FROM zz_tickets_replies WHERE reply = :reply AND userid = :userid", array(":reply" => $reply, ":userid" => $info["id"]), 0);
		if(!$check)
		{
			Db::execute("INSERT INTO zz_tickets_replies (userid, belongsTo, name, reply, moderator) VALUES (:userid, :belongsTo, :name, :reply, :moderator)", array(":userid" => $info["id"], ":belongsTo" => $id, ":name" => $name, ":reply" => $reply, ":moderator" => $moderator));
			$tic = Db::query("SELECT name,email FROM zz_tickets WHERE id = :id", array(":id" => $id));
			$ticname = $tic[0]["name"];
			$ticmail = $tic[0]["email"];
			$subject = "zKillboard Ticket";
			global $baseAddr;
			$message = "$ticname, there is a new reply to your ticket from $name - https://$baseAddr/tickets/view/$id/";
			if ($moderator == 0) Log::irc("User replied to ticket: |g|$name|n|  https://$baseAddr/moderator/tickets/$id/");
			if ($moderator != 0) Email::send($ticmail, $subject, $message);
			if(isset($report))
				$app->redirect("/moderator/reportedkills/$id/");
			$app->redirect("/moderator/tickets/$id/");
		}
	}
	if(isset($delete))
	{
		if($delete < 0)
		{
			Util::deleteKill($delete);
			Db::execute("DELETE FROM zz_tickets WHERE id = :id", array(":id" => $id));
			Db::execute("DELETE FROM zz_tickets_replies WHERE belongsTo = :belongsTo", array(":belongsTo" => $id));
			$app->redirect("/moderator/reportedkills/");
		}
		$message = "Error, kill is positive, and thus api verified.. something is wrong!";
	}

	if(isset($manualpull) )
	{
		$message = "ah";
	}

	if(isset($deleteapi)){
		Api::deleteKey($deleteapi);
		$message = "The Api had been deleted";
	}

}

if ($req == "") {
	$app->redirect("tickets/");
	die();
}

if($req == "tickets" && $id)
{
	$info["ticket"] = Db::query("SELECT * FROM zz_tickets WHERE id = :id", array(":id" => $id), 0);
	$info["replies"] = Db::query("SELECT * FROM zz_tickets_replies WHERE belongsTo = :id", array(":id" => $id), 0);
}
elseif($req == "tickets")
{
	$limit = 30;
	$offset = ($page - 1) * $limit;
	$info = Db::query("SELECT t.*, count(r.belongsTo) replyCount FROM zz_tickets t left join zz_tickets_replies r on (t.id = r.belongsTo)  WHERE killID = 0 GROUP BY 1 ORDER BY status DESC, count(r.belongsTo) != 0, datePosted DESC LIMIT $offset, $limit", array(), 0);
	foreach($info as $key => $val)
	{
		//if($val["tags"]) $info[$key]["tags"] = explode(",", $val["tags"]);
	}
}
elseif($req == "users")
{
	$info = Moderator::getUsers($page);
}
if($req == "reportedkills" && $id)
{
	$info["ticket"] = Db::query("SELECT * FROM zz_tickets WHERE id = :id", array(":id" => $id), 0);
	$info["replies"] = Db::query("SELECT * FROM zz_tickets_replies WHERE belongsTo = :id", array(":id" => $id), 0);
}
elseif($req == "reportedkills")
{
	$limit = 30;
	$offset = ($page - 1) * $limit;
	$info = Db::query("SELECT * FROM zz_tickets WHERE killID != 0 ORDER BY status DESC LIMIT $offset, $limit", array(), 0);
	foreach($info as $key => $val)
	{
		//if($val["tags"]) $info[$key]["tags"] = explode(",", $val["tags"]);
	}
}

$app->render("moderator/moderator.html", array("id" => $id, "info" => $info, "key" => $req, "url"=>"moderator", "message" => $message, "page" => $page));

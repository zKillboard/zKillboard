<?php
global $cookie_secret;
$randomString = sha1(time());
// Check if user is already merged, just to be safe
$exists = Db::queryField("SELECT merged FROM zz_users WHERE characterID = :characterID", "merged", array(":characterID" => $characterID), 0);
if($exists == 1)
{
	$error = "Error: User already merged.";
	$app->render("merge.html", array("error" => $error, "characterID" => $characterID, "randomString" => $randomString));
}

// Otherwise show the page..
if($_POST)
{
	$username = Util::getPost("username");
	$password = Util::getPost("password");

	if(!$username)
	{
		$error = "No username given";
		$app->render("merge.html", array("error" => $error, "characterID" => $characterID, "randomString" => $randomString));
	}
	elseif(!$password)
	{
		$error = "No password given";
		$app->render("merge.html", array("error" => $error, "characterID" => $characterID, "randomString" => $randomString));
	}
	elseif($username && $password)
	{
		$check = User::checkLogin($username, $password);
		if($check) // Success
		{
			// Get userID for user that passes
			$userID = Db::queryField("SELECT id FROM zz_users WHERE username = :username", "id", array(":username" => $username));

			// Update userID in zz_crest_users
			Db::execute("UPDATE zz_users_crest SET userID = :userID WHERE characterID = :characterID", array(":userID" => $userID, ":characterID" => $characterID));
			// Update the characterID on zz_users and set merged to 1
			Db::execute("UPDATE zz_users SET merged = 1 WHERE id = :userID", array(":userID" => $userID));
			Db::execute("UPDATE zz_users SET characterID = :characterID WHERE id = :userID", array(":userID" => $userID, ":characterID" => $characterID));

			// Set the login session headers and whatnot
			$crestData = Db::queryRow("SELECT * FROM zz_users_crest WHERE characterID = :characterID", array(":characterID" => $characterID));
			$_SESSION["loggedin"] = $crestData["characterName"];

			// Redirect to /
			$app->redirect("/");
		}
		else
		{
			// The login failed, or the user didn't exist.. Either way, we don't give a fuck..
			// Randomly generate a password
			$password = md5(time() + $cookie_secret);
			// Insert no email address, null@null.com
			$email = "null@null.com";

			// Data from zz_user_crest
			$crestData = Db::queryRow("SELECT * FROM zz_users_crest WHERE characterID = :characterID", array(":characterID" => $characterID));
			// Insert the new user to zz_users
			Db::execute("INSERT INTO zz_users (username, password, email, characterID, merged) VALUES (:username, :password, :email, :characterID, :merged)", array(
				":username" => $crestData["characterName"],
				":password" => $password,
				":email" => $email,
				":characterID" => $crestData["characterID"],
				":merged" => 1)
			);

			// Set the userID in zz_users_crest
			$userID = Db::queryField("SELECT id FROM zz_users WHERE username = :username", "id", array(":username" => $crestData["characterName"]));
			Db::execute("UPDATE zz_users_crest SET userID = :userID WHERE characterID = :characterID", array(":userID" => $userID, ":characterID" => $characterID));
			// Set the session headers and whatnot
			$_SESSION["loggedin"] = $crestData["characterName"];

			// Redirect to /
			$app->redirect("/");
		}
	}
}
elseif($exists == 0 || $exists == NULL)
	$app->render("merge.html", array("characterID" => $characterID, "randomString" => $randomString));
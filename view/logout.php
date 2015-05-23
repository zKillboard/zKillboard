<?php

global $cookie_name;
$requesturi = "";
if(isset($_SERVER["HTTP_REFERER"])) $requesturi = $_SERVER["HTTP_REFERER"];
$sessionCookie = $app->getEncryptedCookie($cookie_name, false);
// remove the entry from the database
Db::execute("DELETE FROM zz_users_sessions WHERE sessionHash = :hash", array(":hash" => $sessionCookie));
unset($_SESSION["loggedin"]);
$app->view(new \Slim\Views\Twig());
$twig = $app->view()->getEnvironment();
$twig->addGlobal("sessionusername", "");
$twig->addGlobal("sessionuserid", "");
$twig->addGlobal("sessionadmin", "");
$twig->addGlobal("sessionmoderator", "");
setcookie($cookie_name, "", time()-$cookie_time, "/", $baseAddr);
setcookie($cookie_name, "", time()-$cookie_time, "/", ".".$baseAddr);
if (isset($requesturi) && $requesturi != "") $app->redirect($requesturi);
else $app->render("logout.html", array("message" => "You are now logged out"));

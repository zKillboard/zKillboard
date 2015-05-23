<?php

$code = $e->getCode();
$message = $e->getMessage();
$file = $e->getFile();
$line = $e->getLine();
$trace = $e->getTraceAsString();
$codeHash = md5($trace);

$html = "";
if ($code) {
	$html .= sprintf('<div><strong>Code:</strong> %s</div>', $code);
}
if ($message) {
	$html .= sprintf('<div><strong>Message:</strong> %s</div>', $message);
}
if ($file) {
	$html .= sprintf('<div><strong>File:</strong> %s</div>', $file);
}
if ($line) {
	$html .= sprintf('<div><strong>Line:</strong> %s</div>', $line);
}
if ($trace) {
	$html .= '<h4>Trace</h4>';
	$html .= sprintf('<pre>%s</pre>', $trace);
}

$date = date("Y-m-d H:i:s");
$url = $_SERVER["REQUEST_URI"];
$ip = IP::get();

try {
	Db::execute("INSERT INTO zz_errors (id, error, message, url, ip) VALUES (:id, :error, :message, :url, :ip) ON DUPLICATE KEY UPDATE ip = :ip, date = :date", array(":id" => $codeHash, ":error" => $html, ":message" => $message, ":url" => $url, ":ip" => $ip, ":date" => $date));
	$app->render("error.html", array("code" => $codeHash, "message" => $message, "error" => $html));
} catch (Exception $ex) {
    $html = "<html>";
    $html .= "<head>";
    $html .= "<title>Oh noes an error!</title>";
    $html .= "</head>";
    $html .= "<body>";
    $html .= $e->getMessage();
    $html .= "</body>";
    $html .= "</html>";
    echo $html;
}

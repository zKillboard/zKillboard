<?php
/* zKillboard
 * Copyright (C) 2012-2013 EVE-KILL Team and EVSCO.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
if(php_sapi_name() != "cli")
    die("This is a cli script!");

$base = dirname(__FILE__);

if(php_sapi_name() != "cli")
die("This is a cli script!");

if(!extension_loaded('pcntl'))
die("This script needs the pcntl extension!");

// Update composer and any vendor products
out("\nUpdating composer...");
chdir("$base/..");
passthru("php composer.phar self-update");
out("\nUpdating vendor files...");
passthru("php composer.phar update --optimize-autoloader");

require_once( "config.php" );
chdir("$base");

// vendor autoload
require( "$base/../vendor/autoload.php" );

// zkb class autoloader
spl_autoload_register("zkbautoload");

function zkbautoload($class_name)
{
	global $base;
	$fileName = "$base/../classes/$class_name.php";
	if (file_exists($fileName))
	{
		require_once $fileName;
		return;
	}
}

Db::execute("SET SESSION wait_timeout = 120000000");
out("\n|g|Starting maintenance mode...|n|");
Db::execute("replace into zz_storage values ('maintenance', 'true')");
out("|b|Waiting 60 seconds for all executing scripts to stop...|n|");
sleep(60);

// Get a list of all tables
$tableResult = Db::query("show tables", array(), 0, false);
$tables = array();
foreach($tableResult as $row)
{
	$table = array_pop($row);
	$tables[$table] = true;
}

// Now install the db structure
try {
	$sqlFiles = scandir("$base/sql");
	foreach($sqlFiles as $file)
	{
		if (Util::endsWith($file, ".sql"))
		{
			$table = str_replace(".sql", "", $file);
			out("Updating table |g|$table|n| ... ", false, false);
			$sqlFile = "$base/sql/$file";
			loadFile($sqlFile, $table);
			out("|w|done|n|");
			$tables[$table] = false;
		}
	}
	foreach ($tables as $table=>$drop)
	{
		if ($drop && Util::startsWith($table, "zz_"))
		{
			out("|r|Dropping table: |g|$table|n|\n", false, false);
			Db::execute("drop table $table");
		}
	}
}
catch (Exception $ex)
{
	out("|r|Error!|n|");
	throw $ex;
}

$count = Db::execute("INSERT IGNORE INTO zz_users (username, moderator, admin, password) VALUES ('admin', 1, 1, '$2y$10\$maxuZ/qozcjIgr7ZSnrWJemywbThbPiJDYIuOk9eLxF0pGE5SkNNu')");
if ($count > 0)
	out("\n\n|r|*** NOTICE ***\nDefault admin user has been added with password 'admin'\nIt is strongly recommended you change this password!\n*** NOTICE ***\n");

out("|g|Unsetting maintenance mode|n|");
Db::execute("delete from zz_storage where locker = 'maintenance'");
out("All done, enjoy your update!");

function loadFile($file, $table)
{
	if (Util::endsWith($file, ".gz"))
		$handle = gzopen($file, "r");
	else
		$handle = fopen($file, "r");

	//Check to see if we are adding new tables
	if(Db::queryRow("SHOW TABLES LIKE'$table'", array(), 0, false)!= null)
	{
		if (Util::startsWith($table, "ccp_"))
			Db::execute("drop table $table");
		else
			Db::execute("alter table $table rename old_$table");
	}


	$query = "";
	while ($buffer = fgets($handle)) {
		$query .= $buffer;
		if (strpos($query, ";") !== false) {
			$query = str_replace(";", "", $query);
			Db::execute($query);
			$query = "";
		}
	}
	fclose($handle);

	if (Db::queryRow("SHOW TABLES LIKE 'old_$table'", array(), 0, false)!= null){ // Check again to see if the old_table is there
		if (!Util::startsWith($table, "ccp_")) {
			try {
				Db::execute("insert ignore into $table select * from old_$table");
				Db::execute("drop table old_$table");
			} catch (Exception $ex) {
				Db::execute("drop table $table");
				Db::execute("alter table old_$table rename $table");
				throw $ex;
			}
		}
	}
}

function out($message, $die = false, $newline = true)
{
	$colors = array(
			"|w|" => "1;37", //White
			"|b|" => "0;34", //Blue
			"|g|" => "0;32", //Green
			"|r|" => "0;31", //Red
			"|n|" => "0" //Neutral
		       );

	$message = "$message|n|";
	foreach($colors as $color => $value)
		$message = str_replace($color, "\033[".$value."m", $message);

	if($newline)
		echo $message.PHP_EOL;
	else
		echo $message;
}

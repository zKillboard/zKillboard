<?php

require_once "../init.php";

if (Util::isMaintenanceMode()) return;

// Cleanup old sessions
Db::execute("delete from zz_users_sessions where validTill < now()");

// Keep the account balance table clean
Db::execute("delete from zz_account_balance where balance = 0");

Db::execute("delete from zz_scrape_prevention where dttm < date_sub(now(), interval 1 hour)");

// Cleanup subdomain stuff
Db::execute("update zz_subdomains set adfreeUntil = null where adfreeUntil < now()");
Db::execute("update zz_subdomains set banner = null where banner = ''");
Db::execute("delete from zz_subdomains where adfreeUntil is null and banner is null and (alias is null or alias = '')");

// Expire change expirations
Db::execute("update zz_users set change_expiration = null, change_hash = null where change_expiration < date_sub(now(), interval 3 day)");

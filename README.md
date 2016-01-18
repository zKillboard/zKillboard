# WARNING

zKillboard code base is being restructured for easy installs, there are no instructions yet, as the restructure isn't finished. This also means that any instructions you see below probably don't work. You've been warned!

## zKillboard
zKillboard is a killboard created for EVE-Online, for use on zkillboard.com, but can also be used for single entities.

#### License and Copyright
Licensing for all files in this repository can be found in in AGPL.md

## WARNING WARNING
This is the latest verison of zKillboard.com, which prefers NoSQL over MySQL. If you do not have experience with installing and maintaining a NoSQL database, specifically TokuMX v2.4, we do not recommend you try this at home unless you truly enjoy a challenge! There are some aspects of zKillboard.com that still utilize MySQL, but that is only because they haven't been integrated into NoSQL yet.

### History and previous versions
zKillboard.com came as the brainchild of Squizz Caphinator who wanted to improve upon [http://wiki.eve-id.net/EDK](Eve-Dev Killboard). Squizz decided to write a new killboard completely from scratch and began the zKillboard project. Karbowiak of eve-kill.net eventually joined into the project, contributed much code,, [created a repository on Github](https://github.com/EVE-KILL/zKillboard), and announced zkillboard as the new Beta killboard for eve-kill.net. zKillboard matured and gained a fanbase, and of course, haters. As time went on Squizz and Karbowiak had some differences and Squizz forked his code into [this repository](https://github.com/3zLabs/zKillboard) and made this new repository the primary code base for zkillboard.com. After about a year Squizz then began dabbling in NoSQL, as it seemed the perfect database for the type of data consumed by zKillboard. Two months of heavy coding and extreme database changes, the repository [zKillboard/zKillboard](https://github.com/zKillboard/zKillboard) was created to make the code public to the masses with the various NoSQL changes.

Fun fact: zKillboard.com was originally called killwhore.com until it was discovered that the Eve Online forums censored the word whore.

## WARNING
This is BETA, which means it is a work in progress.  It lacks complete documentation and is currently not meant for use in production.

Since zKillboard is a beta product, it has a code base that is far from complete and enjoys numerous updates, deletions, and modifications to the code and accompanying tables. Please feel free to attempt to install zKillboard on your own server, however, we are not responsible for any difficulties you come across during installation and continuing execution of the product.

## Credits
zKillboard is released under the GNU Affero General Public License, version 3. The full license is available in the `AGPL.md` file.
zKillboard also uses data and images from EVE-Online, which is covered by a seperate license from _[CCP](http://www.ccpgames.com/en/home)_. You can see the full license in the `CCP.md` file.
It also uses various 3rd party libraries, which all carry their own licensing. Please refer to them for more info.

## Contact
`#zkb` on `irc.coldfront.net`
Mibbit link incase you're lazy: _http://chat.mibbit.com/?channel=%23zkb&server=irc.coldfront.net_

## Minimum requirements
- PHP 5.4+ / HHVM 3.0+
- Apache + mod_rewrite, Nginx or any other httpd you prefer that supports php via mod_php or fastcgi.
- Linux, Mac OS X or Windows
- MariaDB 5.5+
- TokuMX 2.4+
- Composer
- cURL and php5-curl

## Recommended requirements
- PHP 5.5+ / HHVM 3.0+
- Linux
- MariaDB 5.5+
- TokuMX 2.4+
- Composer
- APC / Redis / Memcached (Doesn't matter which one)
- cURL and php5-curl

## Nginx Config
```
upstream php-upstream {
  server unix:/tmp/php-fpm.sock;
  server 127.0.0.1:9000;
}

server {
  server_name example.com www.example.com;
  listen      80;
  root        /path/to/zkb_install;

  location    / {
    try_files $uri $uri/ /index.php$is_args$args;
  }

  location    ~ \.php$ {
    try_files $uri = 404;
    include   fastcgi_params;
    fastcgi_index index.php;
    fastcgi_pass php-upstream;
  }
}

```

## Apache rewrite
Apache rewrite is handled by the .htaccess.

## Apache Config
```
<VirtualHost *:80>
        ServerAlias yourdomain.tld

        DocumentRoot /path/to/zkb_install/
        <Directory /path/to/zkb_install/>
          Require all granted
          Options FollowSymLinks MultiViews
          AllowOverride All
          Order allow,deny
          Allow from all
        </Directory>
</VirtualHost>
```

### Cronjobs
zKillboard comes with a script that automates the cron execution.
It keeps track of when each job has been run and how frequently it needs to be executed.
Just run it every minute via cron or a similar system:

```
* * * * * /var/killboard/zkillboard.com/cron/cron.sh
```

The cron.sh file handles the output as well as rotating of the logfiles in /cron/logs/

# Admin account

Every clean zKillboard installation comes with an admin account, default username and password is `admin`, it is highly recommended that you immediately change this password after you finish your installation.

Current special features to the admin account:

1) Any entities (pilots, corporations, etc.) added to the Admin's tracker will automatically be fetched from _https://zkillboard.com_ up to and including a full fetch of all kills, and maintaining a fetch of said kills on an hourly basis. This of course depends on the cronjob being setup.

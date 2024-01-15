## zKillboard
zKillboard is a killboard created for EVE-Online, for use on zkillboard.com, but can also be used for single entities.


Fun fact: zKillboard.com was originally called killwhore.com until it was discovered that the EVE Online forums censored the word whore.

## Installation
This is a set of code that is beta and is constantly in flux. Which means it is a work in progress.  It lacks complete documentation and is currently not meant for use by those who do not have a lot of experience in setting up PHP, TokuDB (a derivative of MongoDB), and Redis. Please feel free to attempt to install zKillboard on your own server, however, we are not responsible for any difficulties you come across during installation and continuing execution.

## Contact
Via Twitter at @zkillboard, via the ticket system itself on zkillboard.com (you have to log in), send an email to zkillboard@gmail.com, or you can talk to Squizz on TweetFleet.

## Minimum requirements
- To be updated.

### Cronjobs
zKillboard comes with a script that automates the cron execution.
It keeps track of when each job has been run and how frequently it needs to be executed.
Just run it every minute via cron or a similar system:

```
* * * * * /var/killboard/zkillboard.com/cron/cron.sh
```

The cron.sh file handles the output as well as rotating of the logfiles in /cron/logs/

## Credits
zKillboard is released under the GNU Affero General Public License, version 3. The full license is available in the `AGPL.md` file.
zKillboard also uses data and images from EVE-Online, which is covered by a separate license from _[CCP](https://www.ccpgames.com)_. You can see the full license in the `CCP.md` file.
It also uses various 3rd party libraries, which all carry their own licensing. Please refer to them for more info.

#### License and Copyright
Licensing for all files in this repository can be found in AGPL.md

### History and previous versions
zKillboard.com came as the brainchild of Squizz Caphinator who wanted to improve upon [Eve-Dev Killboard](http://wiki.eve-id.net/EDK). Squizz decided to write a new killboard completely from scratch and began the zKillboard project. Karbowiak of eve-kill.net eventually joined into the project, contributed much code, [created a repository on Github](https://github.com/EVE-KILL/zKillboard), and announced zKillboard as the new Beta killboard for eve-kill.net. zKillboard matured and gained a fanbase, and of course, haters. As time went on Squizz and Karbowiak had some differences and Squizz forked his code into this repository and made this new repository the primary code base for zkillboard.com. After about a year Squizz then began dabbling in NoSQL, as it seemed the perfect database for the type of data consumed by zKillboard. Two months of heavy coding and extreme database changes, the repository [zKillboard/zKillboard](https://github.com/zKillboard/zKillboard) was updated to make the code public to the masses with the various NoSQL changes.

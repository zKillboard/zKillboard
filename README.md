## zKillboard
zkillboard.com is a killboard for the Massively Multiplayer Online Role Playing Game (MMORPG) EVE-Online.

Fun fact: zKillboard.com was originally called killwhore.com until it was discovered that the EVE Online forums censored the word whore.

## Setup

See [Docker.md](Docker.md) for the application and service setup.

The EVEShip.fit engine, bindings, license notices, and verified `data.json.gz`
snapshot are included in the repository and Docker images. Each web/static server
uses its deployed copy; no cron job or shared storage is required for Fit Stats.

Updates are manual. From the repository root with Node.js 24 installed:

```bash
node setup/updateFitData.mjs
```

The updater checks the latest public release, downloads changed data, and runs the
fitting tests before atomically replacing the snapshot. Pass a release argument to
select a specific upstream release. Review and commit the changed
`public/vendor/eveshipfit/data.json.gz`, then deploy it to the web/static servers
(or rebuild the web image). Failed downloads or tests retain the current snapshot.
The snapshot records its data release.
See [Docker.md](Docker.md#eveshipfit-fit-stats) for setup and source details.

## Contact
If you're seeking assistance, please join the [zKillboard Discord](https://discord.gg/sV2kkwg8UD) and ask in the `#zkillboard-com` channel.

## Credits
zKillboard is released under the GNU Affero General Public License, version 3. The full license is available in the `AGPL.md` file.

zKillboard also uses data and images from EVE Online, which are covered by a separate license from [CCP](https://www.ccpgames.com). You can view the full license in the `CCP.md` file.

It also relies on various third-party libraries, each with their own licensing terms. Please refer to their documentation for more information.

## License and Copyright
Licensing for all files in this repository can be found in `AGPL.md`.

## History
zKillboard began as the brainchild of Squizz Caphinator, who wanted to improve upon the [Eve-Dev Killboard](https://github.com/evekb/evedev-kb). Rather than continue modifying existing code, Squizz chose to start fresh and wrote a new killboard from scratch.

Karbowiak of eve-kill.net joined the project, contributed a significant amount of code, [created a repository on GitHub](https://github.com/EVE-KILL/zKillboard), and announced zKillboard as the new beta killboard for eve-kill.net.

zKillboard matured, gained a loyal user base—and, inevitably, some haters. Eventually, creative differences between Squizz and Karbowiak led to Squizz forking the project and making this repository the official home of zKillboard.com.

In the following year, Squizz began exploring NoSQL databases, which better suited the scale and structure of killmail data. After two months of intense development and major database changes, the repository was made public with full NoSQL support.

With the shutdown of eve-kill.net and Battleclinic in the years leading up to 2015, Squizz committed to ensuring all collected killmails remained accessible to the public. Various APIs and killmail data dumps are available—see the Wiki for details.

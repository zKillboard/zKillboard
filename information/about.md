# About zKillboard

## What is zKillboard?

zKillboard is EVE Online's premier killboard, tracking and archiving PvP combat data from across New Eden. Since its inception, zKillboard has become the go-to resource for players to track their kills, losses, and combat statistics in one of the most complex virtual universes ever created.

We process millions of killmails, providing detailed analytics, statistics, and insights into every ship destroyed, every battle fought, and every pilot's combat history.

---

## How It Works

### Data Collection

zKillboard receives killmail data through multiple channels:

- **Player Authentication**: When you log in with your EVE Online character and grant permissions, we automatically fetch your personal and corporation killmails through CCP's ESI (EVE Swagger Interface)
- **Manual Submissions**: Players can manually submit killmails via our API
- **Community Integration**: Third-party tools and applications feed data through our public API
- **Real-time Processing**: Our systems process approximately **10 killmails per second** during peak activity

### What We Track

Every killmail contains rich information that we analyze and present:

- **Combat Details**: Victim ship, attackers, weapons used, damage dealt
- **Location Data**: Solar system, region, security status
- **Value Calculations**: ISK destroyed, fitted value, dropped loot
- **Player Statistics**: All-time, monthly, and weekly rankings
- **Corporation & Alliance Stats**: Aggregate performance metrics
- **Ship & Item Analytics**: Popular fittings, loss trends

### Advanced Features

- **Real-time Updates**: Live killmail feed via WebSocket connections
- **Battle Reports**: Automatic battle detection and aggregation
- **Advanced Search**: Complex queries across millions of killmails
- **Killmail Sponsorship**: Highlight significant kills
- **API Access**: Full programmatic access for developers
- **Rankings**: Multiple leaderboards (all-time, weekly, recent, solo)

---

## The Technology

zKillboard is built on modern open-source technology:

- **Backend**: PHP with MongoDB for data storage
- **Caching**: Redis for high-performance data access
- **Real-time**: Redis pub/sub for live updates
- **Processing**: Multi-threaded cron jobs handling millions of records
- **API**: RESTful endpoints serving thousands of requests per minute

Our infrastructure processes **millions of killmails** going back years, making EVE Online's combat history searchable and accessible to everyone.

---

## Community & Open Source

zKillboard is a **collaborative effort** built and maintained by the EVE Online community:

- **Open Source**: Our codebase is available on [GitHub](https://github.com/zKillboard/zKillboard)
- **Community Contributions**: Developers from around the world contribute code, features, and fixes
- **Free to Use**: All core features are available to everyone at no cost
- **Third-Party Friendly**: Comprehensive API for tool developers

---

## Contacts & Support

### Get Help

- **Read First**: Check our [FAQ](/information/faq/) for answers to common questions
- **Discord**: Join our [Discord server](https://discord.gg/sV2kkwg8UD) for community support and announcements
- **GitHub**: Report bugs or contribute at [github.com/zKillboard](https://github.com/zKillboard/zKillboard)

### For Developers

- **API Documentation**: Available at [/information/api/](/information/api/)
- **API Support**: Technical questions welcome in our Discord
- **Rate Limits**: Please be respectful of our infrastructure

---

## Third-Party Data & Attribution

Some information displayed on zKillboard comes from third-party sources:

- **EVE Online Data**: Character, corporation, alliance, and item information is provided by CCP Games through their ESI API
- **Pricing Data**: Market prices sourced from EVE Online's market API
- **Static Data**: Ship, item, and universe data from CCP's Static Data Export (SDE)

All EVE Online-related materials are the property of CCP Games. zKillboard is not affiliated with or endorsed by CCP Games.

---

## Support zKillboard

Running zKillboard requires significant infrastructure and bandwidth. If you find our service valuable:

- **Send ISK**: Support via in-game ISK for ad-free access ([details here](/information/payments/))
- **Patreon**: Subscribe at [patreon.com/zkillboard](https://www.patreon.com/zkillboard)
- **Spread the Word**: Share zKillboard with your corp and alliance

Your support helps keep zKillboard running for the entire EVE Online community. o7

---

**zKillboard** - Documenting New Eden's conflicts since 2011.

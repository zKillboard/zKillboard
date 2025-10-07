# zKillBot 

zKillBot is a Discord bot that listens to [zKillboard’s RedisQ](https://github.com/zKillboard/RedisQ) and posts killmails directly into your Discord server.

<img src="/img/zkillbot-example.png" alt="zKillBot Example" width="400">

---

## Invite zKillBot

Click here to invite the bot to your server (**you must have the Manage Server permission!**):

<a class="btn btn-primary" href="https://discord.com/oauth2/authorize?client_id=1422039566721876069&permissions=2048&integration_type=0&scope=bot+applications.commands" target="_blank" rel="noopener noreferrer">
  <strong>Invite zKillBot to your Discord</strong>
</a>

---

## Commands

All commands are available as Discord slash commands (`/command`).

🌐 command is available to everyone

🔒 command requires Manage Channels permission

#### `/zkillbot about` 🌐

Reports various statistics about zKillBot.

#### `/zkillbot check` 🔒

Verifies the current channel’s permissions to ensure zKillBot can post messages here.  
This command **must be run successfully before any subscriptions can be added**.

#### `/zkillbot config-channel` 🔒

Allows you to customize what fields are posted.  You can Display/Hide each of the following:

- (Header) The Victim (with their image and link to zkill)
- Description.  If hidden, a description must be present and will default to the killmail's zkill url.
- Image.  The image of the ship.
- Destroyed
- Dropped
- Fitted Value
- Involved
- Points
- Killmail Value.
- (Footer) The entity that gave the final blow (with their image)
- Timestamp.  The time the killmail. This is automatically adjusted by Discord to your locale.


#### `/zkillbot invite` 🌐

Returns the bot’s invite link.

#### `/zkillbot leave` 🔒

Removes all subscriptions on the server and instructs zKillBot to leave the server.

#### `/zkillbot list` 🌐

List all current subscriptions in the channel.

#### `/zkillbot remove_all_subs` 🔒

Remove all subscriptions for the current channel.

#### `/zkillbot subscribe <id | name | isk | label>` 🔒

Subscribes the current channel to killmails for a specific **character, corporation, alliance, location, system, constellation, region, minimum ISK value, or label**.

Examples:
```
# Character
/zkillbot subscribe CCP Stroopwafel

# Corporation
/zkillbot subscribe eve university

# Alliances, e.g. C C P Alliance
/zkillbot subscribe 434243723  
/zkillbot subscribe The Wormhole Police

# Capsules
/zkillbot subscribe 670

# System
/zkillbot subscribe 30000142

# Region, e.g. The Forge
/zkillbot subscribe 10000002

# ISK, provide a minimum value
/zkillbot subscribe isk:1000000000

# Labels (see below for full list of labels)
/zkillbot subscribe label:ganked
```

#### `/zkillbot unsubscribe <id | isk | label>` 🔒

Removes a subscription from the current channel, stopping killmail posts for the specified entity.

Example:
```
/zkillbot unsubscribe 670
/zkillbot unsubscribe isk  # No need to add the value here
/zkillbot unsubscribe label:ganked
```

---

## Notes
- zKillBot uses RedisQ to stream killmails from zKillBoard in near real-time. 
- If a name query matches multiple results, zKillBot will list the IDs so you can subscribe by ID or refine your search with a longer name.
- A channel may have multiple subscriptions.  
- Each killmail will only be posted once per channel, even if it matches more than one subscription.
- Subscriptions are per-channel, meaning different channels can track different entities.  
- Killmails posted include links back to [zKillboard](https://zkillboard.com/) for full details.
- ISK values are adjusted to your guildId's locale setting.

## Upcoming Features
- Filter for losses or kills only (advanced filter?)
- Grouping iskValue, entityId, label together (advanced filter?)
- LOCALE implementation for language (ISK values already implemented)
- linking entities to their respective pages
- Subscribe to ship groups (e.g., Frigates, Titans).  
- Fine-grained filters for precise control over subscriptions.  
- Subscribe to a system and receive killmails within a chosen jump range or light-year distance.

---

## Labels

Valid labels:

```
#:1, #:10+, #:100+, #:1000+, #:2+, #:25+, #:5+, #:50+, 
atShip, awox, bigisk, capital, 
cat:11, cat:18, cat:22, cat:23, cat:350001, cat:40, cat:46, cat:6, cat:65, cat:87, 
concord, extremeisk, ganked, insaneisk, 
isk:100b+, isk:10b+, isk:1b+, isk:1t+, isk:5b+, 
loc:abyssal, loc:drifter, loc:highsec, loc:lowsec, loc:nullsec, loc:w-space, 
npc, padding, pvp, solo, 
tz:au, tz:eu, tz:ru, tz:use, tz:usw
```

---

## Support
For issues or feature requests, join the [zKillboard Discord](https://discord.gg/sV2kkwg8UD) and stop by channel `#discord-zkillbot`

## Github

The source for zKillBot can be found at [https://github.com/zKillboard/zkillbot](https://github.com/zKillboard/zkillbot)

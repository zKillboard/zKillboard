# Delayed Killmails Feature

## Overview

Delayed killmails have been implemented as a configurable option to address long-standing community requests. This feature allows entities to delay when their killmails appear on zKillboard while maintaining the integrity of the killboard ecosystem.

## Delay Options

The following delay options are available:

- **ASAP** - killmails will post as they are received (default)
- **1 hour** - killmails will post when they are 1 hour old
- **3 hours** - killmails will post when they are 3 hours old  
- **8 hours** - killmails will post when they are 8 hours old
- **24 hours** - killmails will post when they are 24 hours old
- **72 hours** - killmails will post when they are 72 hours (3 days) old

## How It Works

### Priority System
- Manually posted killmails will always take priority over API delays
- Entities with shorter delays will take priority over longer delays
- If a corporation has a 3-hour delay but shoots someone with a 1-hour delay, the shorter delay wins

### API and RedisQ Impact
- Delayed killmails won't appear in the API or RedisQ until the delay period has passed
- Once the delay expires, the killmail is posted normally

### Configuration
- The shortest delay among all involved parties determines when the killmail appears
- To configure delays, logout and log back into zKillboard to replace existing scopes

## Use Cases

This feature addresses several scenarios where immediate killmail posting was problematic:

- **Fleet Operations**: Prevents enemies from seeing your fleet composition and movement
- **Roaming Groups**: Avoids revealing your route and ship fittings to potential targets
- **Wormhole Operations**: Allows time for hole closures before activity is revealed
- **Testing New Doctrines**: Provides time to refine fits before they become public
- **Strategic Operations**: Maintains operational security for time-sensitive activities

## Beta Period

The feature launched in beta to identify and resolve any technical issues. The implementation has been stable and is now a permanent feature of zKillboard.

## Frequently Asked Questions

**Q: Do all parties need to set up delays for it to work?**
A: No, if only one side has an API configured with zKillboard, that entity's delay setting will be used.

**Q: Will other killboards still show immediate killmails?**
A: If other killboards have direct API access from the involved parties, they may post immediately. If they rely on zKillboard's feed, they will inherit the delay.

**Q: Can I still manually post killmails immediately?**
A: Yes, manually posted killmails always take priority and will appear immediately regardless of delay settings.

**Q: How do I configure the delay?**
A: Log out of zKillboard and log back in. During the login process, you'll be able to select your preferred delay option.
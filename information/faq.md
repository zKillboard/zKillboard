# ❓ Frequently Asked Questions

---

## 📊 Data & Killmails

### [#](#how) Why doesn't zKillboard have all my killmails?

zKillboard doesn't automatically receive all killmails. **CCP does not make killmails public** - they must be provided through various means.

**How killmails reach zKillboard:**

1. **Player Authorization** *(Primary Source)*
   - Characters grant access to their personal killmails via EVE SSO
   - Directors/CEOs grant access to corporation killmails
   
2. **Manual Submissions**
   - Players manually post killmails through the API or website
   
3. **War System**
   - Automatic collection of war-related killmails
   
4. **Economic Reports**
   - Monthly data dumps from CCP may include additional kills

**How the killmail system works:**

Just like in-game, only certain parties receive killmails:
- The **victim** always gets the killmail
- The character with the **final blow** gets the killmail
- If an **NPC gets final blow**, the last player to deal damage receives it

**Remember:** Every PvP killmail has two sides. Victims often don't share their losses, but attackers usually do. If neither side has authorized zKillboard, we won't receive the killmail.

---

### [#](#authorize) How do I authorize zKillboard to retrieve my killmails?

**It's simple:**

1. Click the character icon in the top-right menu
2. Log in with your EVE Online character via SSO
3. Grant the requested permissions during authentication
4. zKillboard will automatically fetch your killmails every 15 minutes

You can also enable corporation killmails if you're a Director or CEO.

---

### [#](#remove) Can you remove a killmail because [reason]?

**No. Killmails are never removed from zKillboard.**

Here's why:
- Once posted, killmails are distributed to dozens of other services via RedisQ
- Removing it here won't erase the fact that it happened
- Even if CCP reimbursed your ship, the killmail still exists in-game and in CCP's database
- It's part of New Eden's permanent history

**This includes:**
- Embarrassing losses
- Ships you lost testing fits
- Kills/losses during friendly scrimmages
- NPC losses (see below)

---

### [#](#npc) I have NPC killmails showing - can you remove them?

**No.** Since Spring 2016, zKillboard displays all killmails it receives, including NPC losses.

**Good news:** NPC-only losses **do not count** against your statistics. Your efficiency, danger ratio, and rankings ignore pure PvE deaths.

---

## 🎯 Points & Statistics

### [#](#points) How are kill points calculated?

Points are inherently arbitrary - there's no perfect formula that satisfies everyone. Here's what we consider:

**Point Calculation Factors:**

- ✅ **Victim ship size** - Larger ships worth more
- ✅ **Fitted module meta** - Offensive/defensive modules increase value
- ✅ **Mining equipment** - Reduces points (miners aren't combat fits)
- ✅ **Fleet size penalty** - Larger gangs reduce points per participant
- ✅ **Attacker ship size** - Bonus for killing larger ships, penalty for smaller
- ✅ **Minimum value** - Every kill is worth at least 1 point

**Size comparison bonuses:**
- Killing a **bigger ship**: Up to +20% bonus
- Killing a **smaller ship**: Up to -50% penalty

Points are **final and not subject to debate**. Attempts to argue about point values will be directed back to this FAQ.

---

### [#](#solo) What defines a "solo" killmail?

**A solo kill requires:**

1. Exactly **one non-NPC attacker** (you)
2. Any number of NPC attackers is fine
3. Victim is **not** a Corvette, Shuttle, or Capsule

**Not considered solo:**
- Killmails with only NPC attackers
- Kills on rookie ships, shuttles, or pods (even if you're alone)
- Any killmail with 2+ player attackers

---

## 💰 ISK Values

### [#](#blueprint) How do you price blueprint copies and SKINs?

Blueprint copies and SKIN prices are extremely volatile and unreliable in the market API. 

**Our solution:** All blueprint copies and SKINs are valued at **0.01 ISK**.

This prevents wild ISK value swings on killmails and ensures consistency.

---

## 🔐 Privacy & Data

### [#](#authorized) What do you do with my authorized killmail access?

**We read your killmails. That's it.**

The ESI killmail endpoints only allow us to:
- ✅ Fetch your kill and loss data
- ✅ See which systems you've been active in (via killmails)

We **cannot:**
- ❌ Access your wallet
- ❌ View your assets
- ❌ Control your character
- ❌ Read your mail
- ❌ Do anything beyond reading killmails

---

### [#](#fittings) What about the ship fitting permission?

zKillboard will **only write ship fittings** if you:

1. Granted the "Write Fittings" permission during login
2. Click a "Save Fitting" button on a killmail page

We never write fittings automatically or without your explicit action.

---

### [#](#namechange) I changed my character's name - how do I update it?

**Quick update method:**

1. Log out of zKillboard
2. Log back in with your character
3. Allow 1-2 hours for caches to clear

The ESI API doesn't always reflect name changes immediately, but logging in forces an update.

---

### [#](#ohnos) Can I remove my character/corporation/alliance from zKillboard?

**No. All entities are always displayed.**

- We will not accept ISK or any payment to remove entities
- Multiple substantial offers have been made and rejected
- This policy is non-negotiable

All EVE Online data is owned by CCP Games and is part of the public game universe.

---

## ⚖️ Legal & Privacy

### [#](#butmyrights) You're violating my privacy! I'll sue!

**No, we're not.** All character names, killmails, ships, and game data are owned by **CCP Games**, not you. zKillboard derives all information from CCP's databases and APIs.

**If your character name matches your real name:**

1. Contact CCP Games: https://www.ccpgames.com/contact-us/
2. Request a character name change through a support ticket
3. Once CCP processes the change, zKillboard will automatically update within a week

**Legal references:**
- [Section 230 of the Communications Decency Act](https://www.eff.org/issues/cda230)
- [CCP's Terms of Service](https://community.eveonline.com/support/policies/terms-of-service-en/)

---

### What about GDPR?

**All EVE Online game data is owned by CCP Games.**

zKillboard does not contain personally identifiable information. If you created a character with your real name and want it changed:

1. **Contact CCP Games** at **legal@ccpgames.com**
2. Submit a GDPR request through their support system
3. Once CCP updates the name in their database, zKillboard will reflect the change

**For zKillboard-specific data** (preferences, ad-free status, favorites), contact us via [Discord](https://discord.gg/sV2kkwg8UD).

---

## 🔧 Account Management

### [#](#dislike) How do I revoke zKillboard's API access?

**Option 1: Through zKillboard**
1. Log in to zKillboard
2. Visit https://zkillboard.com/account/api/
3. Remove the authorizations you want to revoke

**Option 2: Through CCP**
- Visit CCP's SSO management: https://developers.eveonline.com/authorized-apps
- Revoke zKillboard's access from there

---

### [#](#sisi) Is there a killboard for Singularity (Sisi) or the Chinese server?

**Singularity (Test Server):**
- ❌ CCP removed all killmail API endpoints from Sisi
- **Reason:** Players were attempting to brute-force ships being tested for alliance tournaments
- No test server killboard is possible or permitted

**Chinese Server (Serenity):**
- ❌ The Chinese server has separate APIs and operates independently
- zKillboard does not mix killmails from different servers
- Different environments, different playerbases, separate ecosystems

---

## 💬 Still Have Questions?

**Join our Discord:** https://discord.gg/sV2kkwg8UD

**Check out:**
- [About zKillboard](/information/about/)
- [API Documentation](/information/api/)
- [Legal Information](/information/legal/)
- [GitHub Repository](https://github.com/zKillboard/zKillboard)

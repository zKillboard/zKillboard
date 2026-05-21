# ⏱️ Delayed Killmails

## What Are Delayed Killmails?

Delayed killmails give you control over **when** your kills and losses appear on zKillboard. Instead of posting immediately, you can configure a delay period that protects your operational security while maintaining the integrity of New Eden's combat records.

This feature addresses long-standing community requests for better OpSec without compromising the killboard's core mission of documenting EVE's conflicts.

---

## Available Delay Options

Choose the delay that best fits your operational needs:

- **ASAP** *(default)*
	Posts: Immediately
	Best for: Solo PvP, public fleets, no OpSec concerns

- **1 Hour**
	Posts: After 1 hour
	Best for: Quick roams, small gang PvP

- **3 Hours**
	Posts: After 3 hours
	Best for: Medium-scale operations, wormhole chains

- **8 Hours**
	Posts: After 8 hours
	Best for: Strategic operations, daily fleet activities

- **24 Hours**
	Posts: After 1 day
	Best for: Major operations, capital deployments

- **72 Hours**
	Posts: After 3 days
	Best for: Maximum OpSec, sensitive campaigns

---

## How It Works

### 🎯 Priority Rules

The delay system follows simple, logical rules:

1. **Manual posts override delays** - Manually submitted killmails always appear immediately
2. **Shorter delays win** - The shortest delay among all involved parties determines posting time
3. **One-sided protection** - If you're the only entity with an API configured, your delay setting applies

**Example:** Your corporation uses a 3-hour delay, but you engage a target with a 1-hour delay. The killmail posts after **1 hour** (shorter delay wins).

### 🔒 What Gets Delayed

When a killmail is delayed:

- ❌ **Won't appear** on zKillboard's website
- ❌ **Won't appear** in API queries
- ❌ **Won't appear** in RedisQ feed
- ❌ **Won't appear** in search results or stats

When the delay expires:

- ✅ **Posts normally** as if it just happened
- ✅ **Appears in all APIs** and feeds
- ✅ **Counts toward statistics** and rankings

### ⚙️ Configuration

**To set up or change your delay:**

1. Log out of zKillboard
2. Log back in with your character
3. During authentication, select your preferred delay option
4. Your new delay applies to all future killmails

**Note:** You must re-authenticate to update your delay setting. Existing scopes won't automatically update.

---

## Why Use Delayed Killmails?

### Operational Security

Protect your operations from intelligence gathering:

- **Fleet Composition**: Enemies can't immediately see your fleet's ships and fittings
- **Movement Patterns**: Your roaming path isn't revealed in real-time
- **Target Selection**: Prevent others from tracking where you're hunting
- **Force Projection**: Deploy capitals without broadcasting your position

### Tactical Advantages

Gain strategic benefits:

- **Wormhole Operations**: Close holes before your activity is public
- **Territory Control**: Complete objectives before defenders respond
- **Doctrine Testing**: Refine new fits before they're copied
- **Campaign Security**: Maintain surprise for multi-day operations

### When NOT to Use Delays

Delays aren't always beneficial:

- Solo PvP where you want immediate recognition
- Public fleets with no security concerns
- Participation in open alliance tournaments
- When you want real-time bragging rights

---

## Impact on Third Parties

### Other Killboards

- **Direct API Access**: If other killboards have their own API access from involved parties, they may post immediately
- **zKillboard Feed**: Third parties relying on zKillboard's data feed inherit your delay settings

### Your Statistics

- Delayed killmails **do count** toward your all-time stats once posted
- They appear in **recent/weekly rankings** once posted
- Your character page updates normally after the delay expires

---

## Frequently Asked Questions

**Q: Do all participants need delays configured?**  
**A:** No. If you're the only entity with an API configured, your delay setting is used. The shortest delay among all parties always wins.

**Q: Can I still post kills manually?**  
**A:** Yes! Manual submissions always override delays and post immediately. This is useful for sharing specific kills while maintaining delays on others.

**Q: Will my kills "disappear" after posting?**  
**A:** No. Once posted, killmails remain permanently. Delays only affect the initial posting time.

**Q: How do I check my current delay setting?**  
**A:** Your delay is configured during login. To verify or change it, log out and log back in.

**Q: Can I use different delays for different characters?**  
**A:** Yes. Each character has its own delay setting configured during authentication.

**Q: What about corporation killmails with Director/CEO scopes?**  
**A:** Corporation-level killmails use the delay configured on the character that granted the scope.

---

## Status

✅ **Fully Operational** - This feature launched in beta and is now a permanent, stable part of zKillboard.

---

**Questions?** Join our [Discord](https://discord.gg/sV2kkwg8UD) for support.
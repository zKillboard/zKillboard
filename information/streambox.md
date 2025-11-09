# 📺 StreamBox

**StreamBox** is a simple, lightweight tool built for streamers who want to showcase their most recent killmails live on stream. No configuration required—just add `/streambox/` to your URL and you're ready to go.

---

## 🚀 How to Use StreamBox

Getting started is incredibly simple:

1. **Navigate** to your character, corporation, or alliance page on [zKillboard.com](https://zkillboard.com)
2. **Add** `streambox` to the end of the URL
3. **Display** the page in your streaming software (OBS, Streamlabs, etc.)

### 📋 Examples

**Character StreamBox:**
```
Original URL:  https://zkillboard.com/character/1633218082/
StreamBox URL: https://zkillboard.com/character/1633218082/streambox/
```

**Solo Kills Only:**
```
Solo URL:      https://zkillboard.com/character/1633218082/solo/
StreamBox URL: https://zkillboard.com/character/1633218082/solo/streambox/
```

**Corporation StreamBox:**
```
Original URL:  https://zkillboard.com/corporation/98621101/
StreamBox URL: https://zkillboard.com/corporation/98621101/streambox/
```

**Alliance StreamBox:**
```
Original URL:  https://zkillboard.com/alliance/99003581/
StreamBox URL: https://zkillboard.com/alliance/99003581/streambox/
```

If you have recent killmails, StreamBox will display them in a clean, stream-friendly layout. If there's no activity, it stays empty until you create some explosions!

---

## 📸 What It Looks Like

![StreamBox Example](/img/streambox_example.png)

---

## 💡 Why StreamBox Exists

StreamBox was created at the request of **Brother Grimoire**, a popular EVE Online pirate streamer. Tired of third-party streamer tools breaking or losing support, he wanted something reliable, simple, and integrated directly into zKillboard.

**StreamBox delivers exactly that:** zero setup, zero maintenance, zero cost.

---

## ❓ Frequently Asked Questions

**What killmails does it show?**  
✅ The most recent killmails from the **last 8 hours**

**How many killmails are displayed?**  
✅ Up to **5 by default** (configurable up to 25)

**How often does it update?**  
✅ Automatically refreshes **approximately once per minute**

**How are losses displayed?**  
✅ Losses show a subtle **"×"** symbol to the left of the ship value

**How are ISK values formatted?**  
✅ Ship values are automatically formatted according to **your browser locale**

**Do I have to pay to use StreamBox?**  
✅ **No!** StreamBox is completely **free** for everyone

**Can I customize it?**  
✅ **Yes!** You can configure the number of kills displayed and choose horizontal or vertical layout

---

## ⚙️ Customizing Your StreamBox

You can control how many kills are shown and switch between horizontal/vertical layouts using custom CSS in your streaming software.

### 🎨 Configuration Options

Add this CSS block to your **OBS Custom CSS** section (or equivalent in your streaming software):

```css
:root {
	--max-kills: 5;   /* Number of kills to display (1–25 recommended) */
	--vertical: 0;    /* 0 = horizontal layout, 1 = vertical layout */
}
```

### 📐 Recommended Browser Source Sizes

| Layout | Dimensions | Best For |
|--------|------------|----------|
| **Horizontal** (default) | 400px wide × 125px tall | Bottom/top overlays |
| **Vertical** | 76px wide × 470px tall | Side panels |

### 📏 Adjusting for More/Fewer Kills

**Horizontal Layout:**
- Each additional kill adds **76px** to the width
- Example: 10 kills = 400 + (5 × 76) = **780px wide**

**Vertical Layout:**
- Each additional kill adds **94px** to the height
- Example: 10 kills = 470 + (5 × 94) = **940px tall**

### 🔄 Testing Your Changes in OBS

1. **Right-click** your Browser Source → **Properties**
2. Click **"Refresh cache of current page"**
3. If the layout doesn't update, **toggle visibility** (eye icon) off and on
4. Verify your **Browser Source dimensions** match your chosen layout
5. If styling looks wrong, check that **Custom CSS was applied correctly**—OBS sometimes caches old versions

Your layout should update immediately once the cache is refreshed!

---

## 🛠️ Troubleshooting

**StreamBox is blank/not showing killmails:**
- ✅ Verify you have killmails within the last 8 hours
- ✅ Make sure the URL ends with `/streambox/` (with trailing slash)
- ✅ Check your browser source is pointing to the correct URL

**Layout is cut off or overlapping:**
- ✅ Verify your browser source dimensions match the recommended sizes
- ✅ Check your `--max-kills` value against your canvas size
- ✅ Refresh the browser source cache in OBS

**Changes aren't appearing:**
- ✅ Refresh the browser source cache (right-click → Properties → Refresh)
- ✅ Toggle visibility off/on in OBS Sources panel
- ✅ Verify CSS was saved correctly in Custom CSS section

**Values or fonts look weird:**
- ✅ Make sure Custom CSS is applied in OBS settings
- ✅ Clear browser cache and refresh the source
- ✅ Check for conflicting CSS from other sources

---

## 💬 Need Help?

Join the [zKillboard Discord](https://discord.gg/sV2kkwg8UD) for support, suggestions, or to share your StreamBox setup!

---

## 🎬 Pro Tips for Streamers

- Use **horizontal layout** for bottom-screen overlays without blocking gameplay
- Use **vertical layout** for side panels on ultra-wide streams
- Use the **solo streambox** to highlight your 1v1 prowess

**Happy streaming, capsuleer! o7**


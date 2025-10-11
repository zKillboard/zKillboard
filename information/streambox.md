# StreamBox

StreamBox is a simple, lightweight tool built for streamers who want to showcase their most recent killmails live on stream.

## How It Works

Viewing your StreamBox is easy:

1. Go to your character, corporation, or alliance page on [zKillboard.com](https://zkillboard.com).
2. Add `streambox` to the end of the URL.

For example, if your character page is:

    https://zkillboard.com/character/1633218082/

Adding `streambox` gives you:

    https://zkillboard.com/character/1633218082/streambox/

If you have recent killmails, StreamBox will display them in a clean, stream-friendly layout.  
If you don’t, it’ll just stay empty until you get back to work making explosions.

Want only solo kills? Just navigate to your solo page and tack on `/streambox/`:

    https://zkillboard.com/character/1633218082/solo/streambox/

## Example StreamBox screenshot

![Example StreamBox screenshot](/img/streambox_example.png)

## Why StreamBox?

StreamBox was originally requested by streamer **Brother Grimoire**, a well-known pirate enthusiast. Frustrated by other streamer tools breaking or losing support, he wanted something reliable, simple, and built directly into zKillboard. That’s exactly what StreamBox delivers.

Adjusting Kill Display Count or Layout
======================================

You can easily control how many kills are displayed and whether they appear in a horizontal or vertical layout by adding a small CSS block to your Custom CSS section in OBS (or whatever tool you’re using to render the browser source).

Step 1: Add these CSS variables
-------------------------------
```
:root {
	--max-kills: 5;   /* Number of kills to display (1–25 recommended) */
	--vertical: 0;    /* 0 = horizontal layout, 1 = vertical layout */
}
```

Step 2: Customize the values
----------------------------
- Horizontal layout (default)
  Recommended browser source size: 400 px wide × 125 px tall

- Vertical layout
  Recommended browser source size: 76 px wide × 470 px tall

Step 3: Adjust for custom layouts
---------------------------------
If you want a different number of kills, adjust your browser source size using these guides:
- Add or remove 76 px from the width for each additional kill in horizontal mode
- Add or remove 94 px from the height for each additional kill in vertical mode

Step 4: Testing in OBS
----------------------
1. After changing the CSS, right-click your Browser Source → Properties → Refresh cache of current page to reload it.  
2. If the layout doesn’t update immediately, toggle the visibility eye off and on again in the Sources list.  
3. Double-check your Browser Source width and height match the recommendations above.  
4. If fonts or styling look wrong, verify the Custom CSS was applied correctly — OBS sometimes caches older versions.

Once refreshed, your layout should update instantly to reflect your chosen number of kills and orientation.



## FAQ

- **What does it show?**  
  The most recent killmails from the last 8 hours.
- **How many killmails are displayed?**  
  Up to 5 at a time.
- **How often does it update?**  
  StreamBox refreshes automatically, updating approximately once per minute.
- **How are losses displayed?**  
  Losses are indicated by a subtle, non-obtrusive **“×”** to the left of the ship’s value.
- **How are ship values shown?**  
  Ship values are automatically formatted according to your locale, so numbers appear in the style you’re used to seeing.
- **Do you have to pay to use this?**  
  No! StreamBox is completely public and free for anyone to use.
- **Can I configure it?**  
  Configuration options are planned, but the goal is to keep StreamBox as simple and hassle-free as possible.


---

Need help or support? Join the [zKillboard Discord](https://discord.gg/sV2kkwg8UD).


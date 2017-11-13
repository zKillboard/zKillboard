###FAQ

***

#####zKillboard doesn&#39;t have all my killmails, where are they?

zKillboard does not get all killmails automatically. CCP does not make killmails public. They must be provided by various means.

In short there are many sources for a killmail:

* Someone manually posts the killmail.
* A character has authorized zKillboard to retrieve their killmails.
* A corporation director or CEO has authorized zKillboard to retrieve their corporation&#39;s killmails.
* War killmail (victim and final blow have a Concord sanctioned war with each other)

The killmail API works just like killmails do in game. The victim gets the killmail, and the person with the finalblow gets the killmail. Therefore, for zKillboard to be able to retrieve the killmail via API it must have the character or corporation API submitted for the victim or the person with the final blow. If an NPC gets the final blow, the last character to aggress to the victim will receive the killmail and credit for the final blow.

Remember, every PVP killmail has two sides, the victim and the aggressors. Victims often don&#39;t want their killmails to be made public, however, the aggressors do. 

***

#####How do I authorize zKillboard to retrieve my killmails?

It&#39;s easy! Log in using the dropdown menu in the top right where you can see the empty character icon (unless you&#39;re already logged in of course).

***

#####Can you remove a kill from zKillboard because ____________ ?

Kills are never removed from zKillboard. Once zKillboard has a killmail it is disseminated to about dozen other sources via RedisQ. Removing a killmail will not prevent the fact that it happened. Even if CCP reimbursed your ship and items, the killmail still happened, and it still exists in game too.

***

#####I have NPC killmails on here, I don&#39;t want them here. Can you remove them?

In the Spring of 2016 zKillboard starting displaying all killmails that it receives including losses to NPC&#39;s. This has been a popular request and supported by many. Killmails aren&#39;t removed from zKillboard. Also, please remember that losses to just NPC&#39;s are not counted against you in your stats.

***

#####I don&#39;t like the points that are given on a particular kill, can you fix it to appease me?

No. Points are very, very arbitrary. Calculating them in a fashion that keeps everyone happy is impossible.

In short, this is how points are calculated:

* Size of victim ship
* Meta level of items fitted that have offensive/defensive capabilities to determine danger level of victim.
* Meta level of Miners fitted to reduce points.
* Number involved on killmail, the larger the gang the bigger the penalty.
* Average size of attacking ships. Killing a bigger ship gets up to a 20% bonus, a smaller ship up to a 50% penalty.
* A kill is always worth at least 1 point.

Any attempts at point discussion will likely result in a link to this FAQ.

***

#####How do you value blueprint copies or skin prices?

Blueprint copies and skin prices have been extremely volatile and are difficult to calculate the proper price. Because of this difficulty, it is better to err on the side of caution. Therefore, all blueprint copies and skins are given a price of 0.01 ISK.

***

#####I have given authorized you to retrieve my killmails, what are you doing with this information?

Read the killmails. That is all. That is really about all zKillboard can do with the killmail endpoints.

***

#####I have given authorized you to save ship fittings, what are you doing with this information?

zKillboard will write to your character&#39;s ship fittings, if and only if, it has been given permission to do so by having the "Allow zKillboard to write Fittings" checked when logging in and you click a "Save Fitting" link from a killmail page.

***

#####I don&#39;t want my character/corporation/alliance shown on zKillboard? How do I remove them?

All characters, corporations, and alliances will always be displayed. zKillboard will not accept ISK or any form of currency to have entities removed. Yes, offers have been made and all offers get turned down.

***

#####You are violating my privacy rights! I will sue! What will you do to stop?

Nothing. You are throwing threats at a game website where the ships, names, killmails, etc. are all owned by CCP. While this website is not owned or operated by CCP it does derive all of its information from their databases. 

If for some reason a character&#39;s name matches your real life name, please, contact CCP and work with them to find a resolution. 
https://www.ccpgames.com/contact-us/ . People have reported that they have had success in getting their names removed. Once CCP informs you that they have taken action, please allow up to a week for the changes to reflect on this website.

Please also read and understand Section 230 of the Communications Decency Act: https://www.eff.org/issues/cda230

***

#####I don&#39;t like you and I want all my API&#39;s gone! How do I remove them?

There is a page within your account for that, please visit https://zkillboard.com/account/api/ (You must be logged in.)

Also, CCP provides the following pages to help manage APIs:

* SSO API: https://community.eveonline.com/support/third-party-applications/

# zKillboard Features Documentation

This guide outlines the features and capabilities of zKillboard based on codebase analysis.

*Note: This documentation is vibed - features are documented based on code analysis and may not represent the exact current state of all functionality.*

---

## 🆕 June & July 2026 Highlights

### June 2026
- **Item-aware Advanced Search**: Search killmails for specific fitted, cargo, or dropped item types using AND/OR matching
- **Queued Advanced Search**: Expensive searches and aggregations continue in the background, with the page polling until results are ready
- **Daily Statistics**: Entity pages gained daily kills/losses analytics with selectable dates, Ships/ISK/Points graphs, summaries, top entities, top values, and label breakdowns
- **Scoped Favorites**: Corporation CEOs and alliance executor CEOs can maintain shared corporation and alliance killmail favorites alongside personal favorites
- **Trophy Leaderboard**: A dedicated leaderboard ranks characters by completed trophy levels and completion percentage
- **Faster In-site Navigation**: Eligible internal links load through SPA-style navigation with browser history and scroll restoration
- **Delayed Submissions**: Manual and API killmail submissions can request a strategic publication delay and receive clear queued-processing status

### July 2026
- **Richer Search Matching**: Autocomplete supports spaced partial-name matching and corporation/alliance ticker matching, with clearer match highlighting and more suggestions
- **Expanded Statistics UX**: Daily analytics gained broader date-range handling, while overview rankings gained live recent/weekly refreshes and Kills/ISK sorting for top entities
- **Inferred Fits**: A dedicated ship-fit explorer derives popular combat fits from observed killmail history and integrates those results with Advanced Search
- **Campaigns (Beta)**: Users can turn attacker-versus-defender Advanced Searches into public or link-shareable private campaign reports with side comparisons, top lists, killmails, and ongoing statistics
- **Richer Entity Profiles**: Character, corporation, and alliance pages expose more ESI-backed metadata, including corporation founder, home station, member count, tax rate, war eligibility, and alliance executor details when available
- **Supporter Recognition**: 1B ISK supporters receive a silver portrait shine, 10B ISK supporters receive a gold super-monocle shine, and the portrait effect can be disabled in site settings
- **ESI Entity Fallback**: Missing character, corporation, and alliance pages can show live public ESI identity details instead of only a generic 404

---

## 🔐 Authentication & User Management

### EVE Online SSO Integration
- **OAuth2 Authentication**: Secure login through EVE Online's Single Sign-On system
- **Scope-based Permissions**: 
  - `esi-killmails.read_killmails.v1` - Personal killmail access
  - `esi-killmails.read_corporation_killmails.v1` - Corporation killmail access
  - `esi-fittings.write_fittings.v1` - Fitting export to EVE
- **Session Management**: Redis-based session handling with MongoDB storage
- **No Scopes Login**: Optional login without API scopes for basic access
- **Corporation/Alliance Affiliation**: Automatic detection through ESI integration

### Account Management
- **Profile Management**: Character information, corporation/alliance details, payment history
- **API Scope Management**: Add, remove, and manage ESI scopes with last validation timestamps

### User Interface Features
- **Theme Customization**: Multiple visual themes available for user selection
- **Activity Heatmaps**: 90-day activity visualization with hourly breakdown 
- **Monthly History Tracking**: Detailed month-by-month performance analytics
- **Interactive Maps**: EVE Online map integration with activity visualization
- **Autocomplete Search**: Search suggestions with external integration support
- **Login Page Preferences**: Customizable default login destinations (main, character, corp, alliance)
- **Tracker Notifications**: Configurable notification system for tracked entities
- **Account Management**: Personal settings, API key management, payment history
- **Notification Settings**: Tracker notifications and login page preferences
- **Payment History**: ISK donation tracking and ad-free time management
- **Activity Logs**: Personal activity and system interaction logs
- **Expanded Entity Metadata**: ESI-backed character, corporation, and alliance details such as founder, home station, member count, tax rate, war eligibility, and executor corporation
- **Missing Entity Fallback**: Public ESI data can provide a useful identity view when a character, corporation, or alliance is not yet available locally

---

## 🔍 Core Killmail System

### Killmail Processing & Sources
- **ESI Integration**: Killmail fetching from EVE's ESI API
- **Manual Posting**: Users can submit killmails via killmail hash or ESI URL
- **Corporate API**: Corporation-level killmail collection with appropriate scopes
- **War Killmails**: Automatic collection of war-related killmails
- **RedisQ Integration**: Killmail streaming system
- **Queue Processing**: Background processing system for incoming killmails
- **Validation System**: Automated verification against EVE's official data
- **Delayed Killmails**: Configurable delays (ASAP, 1h, 3h, 8h, 24h, 72h) for strategic operations

### Killmail Display & Analysis
- **Complete Breakdown**: Ship fitting, damage, attackers, victim details, items destroyed/dropped
- **Fitting Export**: Direct export to EVE Online (requires `esi-fittings.write_fittings.v1` scope)
- **Value Calculations**: ISK values using integrated market data
- **Damage Analysis**: Detailed damage breakdown by attacker and weapon type
- **Final Blow Detection**: Clear identification of killing blow participant
- **Points System**: Sophisticated point calculation based on ship size, meta levels, gang size, and engagement context
- **Classification System**: Solo, gang, awox, NPC, ganked, padding automatic labeling

### Killmail Enhancement Features
- **Auto-linking**: Automatic links to characters, corps, alliances, ships, systems
- **Comments System**: User-generated discussions with upvoting system and default meme comments
- **Reporting System**: Report invalid or problematic killmails for review
- **Favorites System**: Star killmails for personal collections
- **Killmail Sharing**: Social sharing and external links (ESI, EVE Ship Fit, EVE Workbench)
- **Related Killmails**: Battle report generation and related engagement detection
- **In-game Links**: Direct CREST/ESI links for in-game viewing
- **Killmail Export**: Export killmail data in various formats (DNA, EFT, ESI)
- **Social Media Sharing**: Share killmails to Facebook, Twitter/X, Reddit
- **Permalink Generation**: Permanent URLs for killmail sharing
- **External Tool Integration**: Direct links to fitting analyzers (EVE Ship Fit, EVE Workbench) and battle report tools
- **Fitting Visualization**: Interactive fitting wheel showing ship loadout with high/mid/low/rig/subsystem slots
- **Killmail Navigation**: Previous/next killmail browsing within search contexts
- **Final Blow & Top Damage**: Special recognition for key participants
- **ESI Fit Import**: One-click fitting import to game via ESI
- **In-game Link Generation**: Create shareable in-game links for killmails
- **Sponsorship System**: Allow users to sponsor killmails for promotion
- **ESI Verification**: Visual verification badges showing ESI data source

---

## 🔎 Advanced Search System

### Search Interface
- **Multi-parameter Queries**: Combine multiple search criteria simultaneously
- **Entity Search**: Characters, corporations, alliances, ships, systems, regions, factions
- **Time-based Filtering**: Specific date ranges, relative time periods, rolling windows
- **Value-based Filtering**: ISK value thresholds (1b+, 5b+, 10b+, 100b+, 1t+)
- **Ship/Item Filtering**: Ship types, groups, categories, specific modules
- **Label Filtering**: Solo, gang sizes, PvP/PvE, awox, ganked, NPC, padding, capital ships

### Advanced Search Features
- **Saved Searches**: Save and share complex search queries
- **Search Export**: Export search results to Excel/CSV formats
- **Quick Filters**: Pre-configured filters for common searches
- **Advanced Search Interface**: Dedicated advanced search page with complex filtering
- **Search History**: Access to recently performed searches
- **Solo/Gang Toggle**: Filter by engagement type
- **Kill/Loss Perspective**: Switch between kills made and losses taken
- **Security Level**: High-sec, low-sec, null-sec, wormhole, abyssal filtering
- **Location-based**: System, constellation, region, security band filtering
- **Item History Filtering**: Match killmails containing one or more selected item types with AND/OR controls
- **Queued Queries**: Long-running killmail and aggregation searches are completed by background workers while the interface reports queued status and retries automatically
- **Result Aggregations**: Summaries and top groups for characters, corporations, alliances, factions, ships, groups, locations, systems, constellations, regions, and labels
- **Flexible Sorting and Layouts**: Sort by date, ISK, involved count, damage, or points; switch between compact and wide aggregation layouts
- **Campaign Creation**: Convert a qualifying attacker-versus-defender search into a reusable campaign report
- **Inferred Fit Results**: With one ship filter selected, show inferred fits relevant to the current search criteria

### Autocomplete System
- **Search Suggestions**: Instant search suggestions for all entity types
- **Grouped Results**: Results organized by entity type (characters, corporations, etc.)
- **Single Result Redirect**: Automatic redirection for unique matches
- **Fuzzy Matching**: Intelligent matching for partial names and IDs
- **Ticker Matching**: Find corporations and alliances by ticker as well as by name
- **Multi-word Partial Matching**: Match spaced name fragments in order when a direct prefix match is unavailable
- **OpenSearch Integration**: Browser search bar integration
- **Keyboard Shortcuts**: Focus quick search with `/` or `^`, and open Advanced Search with `\`

---

## 🗺️ Intelligence & ScanAlyzer

### ScanAlyzer Tool
- **D-scan Analysis**: Parse directional scanner results for threat assessment
- **Local Chat Processing**: Analyze local chat member lists for intel gathering
- **Automatic Threat Assessment**: Danger evaluation based on kill/loss history
- **Recent Activity Display**: Quick lookup of recent kills/losses for detected pilots
- **Gang Analysis**: Fleet composition analysis from scan data
- **Clipboard Integration**: Direct paste functionality from EVE client
- **Kill/Loss Ratios**: Instant pilot assessment with danger indicators and snuggly percentage
- **Ship Identification**: Identify ships and potential threats from scans
- **Recent Ship Usage**: Display recently used ships by detected pilots

### Intelligence Systems
- **ScanAlyzer**: D-scan and local chat analysis tool with threat assessment
- **Supers & Titans Tracking**: Tracking of supercapital activities (last 3 months)
- **Character Intelligence**: Recent activity analysis, ship preferences, threat evaluation
- **Corporation Monitoring**: Member activities, recent losses, engagement patterns
- **Alliance Tracking**: Alliance-wide statistics and recent activities
- **Historical Tracking**: Long-term activity patterns and trend analysis
- **Pilot Threat Assessment**: Danger ratings, snuggly percentages, kill/loss analysis
- **Fleet Composition Analysis**: Gang participation metrics and average fleet sizes
- **Ship History Tracking**: Recent ships flown with detailed usage patterns
- **Clipboard Integration**: Direct paste from EVE client for scan analysis
- **Intelligence Indicators**: Threat assessment with visual indicators

---

## 🔧 Inferred Fits

### Fit Discovery
- **Observed Combat Fits**: Best-effort fits inferred from losses by pilots who previously earned kills in the same ship
- **Ship Search**: Browse leading inferred fits across ships or search for a specific ship type
- **Fit Rankings**: Rank fits by observed kills and show supporting final-blow and solo-kill counts
- **Freshness Details**: Display when the inference data was updated and how many killmails and days contributed
- **Advanced Search Integration**: Apply the current Advanced Search criteria to inferred-fit results when exactly one ship is selected

### Fit Inspection & Export
- **Fitting Wheel**: Inspect modules and slots in the familiar killmail fitting layout
- **EFT Export**: View and copy an EFT-format fitting
- **Sample Evidence**: Open the sample loss used to reconstruct the fit
- **Save to EVE**: Import the inferred fit through ESI when authenticated with fitting permissions
- **External Links**: Open the fit in EVE Ship Fit or EVE Workbench
- **Best-effort Warning**: Clearly notes that ammo, drones, cargo, implants, boosters, and refits may be incomplete or inferred

---

## 📊 Statistics & Rankings

### Statistics Engine
- **Kill/Death Ratios**: Comprehensive performance metrics per entity
- **ISK Efficiency**: ISK destroyed vs. ISK lost calculations
- **Activity Metrics**: Kills per time period, engagement patterns
- **Ship Usage Analytics**: Most used ships and weapon systems
- **Location Statistics**: Activity by system, constellation, region
- **Temporal Analysis**: Activity patterns by time zones (AU, EU, RU, USE, USW)
- **Group Statistics**: Ship group performance analytics

### Ranking Systems
- **Character Rankings**: Top killers for weekly, 90-day, and all-time periods
- **Corporation Rankings**: Corporate performance metrics and comparisons
- **Alliance Rankings**: Alliance-level statistics and standings
- **Ship Type Rankings**: Most effective ships and weapon systems
- **Solo vs. Gang Rankings**: Separate tracking for solo and gang activities
- **Regional Activity**: System and region activity levels
- **Top ISK Rankings**: Highest value kills and losses
- **Points-based Rankings**: Rankings based on sophisticated points calculation

### Activity Tracking
- **Last Hour Rankings**: Recent activity tracking
- **Recent Activity API**: API endpoints for recent activity data
- **Activity Heatmaps**: Visual representation of activity patterns
- **Timezone-based Stats**: Activity broken down by major timezones
- **Daily Analytics**: Per-entity kills and losses summarized by Ships, ISK, and Points across selectable days and date ranges
- **Interactive History Graphs**: Switch metrics and sides, select or zoom date ranges, and drill into individual days
- **Daily Breakdowns**: Top values, entity/ship/location lists, and combat-label distributions for the selected period
- **Live Ranking Tabs**: Recent and weekly statistics refresh along with live all-time overview data

### Badge & Recognition System  
- **Custom Badges**: External badge integration system for special recognition
- **Badge Display**: Visual badges shown on killmail pages and profiles  
- **Third-party Integration**: Support for external badge providers and services
- **Achievement Display**: Visual recognition for accomplishments and milestones
- **Trophy Leaderboard**: Site-wide ranking by completed trophy levels and overall completion percentage

---

## ⚔️ Battle Reports & War Tracking

### Battle Report System
- **Automatic Detection**: Identifies major engagements based on killmail clustering by time and location
- **Battle Reconstruction**: Complete timeline reconstruction of conflicts
- **Related Killmail System**: Groups killmails by proximity and time
- **Side Analysis**: Automatic determination of opposing forces
- **ISK Analysis**: Total losses and efficiency calculations for each side
- **Participant Lists**: All involved pilots, corporations, and alliances
- **Battle Saving**: Save battle reports with persistent IDs
- **Shareable URLs**: Persistent battle report links for sharing
- **Integration Links**: Links to external battle report tools (br.evetools.org, warbeacon.net, RIFT)

### War System
- **War Declaration Tracking**: Monitors official EVE Online wars
- **War Categories**: Recent open wars, mutual wars, recently declared, recently finished
- **War Statistics**: Performance tracking for aggressors and defenders
- **War Eligibility Tracking**: Corporations and alliances eligible for war declarations
- **War Killmail Association**: Links killmails to specific war contexts
- **Historical War Data**: Complete archive of war outcomes and statistics
- **Side-by-side War Reports**: Aggressor and defender comparison views with side swapping, top participants, and war killmail feeds
- **Precomputed War Tables**: Frequently viewed war listings are prepared in the background for faster page loads

### Campaign System (Beta)
- **Advanced Search Conversion**: Create a campaign from attacker and defender filters plus a custom start date
- **Public or Private Campaigns**: Publish campaigns in the public directory or share private campaigns directly by link
- **Configurable Duration**: Choose an end date up to one year from the start, with a one-year default when no end is supplied
- **Side Comparisons**: Compare ships killed, ISK, points, dropped value, destroyed value, and droppable value for attackers and defenders
- **Top Participants**: Separate top character, corporation, alliance, faction, ship, group, system, region, and location lists for each side
- **Campaign Killmail Feed**: Browse matching killmails and swap attacker/defender perspectives
- **Campaign Management**: Logged-in owners can view, modify, and delete their campaigns from the account area

---

## 💰 Monetization System

### ISK Payment System
- **Ad-Free Donations**: 5 million ISK per month for ad-free experience
- **Bulk Payment Bonuses**: Extra month for every 6 months purchased in advance
- **Automatic Processing**: Wallet monitoring and automatic credit application via cron jobs
- **Golden Wreck Icon**: Visual indicator for users with active ad-free time
- **Payment History**: Complete tracking of donations and ad-free periods
- **Patreon Integration**: Patreon subscriber verification and linking

### Killmail Sponsoring
- **Ad-Free Time Usage**: Convert ad-free time balance to sponsor killmails (1 month = 5M ISK sponsoring)
- **Sponsorship Duration**: 7-day sponsorship periods per killmail
- **Multiple Sponsorships**: Multiple users can sponsor the same killmail
- **Cumulative Tracking**: Total sponsorship amounts tracked per killmail
- **Live Detail Updates**: Sponsorship state refreshes on an open killmail without requiring a full page reload
- **Large Sponsorship Alerts**: Special notifications for 100M+ ISK sponsorships
- **Sponsored Killmails Page**: Dedicated page showing recently sponsored killmails

### Recognition System
- **Monocle Badge**: Permanent monocle icon for 1+ billion ISK total donations
- **Super Monocle Badge**: Gold super-monocle icon and portrait shine for 10+ billion ISK total donations
- **Configurable Portrait Shine**: Supporters can disable the shiny portrait effect in site settings
- **Visual Recognition**: Special icons for supporting users
- **Automatic Awards**: Cron job processing for monocle eligibility and EVE mail notifications

---

## 🏆 Trophies & Achievements System

### Comprehensive Trophy System
- **Achievement Tracking**: Extensive achievement system for characters
- **Trophy Categories**: General achievements, special accomplishments, ship-specific trophies
- **Level Progression**: 5-level trophy system with progress tracking
- **Completion Metrics**: Overall completion percentage and level counts
- **Visual Display**: Trophy level icons and progress indicators
- **Leaderboard**: Compare top trophy hunters by completed levels and overall completion percentage

### Achievement Types
- **General Trophies**: Solo kills, total kills, losses, security level achievements
- **Special Achievements**: 
  - CCP dev encounters (killing/being killed by CCP developers)
  - CONCORD kills (getting CONCORDed)
  - Tournament participation
  - Ganking achievements
  - Awoxing achievements
  - High-value kills
- **Ship Class Trophies**: Individual achievements for every ship group in the game
- **Location-based**: Achievements for different security levels, regions, and special locations (Pochven)
- **Interactive Links**: Direct links to relevant killmail pages for each trophy

---

## 👥 Social & Community Features

### Favorites System
- **Killmail Favorites**: Save interesting killmails for later viewing
- **Star Interface**: Click star icons to add/remove favorites
- **Personal Collection**: User-specific favorite killmail collections
- **Corporation Favorites**: Corporation CEOs can curate a shared corporation collection
- **Alliance Favorites**: Executor corporation CEOs can curate a shared alliance collection
- **Quick Access**: Dedicated favorites page for easy browsing

### Tracker System
- **Entity Tracking**: Track characters, corporations, alliances, ships, systems, regions, factions, groups
- **Navigation Integration**: Tracked entities appear in top navigation dropdown menu
- **Activity Notifications**: Optional notifications for tracked entity activity
- **Tracker Management**: Add/remove entities from tracker via entity pages
- **Auto-tracking**: Logged-in character and their corp/alliance automatically tracked
- **Tracker API**: Endpoints for tracker data access

### Comments & Community Interaction
- **Killmail Comments**: User-generated comments on killmails with voting system
- **Upvote System**: Community voting on comments for relevance
- **Default Comments**: Pre-populated meme comments for quick selection including "DUNKED", "memed", "good fight!", etc.
- **Discord Integration**: Links to Discord server for community discussion
- **Comment Moderation**: System for handling inappropriate comments

---

## 🎥 Streaming & Content Creation

### StreamBox Tool
- **Live Killmail Display**: Stream-friendly display of recent killmails
- **Automatic Updates**: Refreshes approximately every minute
- **Configurable Layout**: Horizontal or vertical layouts with customizable kill counts
- **OBS Integration**: Custom CSS support for overlay integration
- **Recent Activity**: Shows killmails from last 8 hours (up to 25 recent kills)
- **Value Formatting**: Locale-appropriate ISK value formatting
- **Responsive Design**: Adapts to different streaming setups
- **Kill/Loss Indicators**: Clear visual indicators for kills vs losses

### Content Creator Features
- **Social Media Integration**: Automatic posting of high-value kills to social platforms
- **Big Kill Notifications**: Special alerts for significant kills (10B+ ISK based on ship type)
- **Webhook Support**: Discord webhook integration for community notifications
- **Image Integration**: Ship renders and entity portraits for visual appeal
- **Twitter Integration**: Automated Twitter posting for significant kills
- **#tweetfleet Integration**: Automatic hashtag inclusion for EVE community

### Specialized Pages & Analytics
- **Big ISK Tracking**: Dedicated page for highest value kills by ship type  
- **Last Hour Statistics**: Top killers and losers across all security levels (nullsec, lowsec, highsec, w-space, solo)
- **Type Rankings**: Comprehensive ranking systems for characters, corporations, alliances, ships, groups with efficiency metrics
- **Manual Killmail Posting**: Submit external ESI killmail links for manual processing
- **Market Data Integration**: Historical pricing information display for items
- **War Declaration Tracking**: Monitor war eligibility and active conflicts
- **Sponsored Killmail System**: ISK-based killmail promotion with 7-day visibility periods

---

## 🔧 Technical Features & APIs

### RESTful API System
- **Killmail API**: Complete killmail data access
- **Statistics API**: Entity statistics and rankings
- **Prices API**: Item price data with JSONP support
- **History API**: Historical killmail data
- **Recent Activity API**: Activity tracking
- **Health Check API**: System health monitoring
- **Related Kills API**: Battle report data

### API Features
- **Rate Limiting**: Intelligent request throttling to prevent abuse
- **Caching System**: Multi-tier Redis caching for performance
- **CORS Support**: Cross-origin resource sharing for web applications
- **JSON Support**: Standard JSON data format for API responses
- **Error Handling**: Comprehensive error responses and logging
- **IP-based Limiting**: Protection against abuse and overuse
- **Full Killmail Responses**: Standard API results include the complete killmail body, with `zkbOnly` available for compact metadata-only responses
- **Delayed Killmail Posting**: API clients can submit a supported delay and receive structured queued-status details

### RedisQ Integration
- **Killmail Streaming**: Killmail streaming via Redis Queue
- **External Tool Support**: Integration for third-party applications
- **Webhook System**: Support for external webhook integrations
- **Queue Management**: Efficient queue processing and distribution

---

## 💻 Advanced Client Features

### JavaScript Functionality
- **WebSocket Integration**: Killmail feeds and notifications
- **Autocomplete Search**: Search suggestions with entity grouping
- **Keyboard Shortcuts**: Quick search (/) and advanced search (\) hotkeys
- **Clipboard Integration**: Copy raw values to clipboard on click
- **Progress Indicators**: Visual loading indicators for operations
- **AJAX Loading**: Dynamic content loading without page refresh
- **Responsive Updates**: UI updates via WebSocket
- **Cancelable Requests**: In-flight page and kill-list requests can be canceled when the user navigates or changes context

### Enhanced Navigation
- **Search Integration**: Integrated search with autocomplete across all entity types
- **Quick Navigation**: Keyboard shortcuts and fast navigation features
- **Mobile Touch**: Touch-optimized interface elements
- **Infinite Scroll**: Dynamic loading of additional content
- **Toast Notifications**: Non-intrusive user notifications
- **SPA-style Page Loads**: Eligible internal navigation replaces page content without a full reload while preserving browser history and scroll restoration
- **Graceful Fallback**: Failed or unsupported dynamic navigation falls back to a normal full-page load
- **Deployment Freshness**: Periodic version checks reload stale browser sessions after a site deployment

---

## 🎨 User Interface & Design

### Responsive Design
- **Mobile Optimization**: Complete mobile-friendly interface
- **Bootstrap Framework**: Responsive grid system for all devices
- **Touch Interface**: Mobile-optimized controls and navigation
- **Fast Loading**: Optimized assets and CDN integration
- **Adaptive Layout**: Interface adapts to screen size and device type

### Theme & Customization System
- **Multiple Themes**: Various Bootstrap themes (cyborg, and others)
- **Style Preferences**: User-configurable interface layouts
- **Navigation Options**: Customizable default pages and preferences
- **Personal Dashboards**: User-specific overview and activity pages
- **Dark Mode Support**: Dark theme options available

### Visual Features
- **Ship Renders**: High-quality ship images from EVE's image server
- **Entity Portraits**: Character, corporation, and alliance logos
- **Interactive Elements**: Hover effects, tooltips, and visual feedback
- **Color Coding**: Visual indicators for different kill types and values
- **Icon System**: Comprehensive icon set for various features

---

## 🔗 Integration & Third-Party Support

### External Service Integration
- **Patreon Integration**: Full OAuth integration for Patreon subscriber verification
- **EVE Mail System**: Automated EVE mail sending for notifications and achievements
- **Market Data**: Market price integration for killmail values
- **Image Services**: Integration with EVE's image servers for renders and portraits

### Third-Party Tool Integration
- **EVE Ship Fit**: Direct links to fitting analysis
- **EVE Workbench**: Fitting comparison and analysis
- **br.evetools.org**: External battle report integration
- **warbeacon.net**: Alternative battle report viewing
- **RIFT Integration**: Support for RIFT intel tool

### Developer Support
- **API Documentation**: Comprehensive API documentation
- **GitHub Repository**: Open source code and contribution guidelines
- **RedisQ Documentation**: Killmail streaming documentation
- **Webhook Examples**: Sample implementations for webhooks

---

## 🛠️ Background Processing & Automation

### Extensive Cron Job System
- **Killmail Processing**: Background processing of incoming killmails
- **Statistics Updates**: Regular recalculation of statistics and rankings
- **Data Synchronization**: ESI data fetching and updates
- **Social Media Automation**: Automated posting of significant kills
- **Payment Processing**: ISK payment detection and processing
- **Health Monitoring**: System health checks and status updates

### Automated Detection Systems
- **Ganked Detection**: Automatic identification of suicide ganked killmails
- **Padding Detection**: Detection and marking of padded killmails for stat exclusion
- **Awox Detection**: Automatic detection of friendly fire incidents
- **NPC Classification**: Proper classification of NPC-only killmails
- **War Association**: Automatic linking of killmails to wars

### Data Maintenance
- **Cache Management**: Intelligent cache invalidation and refresh
- **Database Optimization**: Regular database maintenance and optimization
- **Error Recovery**: Automatic error detection and recovery systems
- **Data Integrity**: Regular validation and cleanup of data

---

## 📱 Additional Features

### Map Integration
- **Interactive Map**: Dedicated map interface for activity visualization
- **System Activity**: Visual representation of system-level activity
- **Regional Data**: Region-based activity and statistics

### Information & Documentation
- **Comprehensive FAQ**: Detailed answers to common questions covering all aspects
- **About Pages**: Information about zKillboard and its development
- **Payment Information**: Detailed payment instructions and policies
- **Legal Information**: Terms, privacy, and legal documentation
- **Delayed Killmails**: Information about strategic delay options

### Export & Data Features
- **CSV Export**: Export search results and data to CSV format
- **Excel Export**: Spreadsheet-compatible data exports
- **Sitemap**: Complete sitemap for search engine optimization

### Health & Monitoring
- **System Status**: System health monitoring
- **Performance Metrics**: Detailed performance tracking
- **Error Logging**: Comprehensive error tracking and reporting
- **Uptime Monitoring**: System availability tracking

---

## 🌐 Community & Support

### Community Resources
- **Discord Community**: Active Discord server for support and discussion
- **Community Guidelines**: Clear community standards and moderation
- **User Support**: Help and support for users

### Content & Information
- **Regular Updates**: Continuous feature development and improvements
- **Community Feedback**: Active incorporation of community suggestions
- **Bug Reporting**: Systems for reporting and tracking issues
- **Feature Requests**: Community-driven feature development

---

*This vibed yet exhaustive documentation captures features implemented in zKillboard based on comprehensive source code analysis. zKillboard is one of the most sophisticated and feature-rich applications in the EVE Online ecosystem, serving as the primary killboard, intelligence platform, and community hub for EVE Online players worldwide.*

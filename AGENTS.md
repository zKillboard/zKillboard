# General
- KISS
- YAGNI

# Git
- Read-only git commands are okay; otherwise, do not use git commands unless explicitly instructed.

# Coding, Design (UI/UX)
- Keep code simple and concise.
- Reuse existing code as much as possible without modifying it. If modification is necessary, keep it simple.
- Don't create helper functions unless the code is actually reused.
- Don't create const-like definitions that are used in only one place.
- Prefer small local simplifications when already touching code; avoid broad refactors unless requested.
- When creating or modifying existing `cron/*` scripts, don't create new `classes/*.php` files unless the data is used by more than one cron file.

# CSS
- Presentation should use as little custom CSS as possible, relying instead on BS5 classes.

# Style
- Follow the surrounding file's style for spacing, naming, and control flow.
- Avoid reformatting unrelated code.

# Dependencies
- Do not add new Composer, npm, or system dependencies unless explicitly requested.

# Verification
- Run focused syntax checks or tests for changed files when practical.
- For PHP files, prefer `php -l path/to/file.php` as a quick syntax check.
- Do not run broad or slow test suites unless requested or clearly necessary.

# Safety
- Avoid changes that can increase cron load, database load, or external API calls without calling them out.
- Keep cron scripts idempotent where practical.
- Avoid adding network calls inside hot paths unless requested.

# Databases
- Local mongod is running; refer to config.php for the connection string.
## Allowed
- Read-only database commands.
- Creating or modifying indexes is allowed only when the change is added to /setup/addIndexes.php.
## Disallowed
- Commands that modify data. Creating indexes is okay; see above.

# Website
- localhost is running the local version of the application.
- Do not spin up extra web servers; use localhost.

# Misc
- cron is executing cron.sh every minute, and therefore the cron PHP files with it.
- Local redis is running.

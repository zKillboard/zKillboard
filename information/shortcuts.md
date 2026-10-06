# Keyboard shortcuts

zKillboard provides keyboard shortcuts for navigation and common page actions. Press `?` anywhere outside a form field to see the shortcuts available on the current page.

Shortcuts do not fire while typing in an input, textarea, select, or editable element unless the shortcut explicitly uses a modifier key. Most shortcuts are also paused while a modal is open.

## Global shortcuts

| Shortcut | Action |
| --- | --- |
| `/` or `^` | Focus the main search box |
| `\` | Open Advanced Search |
| `?` | Open keyboard shortcut help |
| `r` | Refresh the current page |
| `Esc` | Close or cancel the active UI and remove focus from the search box |

The help dialog includes an **Enable keyboard shortcuts** switch. The setting is stored in the browser. The `?` shortcut remains available when other shortcuts are disabled so they can be turned back on.

## Go to a page

Press `g`, then press one of the following keys within four seconds. A toast displays the available destinations while zKillboard waits for the second key. Press `Esc` to cancel.

| Shortcut | Destination |
| --- | --- |
| `g`, then `h` | Home |
| `g`, then `c` | Your character |
| `g`, then `o` | Your corporation |
| `g`, then `l` | Your alliance |
| `g`, then `a` | Advanced Search |
| `g`, then `f` | Inferred Fits |
| `g`, then `s` | Fitting Simulator |
| `g`, then `w` | Wars |
| `g`, then `r` | Top Ranks |
| `g`, then `m` | Universe map in a new tab |
| `g`, then `p` | Post Killmail |

Character, corporation, and alliance destinations require a logged-in character and the corresponding EVE entity.

## Kill lists and results

These shortcuts work on pages containing killmail lists, including Advanced Search results, favorites, wars, campaigns, and related-kill reports.

| Shortcut | Action |
| --- | --- |
| `j` | Select the next killmail |
| `k` | Select the previous killmail |
| `Home` | Select the first visible killmail after a row has been selected |
| `End` | Select the last visible killmail after a row has been selected |
| `Enter` | Open the selected killmail |
| `o` | Open the selected killmail in a new tab |
| `c` | Copy the selected killmail link |
| `f` | Favorite or unfavorite the selected killmail |
| `n` | Open the next results page |
| `p` | Open the previous results page |

Favoriting requires a logged-in character. Page navigation only activates when the relevant results page exists.

## Killmail pages

| Shortcut | Action |
| --- | --- |
| `←` | Open the previous killmail |
| `→` | Open the next killmail |
| `f` | Favorite or unfavorite the killmail |
| `c` | Copy the killmail URL |
| `e` | Open the Export menu |
| `s` | Save the inferred fit |
| `b` | Open the related battle report |
| `x` | Open the linked related loss, such as a capsule |

Actions only activate when the corresponding control is available on the killmail.

## Advanced Search

| Shortcut | Action |
| --- | --- |
| `Ctrl/Cmd+Enter` | Run the search while focused in a search field |
| `Alt+R` | Reset the search after confirmation |
| `Alt+S` | Save the search |
| `Alt+E` | Export the search |
| `Alt+F` | Focus the entity filter field |

Saving a search requires a logged-in character.

## Fitting Simulator

| Shortcut | Action |
| --- | --- |
| `Ctrl/Cmd+Z` | Undo |
| `Ctrl/Cmd+R` | Redo |
| `Ctrl/Cmd+Shift+Z` | Redo |
| `Ctrl/Cmd+S` | Save the current fit |
| `Ctrl/Cmd+E` | Open the EFT import/export dialog |
| `Ctrl/Cmd+Shift+C` | Copy the current EFT fit |
| `Alt+N` | Start a new fit using the selected ship |
| `Alt+1` | Switch to the Ship tab |
| `Alt+2` | Switch to the Capsule tab |
| `Alt+F` | Focus equipment or implant search for the active tab |

Undo and redo retain the normal text-editing behavior when a form field has focus. Simulator shortcuts do not run while a modal is open.

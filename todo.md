# Bootstrap 3 Leftovers Migration TODO

## Priority 1: Navbar Cluster
- [ ] Migrate `templates/item.html` navbar from BS3 (`navbar-default`, `nav navbar-nav`) to BS5 nav structure.
- [ ] Migrate `templates/lasthour.html` navbar from BS3 to BS5.
- [ ] Clean up mixed navbar classes in `templates/detail.html` (`navbar-default` + legacy `pull-*` usage).
- [ ] Remove/replace legacy navbar helper rules in `public/css/main.css` (`.navbar-header`, `.navbar-toggle`, old pull-right nav selectors under details menu where obsolete).

## Priority 2: Glyphicon Cluster
- [ ] Replace glyphicons in `templates/components/stats_box.html` with Font Awesome equivalents.
- [ ] Replace glyphicons in `templates/scanalyzer.html` (table header icons).
- [ ] Replace glyphicons in `templates/base.html` (`glyphicon-question-sign` tooltip icon).
- [ ] Replace glyphicons in `templates/components/kill_list_row.html` (`glyphicon-ok` / `glyphicon-remove`).
- [ ] Replace glyphicons in `templates/components/trophies.html` (`glyphicon-arrow-right`).
- [ ] Replace glyphicons in `templates/components/info_top.html` (`glyphicon-bullhorn`).

## Priority 3: Panel/Well Cluster
- [ ] Migrate `templates/account/components/campaign_team.html` from `panel panel-default` to BS5 card.
- [ ] Replace `well` in `templates/merge.html` with BS5 card/alert container.
- [ ] Replace `well` in `templates/postmail.html` with BS5 card/alert container.
- [ ] Replace `well well-small` in `templates/components/involved_summary.html` with BS5 equivalent.
- [ ] Replace `well well-sm` notices in `templates/components/stats_box.html` with BS5 card/alert styling.

## Priority 4: Grid/Offset Leftovers
- [ ] Replace `col-xs-*` classes in `templates/scanalyzer.html`.
- [ ] Replace `col-xs-*` class in `templates/components/big_top_list.html`.
- [ ] Replace `col-xs-*` classes in `templates/components/info_top.html`.
- [ ] Replace `col-lg-offset-*` classes in `templates/merge.html`.

## Priority 5: Utility/Button Leftovers
- [ ] Replace `pull-left` / `pull-right` with `float-start` / `float-end` (or flex utilities) across templates.
- [ ] Replace `btn-default` uses (notably `templates/components/fitting_wheel.html` and `templates/components/item_list.html`) with BS5 button variants.
- [ ] Replace `btn-block` with BS5 `d-grid`/`w-100` patterns (`templates/index.html`, `templates/detail.html`, `templates/overview.html`, `templates/scanalyzer.html`).
- [ ] Replace remaining `data-dismiss` attributes with BS5 `data-bs-dismiss` (`templates/account.html`, `templates/detail.html`).

## Explicit Page Follow-up
- [ ] Scanalyzer full BS3->BS5 pass (grid, glyphicons, old button utilities, text alignment classes).

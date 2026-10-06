# Smart Gateway v22.1 — Responsive UI, Settings Launcher, KPI Icons & Formal Reports

## Changes

- Fixed the application shell width calculation that could create horizontal overflow on desktop: content now uses `calc(100% - 208px)` beside the fixed sidebar and `100%` below the tablet breakpoint.
- Added additional cross-device width guards for rows, columns, cards, forms, and main content.
- Redesigned Settings category buttons:
  - compact maximum width on desktop
  - clearer icon treatment
  - strong active-state border/highlight
  - check indicator for the selected category
  - responsive 3-column/2-column layouts
- Kept the category launcher directly below Account Settings and before the selected settings panel.
- Merged Kiosk Station management into the Kiosk Access category so the existing kiosk station controls remain reachable without adding another oversized category tile.
- Replaced Analytics KPI font icons with inline SVG icons so the KPI icons no longer depend on icon-font glyph rendering.
- Improved KPI icon sizing, alignment, and contrast on desktop and mobile.
- Added viewport-safe profile dropdown sizing/position constraints. Note: the supplied codebase contains a profile/user dropdown, not an SEO dropdown; the profile dropdown was treated as the requested dropdown visible in the supplied screenshot.
- Reworked print/PDF styling into a formal business-report presentation:
  - A4 paper with tighter margins to maximize usable paper
  - no dashboard-style rounded containers/boxes in print
  - formal report header and section rules
  - cleaner KPI presentation
  - tables and charts remain readable without UI card shells
  - print-only styling does not change the normal on-screen report layout

## Validation

- `php -l admin/analytics.php` — passed
- `php -l admin/settings.php` — passed
- Existing pagination arrow-only behavior retained.

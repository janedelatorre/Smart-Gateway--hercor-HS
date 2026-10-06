# Smart Gateway UI V2

## Applied changes
- Reworked the public homepage/portal-selection screen to follow the supplied Hercor College reference: deep navy background, cyan accents, glass panel, circular school logo, two portal cards, and responsive mobile behavior.
- Reworked the administrator/staff sign-in screen to use the same visual system and spacing.
- Added a shared dark navy/cyan visual language to the application pages through the main stylesheet.
- Updated the shared navbar so non-dashboard pages use the same title/profile treatment as the dashboard while preserving notifications.
- Kept the existing sidebar structure and functionality, but aligned its colors with the homepage.
- Removed the decorative analytics/trend icons from all four administrator dashboard KPI cards.
- Kept functional icons inside KPI cards and other pages; only the decorative KPI analytics icons were removed.
- Added responsive breakpoints for desktop, tablet, and mobile layouts.

## Theme decision
The application now uses the homepage's dark theme as the primary system theme rather than maintaining separate light/dark modes. This keeps the UI visually consistent and avoids having the dashboard and internal pages feel like different products.

A light theme/toggle can be added later if user testing shows a real accessibility or preference need.

## Functional scope
No authentication, database, scanner, reporting, notification, or CRUD logic was intentionally changed as part of this UI pass.

- Added Hercor College campus courtyard photo as the homepage/authentication background using a dark cyan overlay for readability.

## UI V3 — Unified Hercor College dark/cyan visual system

- Reworked the public portal-selection homepage to closely match the supplied Smart Gateway reference: campus image, dark overlay, large cyan orbit, Hercor branding, hero typography, feature trio, role cards and footer treatment.
- Unified shared application shell at a 208px sidebar / 59px topbar proportion to match the supplied internal-page screenshots.
- Standardized dark navy surfaces, cyan borders, compact spacing, typography, buttons, filters, tables, badges, dropdowns and modals across internal pages.
- Kept the dashboard KPI analytics/trend decorations removed.
- Improved responsive behavior for tablet and mobile widths.
- Kept application/database/authentication logic unchanged in this UI pass.

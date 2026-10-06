# V18 UI Visibility & Responsive Frontend Pass

## Implemented
- Improved dark/light mode text contrast and hover/focus visibility.
- Kept dashboard user profile/avatar visible while making the topbar responsive.
- Added responsive title/action behavior for the header.
- Improved hamburger menu with backdrop, body-scroll lock, Escape-to-close, link-close, resize reset, and ARIA state updates.
- Changed General Settings from a stretched full-height card to content-sized layout.
- Changed Profile summary, Personal Information, and Account Details cards to content-sized auto-fit layout.
- Forced dark-mode date-picker calendar indicators/icons to white; restored native indicator behavior in light mode.
- Fixed Reports KPI text contrast in light mode.
- Reduced Reports chart heights and doughnut chart footprint.
- Improved Chart.js axis/legend contrast for the initial active theme.
- Refined role-selection and credentials login UI to more closely match the supplied references: centered rounded shell, dark navy background, glass panel, cyan accents, stronger role-card hover states, and responsive mobile fallback.

## Files changed
- `assets/css/style.css`
- `assets/js/app.js`
- `admin/settings.php`
- `admin/profile.php`
- `admin/reports.php`

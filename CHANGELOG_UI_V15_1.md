# Smart Gateway V15.1 UI Fixes

## Dashboard
- Added the Light/Dark theme toggle to the System Administrator dashboard beside the notification bell.
- Kept the same toggle on Staff Dashboard, Scan Entry, and shared navbar pages.
- Added stronger Light Mode contrast for dashboard cards, labels, tables, activity, profile controls, and other custom components.

## Settings / Account
- Added Account Settings shortcuts for **My Profile** and **Change Password**.
- Removed the **Auto Backup** switch from Settings and removed its settings form handling/seed entry.

## Reports
- Fixed report charts by initializing Chart.js only after the page DOM is ready and Chart.js has loaded.
- Strengthened chart container sizing so the Hourly Overview and Verification Breakdown remain visible.
- Kept **Export as PDF** and **Print** as the top actions.
- Date fields now show **Start Date** and **End Date** labels.
- Dark-mode calendar picker indicators are forced to a high-contrast white appearance.

## User Management
- Add User password field now explicitly enforces a minimum of 6 characters in the browser.

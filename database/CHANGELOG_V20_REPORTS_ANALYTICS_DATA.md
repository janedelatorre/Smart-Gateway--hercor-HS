# Smart Gateway V20 — Reports, Analytics & Backup Data Integration

## Reporting improvements
- Reworked Reports into a professional, print-oriented campus access report.
- Added executive summary with period, grade filter, generated time, and prepared-by information.
- Added KPI summary for total transactions, granted, denied, unique students, active students, and SMS sent.
- Added daily activity trend, hourly activity, verification method, and entry/exit breakdowns.
- Added activity-by-grade, denial reasons, most active students, and daily summary tables.
- Separated **Print** and **Save as PDF** controls. Both use the browser print engine, but Save as PDF sets a report-specific document filename before opening the print dialog.
- Added A4 print styling, clean white report pages, compact charts, print-only formatting, and confidential report footer.

## Analytics improvements
- Added grant/denial rates.
- Added average scans per day and scans per unique student.
- Added peak day and peak granted-scan hour indicators.
- Added activity-by-grade and denial-reason tables.
- Added most-active-student table and operational snapshot.
- Retained date-range filtering and responsive Chart.js visualizations.

## Backup data integration
The supplied `backup data.sql` was inspected and integrated as a safe database migration:
- 3 users
- 7 students
- 208 entry logs
- 60 SMS log rows
- 61 login attempts
- 1 notification
- 2 OTP rows
- 1 password-history row
- 257 verification-attempt rows
- 22 settings
- Kiosk-session and audit-log history from the backup are also included.

`database/migration_import_backup_data.sql` performs a safe merge:
- users, students, and settings are upserted using their natural unique keys;
- historical log rows use `INSERT IGNORE` so an existing primary-key row is not overwritten;
- auto-increment values are raised above the imported IDs.

`database/smart database_with_backup_data.sql` contains the normal V20 schema/seed followed by the backup-data migration, so it can be used as a single database setup file.

### Important backup-file limitation
The SQL dump contains file-path references for profile/student photos, but SQL does not contain the actual image binaries. The migration therefore restores those database paths and face-encoding values, but the referenced image files must also exist under `uploads/` if the photos are to display.

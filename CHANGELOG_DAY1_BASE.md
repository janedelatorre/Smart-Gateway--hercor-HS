# Smart Gateway — Dashboard & Database Consistency Update

## Changes in this version

### Dashboard / branding
- Dashboard visual styling retained and tuned to the supplied Hercor College reference.
- Replaced the previous school logo with the supplied Hercor College High School Department logo.
- Added a transparent shield-only logo variant for the compact sidebar.
- Full supplied logo is used on the login/role-selection/password-recovery screens.

### Database consistency
- Canonical MySQL database/schema name is now: `smartgatewayproject_dev`.
- Local XAMPP configuration in `database/config.php` now uses `smartgatewayproject_dev`.
- SQL schema now creates/uses `smartgatewayproject_dev`.
- The SQL file is named exactly: `database/smart database.sql`.
- Migration documentation now references `smartgatewayproject_dev`.
- README installation references were updated to `smart database.sql`.

## Important distinction
The **SQL file name** (`smart database.sql`) and the **MySQL database/schema name** (`smartgatewayproject_dev`) are intentionally different. The application connects to the schema name, not the filename.

## Validation
- All PHP files pass `php -l` syntax validation.
- No remaining references to the old database names `smart_gateway_v1_8` or `smart_gateway_v1_12` remain in the project.

-- Smart Gateway V21 kiosk access permission setting
INSERT INTO settings (setting_key, setting_value)
SELECT 'kiosk_manager_user_ids', '[]'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key='kiosk_manager_user_ids');

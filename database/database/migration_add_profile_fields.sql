-- Smart Gateway v15 development profile fields
-- Run against an existing smartgatewayproject_dev database if the users table already exists.
ALTER TABLE users
    ADD COLUMN first_name VARCHAR(50) DEFAULT NULL AFTER fullname,
    ADD COLUMN last_name VARCHAR(50) DEFAULT NULL AFTER first_name,
    ADD COLUMN staff_id VARCHAR(50) DEFAULT NULL UNIQUE AFTER last_name,
    ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL AFTER staff_id;

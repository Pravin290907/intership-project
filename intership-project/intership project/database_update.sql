-- Database Migration Script for Forgot Password Feature
-- Campus Recruitment System

ALTER TABLE `users`
ADD COLUMN `reset_token` VARCHAR(255) NULL,
ADD COLUMN `reset_token_expiry` DATETIME NULL;

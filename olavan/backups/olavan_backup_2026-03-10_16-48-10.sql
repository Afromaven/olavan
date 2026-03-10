-- Olavan Database Backup
-- Date: 2026-03-10 16:48:10

TRUNCATE TABLE `users`;
INSERT INTO `users` (`id`, `phone_number`, `full_name`, `country`, `password_hash`, `profile_image`, `is_admin`, `status`, `language`, `terms_accepted`, `terms_accepted_at`, `expiry_reminder_sent`, `created_at`, `last_login`) VALUES ('1'', ''+2576200332'', '''', ''Burundi'', ''$2y$10$UVz7LJQGWmnMPCIffu15rukDDWI1d6lF2VsdkxCLYCeAYeqWjBs9C'', ''uploads/images/default.jpg'', ''0'', ''Pending verification'', ''sw'', ''0'', '''', ''0'', ''2026-03-10 04:57:25'', ''');
INSERT INTO `users` (`id`, `phone_number`, `full_name`, `country`, `password_hash`, `profile_image`, `is_admin`, `status`, `language`, `terms_accepted`, `terms_accepted_at`, `expiry_reminder_sent`, `created_at`, `last_login`) VALUES ('2'', ''+25768661170'', '''', ''Burundi'', ''$2y$10$ch5Bdd.G8ZcRZQNydVmCFurU89ttHXGzoY7.RoGK8075UrAzGc7fK'', ''uploads/images/admin_2_1773157518.jpg'', ''1'', ''Pending verification'', ''sw'', ''0'', '''', ''0'', ''2026-03-10 15:39:09'', ''');

TRUNCATE TABLE `subscriptions`;
INSERT INTO `subscriptions` (`id`, `user_id`, `payment_date`, `months_paid`, `payment_method`, `payment_phone`, `transaction_id`, `amount_paid`, `end_date`, `proof_url`, `status`, `reviewed_by`, `reviewed_at`, `admin_notes`, `reminder_sent`, `created_at`) VALUES ('1'', ''1'', ''2026-03-10'', ''3'', ''Mobile Money'', ''+25732500600'', ''5566321'', ''10000.00'', ''2026-06-10'', ''uploads/proofs/1773111567_1_bb5c5e32be6746c2.jpg'', ''Pending verification'', '''', '''', '''', ''0'', ''2026-03-10 04:59:27');

TRUNCATE TABLE `user_activity_log`;
INSERT INTO `user_activity_log` (`id`, `user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES ('1'', ''1'', ''payment_upload'', ''Uploaded proof for 3 months'', ''::1'', ''2026-03-10 04:59:27');


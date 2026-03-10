-- Olavan Database Backup
-- Date: 2026-03-10 19:49:43
-- Host: localhost
-- Database: olavan

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


-- --------------------------------------------------------

-- Table structure for table `users`

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone_number` varchar(20) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `country` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `profile_image` varchar(255) DEFAULT 'uploads/images/default.jpg',
  `is_admin` tinyint(1) DEFAULT 0,
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `expiry_reminder_sent` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone_number` (`phone_number`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `users`

INSERT INTO `users` (`id`, `phone_number`, `full_name`, `country`, `password_hash`, `profile_image`, `is_admin`, `status`, `expiry_reminder_sent`, `created_at`) VALUES ('1', '+2576200332', 'INEZA Lolo', 'Burundi', '$2y$10$UVz7LJQGWmnMPCIffu15rukDDWI1d6lF2VsdkxCLYCeAYeqWjBs9C', 'uploads/images/default.jpg', '0', '', '0', '2026-03-10 04:57:25');
INSERT INTO `users` (`id`, `phone_number`, `full_name`, `country`, `password_hash`, `profile_image`, `is_admin`, `status`, `expiry_reminder_sent`, `created_at`) VALUES ('2', '+25768661170', 'Admi  Olavan', 'Burundi', '$2y$10$ch5Bdd.G8ZcRZQNydVmCFurU89ttHXGzoY7.RoGK8075UrAzGc7fK', 'uploads/images/admin_2_1773157518.jpg', '1', '', '0', '2026-03-10 15:39:09');
INSERT INTO `users` (`id`, `phone_number`, `full_name`, `country`, `password_hash`, `profile_image`, `is_admin`, `status`, `expiry_reminder_sent`, `created_at`) VALUES ('3', '+25771580596', 'KEVO Kab', 'Burundi', '$2y$10$WaaL6QSP3n8AchlG9.MoXutNsauzO1iJaGmU3Kc.KpULRx4bKrZi.', 'uploads/images/default.jpg', '1', 'pending', '0', '2026-03-10 20:02:41');


-- --------------------------------------------------------

-- Table structure for table `payments`

CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `months_paid` int(11) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_phone` varchar(20) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `end_date` date NOT NULL,
  `proof_url` varchar(255) DEFAULT NULL,
  `status` enum('pending','completed','unpayed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_payment_phone` (`payment_phone`),
  KEY `idx_transaction` (`transaction_id`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `payments`

INSERT INTO `payments` (`id`, `user_id`, `payment_date`, `months_paid`, `payment_method`, `payment_phone`, `transaction_id`, `amount_paid`, `end_date`, `proof_url`, `status`, `created_at`) VALUES ('1', '1', '2026-03-10', '3', 'Mobile Money', '+25732500600', '5566321', '10000.00', '2026-06-10', 'uploads/proofs/1773111567_1.jpg', 'completed', '2026-03-10 04:59:27');
INSERT INTO `payments` (`id`, `user_id`, `payment_date`, `months_paid`, `payment_method`, `payment_phone`, `transaction_id`, `amount_paid`, `end_date`, `proof_url`, `status`, `created_at`) VALUES ('2', '1', '2026-03-10', '2', 'Mobile Money', '+25732500600', '5566321', '10000.00', '2026-05-10', 'uploads/proofs/1773162350_1.jpg', 'pending', '2026-03-10 19:05:50');
INSERT INTO `payments` (`id`, `user_id`, `payment_date`, `months_paid`, `payment_method`, `payment_phone`, `transaction_id`, `amount_paid`, `end_date`, `proof_url`, `status`, `created_at`) VALUES ('3', '1', '2026-03-10', '6', 'Mobile Money', '+25732500600', '9794656989', '240000.00', '2026-09-10', 'uploads/proofs/1773164932_1.jpg', 'pending', '2026-03-10 19:48:52');


-- --------------------------------------------------------

-- Table structure for table `notifications`

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('payment_approved','payment_rejected','expiry_soon','expired','welcome') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `notifications`

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `is_read`, `created_at`) VALUES ('1', '1', 'payment_approved', '✅ Payment Approved', 'Your payment of 10,000.00 has been approved.', '0', '2026-03-10 20:30:35');


-- --------------------------------------------------------

-- Table structure for table `logs`

CREATE TABLE `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_activity` (`user_id`,`created_at`),
  CONSTRAINT `logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `logs`

INSERT INTO `logs` (`id`, `user_id`, `action`, `details`, `created_at`) VALUES ('1', '1', 'payment_upload', 'Uploaded proof for 3 months', '2026-03-10 04:59:27');
INSERT INTO `logs` (`id`, `user_id`, `action`, `details`, `created_at`) VALUES ('2', '1', 'payment_upload', 'Uploaded proof for 2 months', '2026-03-10 19:05:50');
INSERT INTO `logs` (`id`, `user_id`, `action`, `details`, `created_at`) VALUES ('3', '1', 'payment_upload', 'Uploaded proof for 6 months', '2026-03-10 19:48:52');
INSERT INTO `logs` (`id`, `user_id`, `action`, `details`, `created_at`) VALUES ('4', '1', 'payment_approved', 'Payment approved by admin', '2026-03-10 20:30:35');


/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
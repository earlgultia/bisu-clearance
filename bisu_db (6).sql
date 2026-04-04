-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 30, 2026 at 10:31 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bisu_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL,
  `users_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`log_id`, `users_id`, `action`, `description`, `ip_address`, `created_at`) VALUES
(1, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-03-01 14:59:53'),
(2, 3, 'LOGOUT', 'User logged out: Super Admin', '::1', '2026-03-01 15:11:05'),
(3, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-03-01 15:11:09'),
(4, 3, 'LOGOUT', 'User logged out: Super Admin', '::1', '2026-03-01 15:11:25'),
(5, NULL, 'LOGIN', 'User logged in successfully', '::1', '2026-03-01 15:11:56'),
(6, NULL, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-01 15:17:09'),
(7, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-03-01 15:18:06'),
(8, NULL, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-08 13:55:13'),
(9, NULL, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-08 14:29:42'),
(10, 3, 'LOGIN', 'User logged in successfully: Super Admin', '::1', '2026-03-08 14:29:50'),
(11, 3, 'LOGOUT', 'User logged out: Super Admin', '::1', '2026-03-08 14:41:35'),
(12, 3, 'LOGIN', 'User logged in successfully: Super Admin', '::1', '2026-03-08 14:43:59'),
(13, 3, 'ADD_USER', 'Created new user: librarian.candijay@bisu.edu.ph with role: sub_admin', '::1', '2026-03-08 14:44:53'),
(14, 3, 'LOGOUT', 'User logged out: Super Admin', '::1', '2026-03-08 14:44:57'),
(15, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-08 14:45:02'),
(16, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-08 15:00:25'),
(17, NULL, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-08 15:00:30'),
(18, NULL, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-08 15:00:39'),
(19, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-08 15:01:18'),
(20, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-08 15:14:34'),
(21, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-08 15:14:40'),
(22, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-08 15:15:06'),
(23, 3, 'LOGIN', 'User logged in successfully: Super Admin', '::1', '2026-03-08 15:15:27'),
(24, 3, 'ADD_USER', 'Created new user: dean.candijay@bisu.edu.ph with role: sub_admin', '::1', '2026-03-08 15:17:56'),
(25, 3, 'LOGOUT', 'User logged out: Super Admin', '::1', '2026-03-08 15:18:10'),
(26, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-08 15:18:14'),
(27, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-08 15:29:57'),
(28, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-08 15:29:59'),
(29, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-08 15:30:21'),
(30, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-08 15:40:16'),
(31, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-08 15:48:28'),
(32, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-08 15:48:32'),
(33, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-08 16:15:41'),
(34, NULL, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-08 16:15:49'),
(35, NULL, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-08 16:16:12'),
(36, 3, 'LOGIN', 'User logged in successfully: Super Admin', '::1', '2026-03-08 16:16:32'),
(37, 3, 'ADD_USER', 'Created new user: cashier.candijay@bisu.edu.ph with role: sub_admin', '::1', '2026-03-08 16:17:52'),
(38, 3, 'LOGOUT', 'User logged out: Super Admin', '::1', '2026-03-08 16:17:57'),
(39, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '::1', '2026-03-08 16:20:33'),
(40, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '::1', '2026-03-08 16:30:29'),
(41, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-08 16:30:38'),
(42, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-08 16:30:45'),
(43, 3, 'LOGIN', 'User logged in successfully: Super Admin', '::1', '2026-03-08 16:30:56'),
(44, 3, 'LOGOUT', 'User logged out: Super Admin', '::1', '2026-03-08 16:31:11'),
(45, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '::1', '2026-03-08 16:31:18'),
(46, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '::1', '2026-03-08 16:42:35'),
(47, NULL, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-09 05:29:52'),
(48, NULL, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-09 05:30:39'),
(49, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-09 07:19:20'),
(50, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-09 07:22:28'),
(51, NULL, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-09 07:22:34'),
(52, NULL, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-09 07:22:56'),
(53, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-09 07:23:02'),
(54, 11, 'PROCESS_CLEARANCE', 'Clearance ID 10 approve with remarks: Kuwang kag kaon', '::1', '2026-03-09 07:23:13'),
(55, 11, 'PROCESS_CLEARANCE', 'Clearance ID 10 approve with remarks: Kuwang kag kaon', '::1', '2026-03-09 07:23:24'),
(56, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-09 07:23:58'),
(57, NULL, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-09 07:24:06'),
(58, NULL, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-09 07:33:38'),
(59, NULL, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-15 13:24:50'),
(60, NULL, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-15 13:24:56'),
(61, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-15 13:26:05'),
(62, 6, 'DELETE_ORGANIZATION', 'Deleted organization: Sample Town Organization', '::1', '2026-03-15 13:26:34'),
(63, 6, 'DELETE_ORGANIZATION', 'Deleted organization: Sample College Organization', '::1', '2026-03-15 13:26:43'),
(64, 6, 'DELETE_ORGANIZATION', 'Deleted organization: University Clinic', '::1', '2026-03-15 13:26:47'),
(65, 6, 'DELETE_ORGANIZATION', 'Deleted organization: Supreme Student Council', '::1', '2026-03-15 13:26:50'),
(66, 6, 'ADD_ORGANIZATION', 'Added new organization: Town Organization (town)', '::1', '2026-03-15 13:27:33'),
(67, 6, 'ADD_ORGANIZATION', 'Added new organization: Clinic (clinic)', '::1', '2026-03-15 13:29:55'),
(68, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-15 13:30:11'),
(69, 6, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-15 13:41:03'),
(70, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-15 13:41:21'),
(71, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-15 13:50:23'),
(72, 6, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-15 13:50:55'),
(75, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-15 13:51:38'),
(76, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-15 14:03:38'),
(77, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-15 14:03:47'),
(78, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-15 14:15:57'),
(79, 14, 'REGISTER', 'New student registered: Kristelle Joyce Lobo', '::1', '2026-03-15 14:18:47'),
(80, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '::1', '2026-03-15 14:18:53'),
(81, 14, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '::1', '2026-03-15 14:19:02'),
(82, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '::1', '2026-03-15 14:19:06'),
(83, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-15 14:19:12'),
(84, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-15 14:19:29'),
(85, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '::1', '2026-03-15 14:19:37'),
(86, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '::1', '2026-03-15 14:19:47'),
(88, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-15 14:20:46'),
(89, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-15 14:21:15'),
(90, 6, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-15 14:22:14'),
(91, 6, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-15 14:22:39'),
(92, 6, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-15 14:32:40'),
(93, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-15 14:44:10'),
(94, 6, 'DELETE_ORGANIZATION', 'Deleted organization: Clinic', '::1', '2026-03-15 14:44:19'),
(95, 6, 'DELETE_ORGANIZATION', 'Deleted organization: Town Organization', '::1', '2026-03-15 14:44:22'),
(96, 6, 'ADD_ORGANIZATION', 'Added new organization: Town Organization (town) with ID: 12', '::1', '2026-03-15 14:57:31'),
(97, 6, 'ADD_ORGANIZATION', 'Added new organization: Clinic (clinic) with ID: 13', '::1', '2026-03-15 14:58:29'),
(98, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-15 15:00:31'),
(99, 13, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-15 15:10:02'),
(100, 12, 'LOGOUT', 'User logged out: Town Organization', '::1', '2026-03-15 15:35:00'),
(101, 13, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-15 15:35:13'),
(102, 15, 'REGISTER', 'New student registered: Earl Gultia', '::1', '2026-03-15 15:35:52'),
(103, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-15 15:35:59'),
(104, 15, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '::1', '2026-03-15 15:36:05'),
(105, 15, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-15 15:36:09'),
(106, 13, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-15 15:36:24'),
(107, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-15 15:36:30'),
(108, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-15 15:36:38'),
(109, 12, 'LOGOUT', 'User logged out: Town Organization', '::1', '2026-03-15 15:36:54'),
(110, 12, 'LOGOUT', 'User logged out: Town Organization', '::1', '2026-03-15 16:00:45'),
(111, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-15 16:00:49'),
(112, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-15 16:00:55'),
(113, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '::1', '2026-03-15 16:01:02'),
(114, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '::1', '2026-03-15 16:01:08'),
(115, 3, 'LOGIN', 'User logged in successfully: Super Admin', '::1', '2026-03-15 16:01:17'),
(116, 3, 'LOGOUT', 'User logged out: Super Admin', '::1', '2026-03-15 16:06:49'),
(117, 12, 'PROCESS_CLEARANCE', 'Reject town clearance ID: 2 for student: Earl Gultia (325681)', '::1', '2026-03-15 16:11:33'),
(118, 12, 'LOGOUT', 'User logged out: Town Organization', '::1', '2026-03-15 16:38:31'),
(119, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-15 21:42:03'),
(120, 6, 'ADD_ORGANIZATION', 'Added new organization: Compass (college) with ID: 14', '::1', '2026-03-15 21:43:45'),
(121, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-15 21:43:56'),
(122, 14, 'LOGOUT', 'User logged out: Compass', '::1', '2026-03-15 22:40:26'),
(123, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-16 02:05:31'),
(124, 15, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-16 02:09:38'),
(125, 14, 'LOGOUT', 'User logged out: Compass', '::1', '2026-03-16 02:29:38'),
(126, 13, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-16 02:29:48'),
(127, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-16 02:33:03'),
(128, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-16 02:33:07'),
(129, 14, 'LOGOUT', 'User logged out: Compass', '::1', '2026-03-16 02:36:15'),
(130, 14, 'LOGOUT', 'User logged out: Compass', '::1', '2026-03-16 02:36:23'),
(131, 14, 'LOGOUT', 'User logged out: Compass', '::1', '2026-03-16 02:36:54'),
(132, 14, 'LOGOUT', 'User logged out: Compass', '::1', '2026-03-16 02:37:39'),
(133, 14, 'LOGOUT', 'User logged out: Compass', '::1', '2026-03-16 02:37:57'),
(134, 14, 'LOGOUT', 'User logged out: Compass', '::1', '2026-03-16 02:44:30'),
(135, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-16 02:44:42'),
(136, 6, 'ADD_ORGANIZATION', 'Added new organization: SSG (ssg) with ID: 15', '::1', '2026-03-16 02:45:23'),
(137, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-16 02:45:29'),
(138, 15, 'LOGOUT', 'User logged out: SSG', '::1', '2026-03-16 03:00:42'),
(139, 15, 'LOGOUT', 'User logged out: SSG', '::1', '2026-03-16 03:00:54'),
(140, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-16 03:01:01'),
(141, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-16 03:04:19'),
(142, NULL, 'REGISTER', 'New student registered: Venus Pelin', '::1', '2026-03-16 03:05:43'),
(143, NULL, 'LOGIN', 'User logged in successfully: Venus Pelin', '::1', '2026-03-16 03:05:49'),
(144, NULL, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '::1', '2026-03-16 03:05:57'),
(145, NULL, 'LOGOUT', 'User logged out: Venus Pelin', '::1', '2026-03-16 03:06:01'),
(146, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-16 03:06:16'),
(147, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-16 03:07:07'),
(148, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-16 03:07:18'),
(149, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-16 03:07:22'),
(150, 13, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-16 03:07:31'),
(151, 12, 'LOGOUT', 'User logged out: Town Organization', '::1', '2026-03-16 03:07:42'),
(152, 15, 'LOGOUT', 'User logged out: SSG', '::1', '2026-03-16 03:07:56'),
(153, 14, 'LOGOUT', 'User logged out: Compass', '::1', '2026-03-16 03:08:12'),
(154, 15, 'LOGOUT', 'User logged out: SSG', '::1', '2026-03-16 03:08:27'),
(155, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-16 03:32:51'),
(156, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-16 03:33:34'),
(157, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-16 03:33:44'),
(158, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-16 03:54:56'),
(159, 13, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-16 03:55:48'),
(160, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-16 03:59:29'),
(161, 15, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-16 04:05:13'),
(162, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-16 04:05:18'),
(163, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-16 04:05:32'),
(164, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-16 04:05:35'),
(165, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-16 04:55:41'),
(166, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-16 05:06:38'),
(167, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-16 05:49:32'),
(168, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-16 05:49:38'),
(169, 15, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-16 06:12:00'),
(170, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-16 06:12:12'),
(171, 11, 'LACKING_COMMENT', 'Added lacking comment for clearance ID: 19 for student: Earl Gultia. Comment: Wa ka kabayad ug 1 million fines', '::1', '2026-03-16 06:12:41'),
(172, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-16 06:12:45'),
(173, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-16 06:12:51'),
(174, 15, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-16 06:14:15'),
(175, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-16 06:14:24'),
(176, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-16 06:15:46'),
(177, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-16 06:16:24'),
(178, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-16 06:16:40'),
(179, NULL, 'LOGIN', 'User logged in successfully: Venus Pelin', '::1', '2026-03-16 06:16:45'),
(180, NULL, 'CANCEL_CLEARANCE', 'Cancelled clearance application for 2nd Semester 2025-2026', '::1', '2026-03-16 06:16:54'),
(181, NULL, 'LOGOUT', 'User logged out: Venus Pelin', '::1', '2026-03-16 06:17:02'),
(182, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-16 06:17:09'),
(183, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-16 06:17:16'),
(184, NULL, 'LOGIN', 'User logged in successfully: Venus Pelin', '::1', '2026-03-16 06:17:28'),
(185, NULL, 'LOGOUT', 'User logged out: Venus Pelin', '::1', '2026-03-16 06:17:37'),
(186, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-16 06:17:52'),
(187, 15, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 19 to Librarian', '::1', '2026-03-16 06:18:27'),
(188, 15, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-16 06:18:36'),
(189, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-16 06:18:41'),
(190, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-16 06:28:16'),
(191, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-16 06:28:24'),
(192, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-16 06:28:32'),
(193, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-16 06:28:40'),
(194, 15, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-16 06:29:38'),
(195, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-16 06:29:44'),
(196, 15, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-16 06:33:23'),
(197, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-16 06:33:40'),
(198, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-16 06:37:36'),
(199, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-16 06:38:02'),
(200, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-16 07:23:50'),
(201, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-16 07:42:26'),
(202, 15, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-16 07:43:42'),
(203, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-18 00:38:38'),
(204, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-18 00:58:09'),
(205, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-18 00:58:14'),
(206, 11, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture', '::1', '2026-03-18 01:11:21'),
(207, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-18 02:40:38'),
(208, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '::1', '2026-03-18 02:40:55'),
(209, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '::1', '2026-03-18 03:18:21'),
(210, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-18 03:18:31'),
(211, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-18 03:19:14'),
(212, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '::1', '2026-03-18 03:19:24'),
(213, 13, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture', '::1', '2026-03-18 03:53:24'),
(214, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '::1', '2026-03-18 03:55:27'),
(215, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-18 03:55:37'),
(216, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-18 03:59:10'),
(217, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '::1', '2026-03-18 03:59:34'),
(218, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '::1', '2026-03-18 04:16:59'),
(219, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-18 04:18:49'),
(220, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-18 04:20:04'),
(221, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '::1', '2026-03-18 04:20:10'),
(222, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '::1', '2026-03-18 04:20:24'),
(223, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-18 04:20:37'),
(224, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-18 04:21:12'),
(225, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-18 04:21:19'),
(226, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-18 04:21:40'),
(227, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-18 04:21:54'),
(228, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-18 04:22:25'),
(229, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '::1', '2026-03-18 04:23:08'),
(230, 12, 'LOGOUT', 'User logged out: Dean Candijay', '::1', '2026-03-18 05:07:00'),
(231, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-18 05:07:10'),
(232, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-18 05:07:26'),
(233, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '::1', '2026-03-18 05:07:39'),
(234, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '::1', '2026-03-18 06:26:13'),
(235, NULL, 'REGISTER', 'New student registered: Jhoel Kenneth Gulle', '::1', '2026-03-18 06:29:47'),
(236, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '::1', '2026-03-18 06:30:30'),
(237, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '::1', '2026-03-18 06:30:48'),
(238, NULL, 'LOGIN', 'User logged in successfully: Venus Pelin', '::1', '2026-03-18 06:31:04'),
(239, NULL, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '::1', '2026-03-18 06:32:06'),
(240, NULL, 'LOGOUT', 'User logged out: Venus Pelin', '::1', '2026-03-18 06:32:42'),
(241, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-18 06:32:54'),
(242, 11, 'LACKING_COMMENT', 'Added lacking comment for clearance ID: 29 for student: Venus Pelin. Comment: Fines 50', '::1', '2026-03-18 06:33:49'),
(243, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-18 06:34:06'),
(244, NULL, 'LOGIN', 'User logged in successfully: Venus Pelin', '::1', '2026-03-18 06:34:14'),
(245, NULL, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 29 to Librarian', '::1', '2026-03-18 06:34:58'),
(246, NULL, 'LOGOUT', 'User logged out: Venus Pelin', '::1', '2026-03-18 06:35:09'),
(247, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-18 06:35:17'),
(248, 11, 'APPROVE_CLEARANCE', 'Approved clearance ID: 29 for student: Venus Pelin (134820)', '::1', '2026-03-18 06:36:26'),
(249, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-18 06:36:33'),
(250, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-18 06:36:40'),
(251, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-18 06:37:08'),
(252, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '::1', '2026-03-18 06:37:20'),
(253, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '::1', '2026-03-18 06:37:41'),
(254, NULL, 'LOGIN', 'User logged in successfully: Venus Pelin', '::1', '2026-03-18 06:37:50'),
(255, NULL, 'LOGOUT', 'User logged out: Venus Pelin', '::1', '2026-03-18 06:38:13'),
(256, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '::1', '2026-03-18 06:38:22'),
(257, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '::1', '2026-03-18 06:39:18'),
(258, 12, 'LOGOUT', 'User logged out: Town Organization', '::1', '2026-03-18 06:39:59'),
(259, NULL, 'LOGIN', 'User logged in successfully: Venus Pelin', '::1', '2026-03-18 06:40:09'),
(260, NULL, 'LOGOUT', 'User logged out: Venus Pelin', '::1', '2026-03-18 06:42:42'),
(261, 13, 'LOGOUT', 'User logged out: Clinic', '::1', '2026-03-18 06:45:01'),
(262, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '::1', '2026-03-18 07:27:08'),
(263, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '::1', '2026-03-18 07:32:25'),
(264, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '::1', '2026-03-18 07:32:35'),
(265, 15, 'LOGOUT', 'User logged out: Earl Gultia', '::1', '2026-03-18 07:49:59'),
(266, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '192.168.1.62', '2026-03-18 08:23:58'),
(267, 15, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture', '192.168.1.62', '2026-03-18 08:27:52'),
(268, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-18 08:42:01'),
(269, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-18 08:42:16'),
(270, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '127.0.0.1', '2026-03-19 01:17:34'),
(271, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '127.0.0.1', '2026-03-19 01:17:46'),
(272, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-20 00:45:46'),
(273, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-20 00:46:06'),
(274, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-20 02:32:49'),
(275, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-20 02:34:44'),
(276, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-20 02:38:55'),
(277, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-20 02:39:01'),
(278, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-20 03:25:41'),
(279, 18, 'REGISTER', 'New student registered: Jessica Mapute', '127.0.0.1', '2026-03-20 03:28:50'),
(280, 18, 'LOGIN', 'User logged in successfully: Jessica Mapute', '127.0.0.1', '2026-03-20 03:29:07'),
(281, 18, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-20 03:29:23'),
(282, 18, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture', '127.0.0.1', '2026-03-20 03:30:40'),
(283, 18, 'CANCEL_CLEARANCE', 'Cancelled clearance application for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-20 03:31:14'),
(284, 18, 'LOGOUT', 'User logged out: Jessica Mapute', '127.0.0.1', '2026-03-20 03:33:02'),
(285, 18, 'LOGIN', 'User logged in successfully: Jessica Mapute', '127.0.0.1', '2026-03-20 03:33:55'),
(286, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 04:23:30'),
(287, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 04:23:54'),
(288, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 04:26:48'),
(289, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 04:27:01'),
(290, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 04:27:11'),
(291, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 04:27:22'),
(292, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 04:30:23'),
(293, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 04:31:33'),
(294, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 04:31:49'),
(295, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 04:38:16'),
(296, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 14:35:42'),
(297, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 14:35:47'),
(298, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 14:44:21'),
(299, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 14:45:49'),
(300, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 14:48:12'),
(301, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 14:48:26'),
(302, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 14:52:04'),
(303, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 14:52:25'),
(304, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 15:06:23'),
(305, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 15:07:50'),
(306, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 15:08:44'),
(307, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 15:09:18'),
(308, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:10:45'),
(309, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:20:32'),
(310, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 15:20:49'),
(311, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 15:21:03'),
(312, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:21:19'),
(313, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:26:12'),
(314, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:27:41'),
(315, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:28:01'),
(316, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:30:07'),
(317, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:30:25'),
(318, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:31:39'),
(319, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:32:26'),
(320, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:32:46'),
(321, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:33:05'),
(322, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:33:09'),
(323, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:36:32'),
(324, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-21 15:43:15'),
(325, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-21 15:43:49'),
(326, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:43:59'),
(327, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:44:38'),
(328, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-21 15:45:19'),
(329, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:45:30'),
(330, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:45:45'),
(331, 13, 'PROCESS_CLEARANCE', 'Approve clinic clearance ID: 23 for student: Earl Gultia (325681)', '127.0.0.1', '2026-03-21 15:46:07'),
(332, 13, 'PROCESS_CLEARANCE', 'Approve clinic clearance ID: 18 for student: Kristelle Joyce Lobo (143256)', '127.0.0.1', '2026-03-21 15:46:38'),
(333, 13, 'PROCESS_CLEARANCE', 'Approve clinic clearance ID: 33 for student: Venus Pelin (134820)', '127.0.0.1', '2026-03-21 15:50:30'),
(334, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-21 15:50:36'),
(335, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:50:46'),
(336, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:51:32'),
(337, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-21 15:52:05'),
(338, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-21 15:52:47'),
(339, 15, 'LOGOUT', 'User logged out: SSG', '127.0.0.1', '2026-03-21 15:53:19'),
(340, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 15:53:27'),
(341, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 16:01:11'),
(342, NULL, 'LOGIN', 'User logged in successfully: Venus Pelin', '127.0.0.1', '2026-03-21 16:01:19'),
(343, NULL, 'LOGOUT', 'User logged out: Venus Pelin', '127.0.0.1', '2026-03-21 16:01:35'),
(344, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-21 16:01:45'),
(345, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-21 16:02:31'),
(346, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-21 16:02:54'),
(347, NULL, 'REGISTER', 'New student registered: Dil Doe', '127.0.0.1', '2026-03-21 16:04:15'),
(348, NULL, 'LOGIN', 'User logged in successfully: Dil Doe', '127.0.0.1', '2026-03-21 16:04:21'),
(349, NULL, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-21 16:04:29'),
(350, NULL, 'LOGOUT', 'User logged out: Dil Doe', '127.0.0.1', '2026-03-21 16:04:37'),
(351, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '127.0.0.1', '2026-03-21 16:04:55'),
(352, 11, 'APPROVE_CLEARANCE', 'Approved clearance ID: 39 for student: Dil Doe (164585)', '127.0.0.1', '2026-03-21 16:05:14'),
(353, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '127.0.0.1', '2026-03-21 16:05:18'),
(354, 13, 'PROCESS_CLEARANCE', 'Approve clinic clearance ID: 43 for student: Dil Doe (164585)', '127.0.0.1', '2026-03-21 16:05:49'),
(355, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-21 16:06:04'),
(356, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-21 16:10:30'),
(357, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-21 16:21:38'),
(358, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-21 16:22:00'),
(359, 15, 'LOGOUT', 'User logged out: SSG', '127.0.0.1', '2026-03-21 16:22:40'),
(360, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-21 16:25:43'),
(361, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-21 16:31:26'),
(362, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-21 16:31:53'),
(363, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 16:32:05'),
(364, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 16:38:27'),
(365, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-21 16:40:50'),
(366, NULL, 'LOGIN', 'User logged in successfully: Dil Doe', '127.0.0.1', '2026-03-21 16:41:02'),
(367, NULL, 'CANCEL_CLEARANCE', 'Cancelled clearance application for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-21 16:44:06'),
(368, NULL, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-21 16:52:35'),
(369, NULL, 'LOGOUT', 'User logged out: Dil Doe', '127.0.0.1', '2026-03-21 16:52:46'),
(370, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '127.0.0.1', '2026-03-21 16:53:00'),
(371, 11, 'APPROVE_CLEARANCE', 'Approved clearance ID: 69 for student: Dil Doe (164585)', '127.0.0.1', '2026-03-21 16:53:09'),
(372, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '127.0.0.1', '2026-03-21 16:53:13'),
(373, 13, 'PROCESS_CLEARANCE', 'Approve clinic clearance ID: 73 for student: Dil Doe (164585)', '127.0.0.1', '2026-03-21 16:53:25'),
(374, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-21 16:53:31'),
(375, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-21 16:55:58'),
(376, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 16:56:06'),
(377, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 17:01:23'),
(378, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 17:01:28'),
(379, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 17:01:53'),
(380, NULL, 'LOGIN', 'User logged in successfully: Dil Doe', '127.0.0.1', '2026-03-21 17:02:00'),
(381, NULL, 'CANCEL_CLEARANCE', 'Cancelled clearance application for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-21 17:02:05'),
(382, NULL, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-21 17:02:13'),
(383, NULL, 'LOGOUT', 'User logged out: Dil Doe', '127.0.0.1', '2026-03-21 17:02:21'),
(384, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '127.0.0.1', '2026-03-21 17:02:29'),
(385, 11, 'APPROVE_CLEARANCE', 'Approved clearance ID: 74 for student: Dil Doe (164585)', '127.0.0.1', '2026-03-21 17:02:36'),
(386, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '127.0.0.1', '2026-03-21 17:02:40'),
(387, 13, 'PROCESS_CLEARANCE', 'Approved clinic clearance ID: 78 for student: Dil Doe (164585)', '127.0.0.1', '2026-03-21 17:02:53'),
(388, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-21 17:02:56'),
(389, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-21 17:03:15'),
(390, 15, 'LOGOUT', 'User logged out: SSG', '127.0.0.1', '2026-03-21 17:03:45'),
(391, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-21 17:04:03'),
(392, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 17:04:13'),
(393, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 17:12:17'),
(394, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-21 17:12:22'),
(395, 6, 'APPROVE_CLEARANCE', 'Approved clearance ID: 75 for student: Dil Doe (164585)', '127.0.0.1', '2026-03-21 17:12:38'),
(396, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-21 17:12:45'),
(397, NULL, 'LOGIN', 'User logged in successfully: Dil Doe', '127.0.0.1', '2026-03-21 17:12:53'),
(398, NULL, 'LOGOUT', 'User logged out: Dil Doe', '127.0.0.1', '2026-03-21 17:12:59'),
(399, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '127.0.0.1', '2026-03-21 17:13:11'),
(400, 12, 'APPROVE_CLEARANCE', 'Approved clearance ID: 76 for student: Dil Doe (164585)', '127.0.0.1', '2026-03-21 17:13:21'),
(401, 12, 'LOGOUT', 'User logged out: Dean Candijay', '127.0.0.1', '2026-03-21 17:13:24'),
(402, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '127.0.0.1', '2026-03-21 17:13:50'),
(403, 13, 'APPROVE_CLEARANCE', 'Approved clearance ID: 77 for student: Dil Doe (164585)', '127.0.0.1', '2026-03-21 17:13:59'),
(404, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '127.0.0.1', '2026-03-21 17:14:05'),
(405, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '127.0.0.1', '2026-03-21 17:14:13'),
(406, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '127.0.0.1', '2026-03-21 17:14:21'),
(407, NULL, 'LOGIN', 'User logged in successfully: Dil Doe', '127.0.0.1', '2026-03-21 17:14:28'),
(408, NULL, 'LOGOUT', 'User logged out: Dil Doe', '127.0.0.1', '2026-03-21 17:14:51'),
(409, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '127.0.0.1', '2026-03-21 17:24:36'),
(410, 10, 'CLEARANCE_COMPLETED', 'Clearance completed for student: Dil Doe (164585) for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-21 17:24:48'),
(411, 10, 'APPROVE_CLEARANCE', 'Approved clearance ID: 78 for student: Dil Doe (164585)', '127.0.0.1', '2026-03-21 17:24:48'),
(412, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '127.0.0.1', '2026-03-21 17:27:46'),
(413, NULL, 'LOGIN', 'User logged in successfully: Dil Doe', '127.0.0.1', '2026-03-21 17:28:01'),
(414, NULL, 'LOGOUT', 'User logged out: Dil Doe', '127.0.0.1', '2026-03-21 17:28:21'),
(415, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 17:29:53'),
(416, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 17:30:29'),
(417, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 17:31:03'),
(418, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 17:31:10'),
(419, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 17:33:16'),
(420, 15, 'CANCEL_CLEARANCE', 'Cancelled clearance application for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-21 17:34:16'),
(421, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 17:34:40'),
(422, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-21 17:35:56'),
(423, 15, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-21 17:36:12'),
(424, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-21 17:36:26'),
(425, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-22 04:09:11'),
(426, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-22 04:09:17'),
(427, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-22 04:18:09'),
(428, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-22 04:19:36'),
(429, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 04:19:52'),
(430, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 04:20:51'),
(431, 15, 'PASSWORD_RESET', 'User reset account password successfully', '127.0.0.1', '2026-03-22 04:26:46'),
(432, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 04:26:58'),
(433, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 04:27:03'),
(434, NULL, 'CONTACT_INQUIRY', 'Contact form submission from Earl Gultia (earl.gultia@bisu.edu.ph): Ha', '127.0.0.1', '2026-03-22 04:28:45'),
(435, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 04:28:55'),
(436, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 04:37:39'),
(437, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 04:40:02'),
(438, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 04:41:17'),
(439, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 04:42:40'),
(440, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 04:42:47'),
(441, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 04:49:33'),
(442, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 05:18:51'),
(443, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-22 05:19:02'),
(444, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-22 05:19:42'),
(445, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 05:19:56'),
(446, 15, 'ADD_FRIEND', 'Added student friend via ISMIS: 143256', '127.0.0.1', '2026-03-22 05:20:13'),
(447, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 05:20:30'),
(448, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-22 05:20:39'),
(449, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-22 05:26:38'),
(450, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 05:26:46'),
(451, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 05:27:12'),
(452, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-22 05:27:22'),
(453, 14, 'SEND_FRIEND_REQUEST', 'Sent friend request via ISMIS: 325681', '127.0.0.1', '2026-03-22 05:31:40'),
(454, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-22 05:31:49'),
(455, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 05:32:41'),
(456, 15, 'ACCEPT_FRIEND_REQUEST', 'Accepted friend request #1', '127.0.0.1', '2026-03-22 05:47:15'),
(457, 15, 'SEND_MESSAGE', 'Sent message to student ID: 14', '127.0.0.1', '2026-03-22 05:47:31'),
(458, 15, 'SEND_MESSAGE', 'Sent message to student ID: 14', '127.0.0.1', '2026-03-22 05:50:20'),
(459, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 05:52:32'),
(460, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-22 05:52:42'),
(461, 14, 'SEND_MESSAGE', 'Sent message to student ID: 15', '127.0.0.1', '2026-03-22 05:53:09'),
(462, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-22 05:53:17'),
(463, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 05:53:29'),
(464, 15, 'CANCEL_CLEARANCE', 'Cancelled clearance application for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-22 05:54:04'),
(465, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 05:54:13'),
(466, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 05:58:32'),
(467, 15, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-22 05:58:42'),
(468, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 05:59:16'),
(469, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:03:54'),
(470, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:04:32'),
(471, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:04:57'),
(472, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:05:03'),
(473, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:05:52'),
(474, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:08:25'),
(475, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:09:37'),
(476, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:09:43'),
(477, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:10:20'),
(478, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:10:25'),
(479, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:10:46'),
(480, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:11:29'),
(481, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:11:59'),
(482, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:12:56'),
(483, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:14:30'),
(484, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:15:39'),
(485, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:17:06'),
(486, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:19:12'),
(487, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:19:34'),
(488, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:21:45'),
(489, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:21:54'),
(490, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-22 06:22:16'),
(491, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:22:42'),
(492, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-22 06:27:02'),
(493, 20, 'REGISTER', 'New student registered: Jaye Melle Dela Peña', '127.0.0.1', '2026-03-25 05:57:06'),
(494, 20, 'LOGIN', 'User logged in successfully: Jaye Melle Dela Peña', '127.0.0.1', '2026-03-25 05:58:56'),
(495, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-25 06:00:02'),
(496, 15, 'CANCEL_CLEARANCE', 'Cancelled clearance application for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-25 06:00:37'),
(497, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-25 06:01:49'),
(498, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-25 06:08:23'),
(499, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-25 06:08:32'),
(500, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-25 06:08:55'),
(501, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-25 06:09:00'),
(502, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-25 07:19:08'),
(503, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-25 07:23:38'),
(504, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-25 07:24:32'),
(505, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-25 07:25:16'),
(506, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 00:57:56'),
(507, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 00:58:18'),
(508, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-30 01:15:12'),
(509, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 01:15:25'),
(510, 15, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 01:15:38'),
(511, 15, 'CANCEL_CLEARANCE', 'Cancelled clearance application for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 01:15:43'),
(512, 15, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 01:15:50'),
(513, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 01:16:36'),
(514, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '127.0.0.1', '2026-03-30 01:16:48'),
(515, 11, 'APPROVE_CLEARANCE', 'Approved clearance ID: 94 for student: Earl Gultia (325681)', '127.0.0.1', '2026-03-30 01:17:08'),
(516, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '127.0.0.1', '2026-03-30 01:17:12'),
(517, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-30 01:24:02'),
(518, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 01:24:10'),
(519, 15, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 95 to Director_SAS', '127.0.0.1', '2026-03-30 01:24:46'),
(520, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 01:24:55'),
(521, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-30 01:27:52'),
(522, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-30 01:28:47'),
(523, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-30 01:29:06'),
(524, 15, 'LOGOUT', 'User logged out: SSG', '127.0.0.1', '2026-03-30 01:29:34'),
(525, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-30 01:32:40'),
(526, 6, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture', '127.0.0.1', '2026-03-30 01:33:34'),
(527, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-30 01:33:41'),
(528, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-30 01:33:45'),
(529, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-30 01:33:48'),
(530, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-30 01:34:00'),
(531, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-30 01:34:04'),
(532, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-30 01:43:26'),
(533, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 01:43:34');
INSERT INTO `activity_logs` (`log_id`, `users_id`, `action`, `description`, `ip_address`, `created_at`) VALUES
(534, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 01:44:43'),
(535, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-30 01:58:24'),
(536, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 01:58:31'),
(537, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 02:09:51'),
(538, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 02:09:55'),
(539, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 02:10:28'),
(540, 13, 'PROCESS_CLEARANCE', 'Approved clinic org clearance ID: 72 for student: Earl Gultia (325681)', '127.0.0.1', '2026-03-30 02:10:58'),
(541, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-30 02:11:03'),
(542, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-30 02:11:30'),
(543, 15, 'LOGOUT', 'User logged out: SSG', '127.0.0.1', '2026-03-30 02:11:51'),
(544, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-30 02:12:00'),
(545, 6, 'APPROVE_CLEARANCE', 'Approved clearance ID: 95 for student: Earl Gultia (325681)', '127.0.0.1', '2026-03-30 02:12:15'),
(546, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-30 02:12:22'),
(547, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 02:12:30'),
(548, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 02:12:41'),
(549, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '127.0.0.1', '2026-03-30 02:12:50'),
(550, 12, 'APPROVE_CLEARANCE', 'Approved clearance ID: 96 for student: Earl Gultia (325681)', '127.0.0.1', '2026-03-30 02:13:02'),
(551, 12, 'LOGOUT', 'User logged out: Dean Candijay', '127.0.0.1', '2026-03-30 02:13:06'),
(552, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 02:13:15'),
(553, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 02:13:22'),
(554, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '127.0.0.1', '2026-03-30 02:13:30'),
(555, 13, 'APPROVE_CLEARANCE', 'Approved clearance ID: 97 for student: Earl Gultia (325681)', '127.0.0.1', '2026-03-30 02:13:36'),
(556, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '127.0.0.1', '2026-03-30 02:13:40'),
(557, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '127.0.0.1', '2026-03-30 02:13:50'),
(558, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '127.0.0.1', '2026-03-30 02:14:05'),
(559, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 02:14:14'),
(560, 15, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 98 to Registrar', '127.0.0.1', '2026-03-30 02:14:50'),
(561, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 02:14:59'),
(562, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '127.0.0.1', '2026-03-30 02:15:09'),
(563, 10, 'CLEARANCE_COMPLETED', 'Clearance completed for student: Earl Gultia (325681) for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 02:15:15'),
(564, 10, 'APPROVE_CLEARANCE', 'Approved clearance ID: 98 for student: Earl Gultia (325681)', '127.0.0.1', '2026-03-30 02:15:15'),
(565, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '127.0.0.1', '2026-03-30 02:16:00'),
(566, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 02:16:10'),
(567, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 02:19:22'),
(568, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 02:20:01'),
(569, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 02:27:34'),
(570, 21, 'REGISTER', 'New student registered: Angel Tutor', '127.0.0.1', '2026-03-30 02:27:54'),
(571, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 02:28:12'),
(572, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '127.0.0.1', '2026-03-30 02:28:21'),
(573, 21, 'LOGIN', 'User logged in successfully: Angel Tutor', '127.0.0.1', '2026-03-30 02:28:21'),
(574, 21, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 02:28:54'),
(575, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '127.0.0.1', '2026-03-30 02:29:13'),
(576, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 02:29:37'),
(577, 21, 'LOGIN', 'User logged in successfully: Angel Tutor', '127.0.0.1', '2026-03-30 02:30:29'),
(578, 21, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture', '127.0.0.1', '2026-03-30 02:31:31'),
(579, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-30 03:28:21'),
(580, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-30 03:28:40'),
(581, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '127.0.0.1', '2026-03-30 03:28:52'),
(582, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '127.0.0.1', '2026-03-30 03:36:23'),
(583, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 03:36:32'),
(584, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 03:49:26'),
(585, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:49:35'),
(586, 14, 'CANCEL_CLEARANCE', 'Cancelled clearance application for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 03:49:56'),
(587, 14, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 03:50:04'),
(588, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:50:19'),
(589, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '127.0.0.1', '2026-03-30 03:50:28'),
(590, 11, 'APPROVE_CLEARANCE', 'Approved clearance ID: 104 for student: Kristelle Joyce Lobo (143256)', '127.0.0.1', '2026-03-30 03:50:36'),
(591, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '127.0.0.1', '2026-03-30 03:50:40'),
(592, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-30 03:51:10'),
(593, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:51:19'),
(594, 14, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 105 to Clinic', '127.0.0.1', '2026-03-30 03:51:39'),
(595, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:51:49'),
(596, 13, 'PROCESS_CLEARANCE', 'Approved clinic org clearance ID: 80 for student: Kristelle Joyce Lobo (143256)', '127.0.0.1', '2026-03-30 03:52:24'),
(597, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-30 03:52:27'),
(598, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-30 03:53:00'),
(599, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:53:09'),
(600, 14, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 105 to Compass', '127.0.0.1', '2026-03-30 03:54:05'),
(601, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:54:16'),
(602, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-30 03:54:46'),
(603, 15, 'LOGOUT', 'User logged out: SSG', '127.0.0.1', '2026-03-30 03:55:23'),
(604, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:55:34'),
(605, 14, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 105 to SSG', '127.0.0.1', '2026-03-30 03:56:02'),
(606, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:56:10'),
(607, 15, 'LOGOUT', 'User logged out: SSG', '127.0.0.1', '2026-03-30 03:56:39'),
(608, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-30 03:57:06'),
(609, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:57:19'),
(610, 14, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 105 to Town Organization', '127.0.0.1', '2026-03-30 03:57:37'),
(611, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:57:45'),
(612, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-30 03:58:08'),
(613, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '127.0.0.1', '2026-03-30 03:58:16'),
(614, 12, 'LOGOUT', 'User logged out: Dean Candijay', '127.0.0.1', '2026-03-30 03:58:39'),
(615, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-30 03:58:48'),
(616, 6, 'APPROVE_CLEARANCE', 'Approved clearance ID: 105 for student: Kristelle Joyce Lobo (143256)', '127.0.0.1', '2026-03-30 03:59:04'),
(617, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-30 03:59:08'),
(618, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '127.0.0.1', '2026-03-30 03:59:16'),
(619, 12, 'LACKING_COMMENT', 'Added lacking comment for clearance ID: 106 for student: Kristelle Joyce Lobo. Comment: Kuwang kag grades', '127.0.0.1', '2026-03-30 03:59:40'),
(620, 12, 'LOGOUT', 'User logged out: Dean Candijay', '127.0.0.1', '2026-03-30 03:59:44'),
(621, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 03:59:51'),
(622, 14, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 106 to Dean', '127.0.0.1', '2026-03-30 04:00:35'),
(623, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 04:00:45'),
(624, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '127.0.0.1', '2026-03-30 04:00:53'),
(625, 12, 'APPROVE_CLEARANCE', 'Approved clearance ID: 106 for student: Kristelle Joyce Lobo (143256)', '127.0.0.1', '2026-03-30 04:01:05'),
(626, 12, 'LOGOUT', 'User logged out: Dean Candijay', '127.0.0.1', '2026-03-30 04:01:18'),
(627, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 04:01:25'),
(628, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 04:01:33'),
(629, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '127.0.0.1', '2026-03-30 04:01:41'),
(630, 13, 'APPROVE_CLEARANCE', 'Approved clearance ID: 107 for student: Kristelle Joyce Lobo (143256)', '127.0.0.1', '2026-03-30 04:01:50'),
(631, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '127.0.0.1', '2026-03-30 04:01:54'),
(632, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '127.0.0.1', '2026-03-30 04:02:03'),
(633, 10, 'CLEARANCE_COMPLETED', 'Clearance completed for student: Kristelle Joyce Lobo (143256) for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 04:02:10'),
(634, 10, 'APPROVE_CLEARANCE', 'Approved clearance ID: 108 for student: Kristelle Joyce Lobo (143256)', '127.0.0.1', '2026-03-30 04:02:10'),
(635, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '127.0.0.1', '2026-03-30 04:02:47'),
(636, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 04:02:55'),
(637, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 04:03:04'),
(638, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-30 04:05:22'),
(639, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:05:59'),
(640, 15, 'SEND_MESSAGE', 'Sent message to student ID: 14', '127.0.0.1', '2026-03-30 04:06:40'),
(641, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:06:55'),
(642, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-30 04:07:37'),
(643, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 04:07:46'),
(644, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:07:47'),
(645, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:09:27'),
(646, 14, 'UPDATE_PROFILE', 'Student profile updated', '127.0.0.1', '2026-03-30 04:10:04'),
(647, 14, 'UPDATE_PROFILE', 'Student profile updated', '127.0.0.1', '2026-03-30 04:11:00'),
(648, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:11:21'),
(649, 14, 'SEND_MESSAGE', 'Sent message to student ID: 15', '127.0.0.1', '2026-03-30 04:14:35'),
(650, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:15:06'),
(651, 15, 'SEND_MESSAGE', 'Sent message to student ID: 14', '127.0.0.1', '2026-03-30 04:15:24'),
(652, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:15:42'),
(653, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:15:47'),
(654, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:16:48'),
(655, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:16:53'),
(656, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 04:17:36'),
(657, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:19:51'),
(658, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:20:00'),
(659, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:20:33'),
(660, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:20:39'),
(661, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:22:02'),
(662, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:23:23'),
(663, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:25:11'),
(664, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 04:26:37'),
(665, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:26:40'),
(666, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:30:24'),
(667, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:32:05'),
(668, 14, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture', '127.0.0.1', '2026-03-30 04:38:38'),
(669, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:38:59'),
(670, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:39:27'),
(671, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 04:41:18'),
(672, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 04:44:24'),
(673, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:51:52'),
(674, 15, 'SEND_MESSAGE', 'Sent message to student ID: 14', '127.0.0.1', '2026-03-30 04:53:41'),
(675, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:54:17'),
(676, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 04:55:14'),
(677, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 04:56:40'),
(678, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-30 04:58:49'),
(679, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-30 04:59:01'),
(680, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-30 04:59:08'),
(681, 3, 'EDIT_USER', 'Updated user ID 14: kristellejoyce.lobo@bisu.edu.ph', '127.0.0.1', '2026-03-30 05:02:42'),
(682, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-30 05:02:49'),
(683, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:02:59'),
(684, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:04:09'),
(685, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-30 05:04:16'),
(686, 3, 'EDIT_USER', 'Updated user ID 14: kristellejoyce.lobo@bisu.edu.ph', '127.0.0.1', '2026-03-30 05:06:18'),
(687, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-30 05:06:33'),
(688, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:06:55'),
(689, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:07:25'),
(690, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-30 05:07:36'),
(691, 22, 'REGISTER', 'New student registered: Myla Peleño', '127.0.0.1', '2026-03-30 05:08:13'),
(692, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-30 05:09:23'),
(693, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:09:32'),
(694, 22, 'LOGIN', 'User logged in successfully: Myla Peleño', '127.0.0.1', '2026-03-30 05:10:59'),
(695, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:11:47'),
(696, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-30 05:12:28'),
(697, 3, 'EDIT_USER', 'Updated user ID 14: kristellejoyce.lobo@bisu.edu.ph', '127.0.0.1', '2026-03-30 05:12:56'),
(698, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-30 05:13:00'),
(699, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:13:12'),
(700, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:13:34'),
(701, 14, 'LOGIN', 'User logged in successfully: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:13:45'),
(702, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:13:55'),
(703, 22, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture', '127.0.0.1', '2026-03-30 05:16:33'),
(704, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:18:38'),
(705, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:19:01'),
(706, NULL, 'LOGIN', 'User logged in successfully: Venus Pelin', '127.0.0.1', '2026-03-30 05:19:12'),
(707, NULL, 'LOGOUT', 'User logged out: Venus Pelin', '127.0.0.1', '2026-03-30 05:19:58'),
(708, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:20:18'),
(709, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:21:36'),
(710, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:21:40'),
(711, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:22:41'),
(712, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-30 05:22:48'),
(713, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-30 05:24:16'),
(714, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:26:07'),
(715, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:26:11'),
(716, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-30 05:26:58'),
(717, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-30 05:30:01'),
(718, 14, 'LOGOUT', 'User logged out: Kristelle Joyce Lobo', '127.0.0.1', '2026-03-30 05:30:20'),
(719, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:30:49'),
(720, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:31:53'),
(721, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:32:22'),
(722, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:34:14'),
(723, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:35:29'),
(724, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:37:39'),
(725, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:38:02'),
(726, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:38:11'),
(727, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:38:29'),
(728, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:38:46'),
(729, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:49:29'),
(730, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:49:38'),
(731, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:52:22'),
(732, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:53:33'),
(733, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-30 05:54:10'),
(734, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-30 05:55:42'),
(735, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:55:47'),
(736, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:56:38'),
(737, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 05:57:16'),
(738, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 05:57:48'),
(739, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-30 05:58:41'),
(740, 3, 'TOGGLE_USER', 'Deactivated user: dil.doe@bisu.edu.ph', '127.0.0.1', '2026-03-30 06:01:15'),
(741, 23, 'REGISTER', 'New student registered: KC ALICARTE', '127.0.0.1', '2026-03-30 06:01:39'),
(742, 23, 'LOGIN', 'User logged in successfully: KC ALICARTE', '127.0.0.1', '2026-03-30 06:01:46'),
(743, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-30 06:02:05'),
(744, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 06:02:17'),
(745, 23, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 06:02:30'),
(746, 23, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture', '127.0.0.1', '2026-03-30 06:02:58'),
(747, 24, 'REGISTER', 'New student registered: Venus Gabriel', '127.0.0.1', '2026-03-30 06:04:51'),
(748, 23, 'LOGOUT', 'User logged out: KC ALICARTE', '127.0.0.1', '2026-03-30 06:05:07'),
(749, 25, 'REGISTER', 'New student registered: Mary Ann Butong', '127.0.0.1', '2026-03-30 06:05:34'),
(750, 25, 'LOGIN', 'User logged in successfully: Mary Ann Butong', '127.0.0.1', '2026-03-30 06:06:08'),
(751, 24, 'LOGIN', 'User logged in successfully: Venus Gabriel', '127.0.0.1', '2026-03-30 06:06:10'),
(752, 24, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture', '127.0.0.1', '2026-03-30 06:07:06'),
(753, 24, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 06:07:26'),
(754, 26, 'REGISTER', 'New student registered: ROSELLE MERENILLO', '127.0.0.1', '2026-03-30 06:07:28'),
(755, 26, 'LOGIN', 'User logged in successfully: ROSELLE MERENILLO', '127.0.0.1', '2026-03-30 06:08:12'),
(756, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 06:08:22'),
(757, 24, 'SEND_FRIEND_REQUEST', 'Sent friend request via ISMIS: 325681', '127.0.0.1', '2026-03-30 06:09:16'),
(758, 15, 'ACCEPT_FRIEND_REQUEST', 'Accepted friend request #2', '127.0.0.1', '2026-03-30 06:09:29'),
(759, 24, 'SEND_MESSAGE', 'Sent message to student ID: 15', '127.0.0.1', '2026-03-30 06:09:41'),
(760, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 06:10:24'),
(761, 25, 'LOGIN', 'User logged in successfully: Mary Ann Butong', '127.0.0.1', '2026-03-30 06:10:40'),
(762, 3, 'LOGIN', 'User logged in successfully: Super Admin', '127.0.0.1', '2026-03-30 06:10:48'),
(763, 3, 'LOGOUT', 'User logged out: Super Admin', '127.0.0.1', '2026-03-30 06:11:31'),
(764, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 06:11:38'),
(765, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 06:11:45'),
(766, 24, 'SEND_FRIEND_REQUEST', 'Sent friend request via ISMIS: 686475', '127.0.0.1', '2026-03-30 06:12:09'),
(767, 26, 'ACCEPT_FRIEND_REQUEST', 'Accepted friend request #3', '127.0.0.1', '2026-03-30 06:12:28'),
(768, 24, 'SEND_MESSAGE', 'Sent message to student ID: 26', '127.0.0.1', '2026-03-30 06:12:57'),
(769, 26, 'SEND_MESSAGE', 'Sent message to student ID: 24', '127.0.0.1', '2026-03-30 06:15:48'),
(770, 24, 'SEND_MESSAGE', 'Sent message to student ID: 26', '127.0.0.1', '2026-03-30 06:16:16'),
(771, 24, 'LOGIN', 'User logged in successfully: Venus Gabriel', '127.0.0.1', '2026-03-30 06:26:23'),
(772, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 06:27:03'),
(773, 11, 'LOGIN', 'User logged in successfully: Librarian Candijay', '127.0.0.1', '2026-03-30 06:27:12'),
(774, 11, 'APPROVE_CLEARANCE', 'Approved clearance ID: 114 for student: Venus Gabriel (134820)', '127.0.0.1', '2026-03-30 06:27:19'),
(775, 11, 'LOGOUT', 'User logged out: Librarian Candijay', '127.0.0.1', '2026-03-30 06:27:32'),
(776, 24, 'LOGOUT', 'User logged out: Venus Gabriel', '127.0.0.1', '2026-03-30 06:27:39'),
(777, 24, 'LOGIN', 'User logged in successfully: Venus Gabriel', '127.0.0.1', '2026-03-30 06:27:46'),
(778, 13, 'PROCESS_CLEARANCE', 'Approved clinic org clearance ID: 88 for student: Venus Gabriel (134820)', '127.0.0.1', '2026-03-30 06:28:06'),
(779, 13, 'LOGOUT', 'User logged out: Clinic', '127.0.0.1', '2026-03-30 06:28:10'),
(780, 14, 'LOGOUT', 'User logged out: Compass', '127.0.0.1', '2026-03-30 06:28:31'),
(781, 15, 'LOGOUT', 'User logged out: SSG', '127.0.0.1', '2026-03-30 06:29:02'),
(782, 12, 'LOGOUT', 'User logged out: Town Organization', '127.0.0.1', '2026-03-30 06:29:19'),
(783, 6, 'LOGIN', 'User logged in successfully: Elsa Cadorna', '127.0.0.1', '2026-03-30 06:29:29'),
(784, 6, 'APPROVE_CLEARANCE', 'Approved clearance ID: 115 for student: Venus Gabriel (134820)', '127.0.0.1', '2026-03-30 06:29:34'),
(785, 6, 'LOGOUT', 'User logged out: Elsa Cadorna', '127.0.0.1', '2026-03-30 06:29:50'),
(786, 12, 'LOGIN', 'User logged in successfully: Dean Candijay', '127.0.0.1', '2026-03-30 06:29:58'),
(787, 12, 'LACKING_COMMENT', 'Added lacking comment for clearance ID: 116 for student: Venus Gabriel. Comment: Wakay grado', '127.0.0.1', '2026-03-30 06:30:11'),
(788, 24, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 116 to Dean', '127.0.0.1', '2026-03-30 06:31:07'),
(789, 24, 'UPLOAD_PROOF', 'Uploaded proof for clearance ID: 116 to Dean', '127.0.0.1', '2026-03-30 06:31:12'),
(790, 12, 'APPROVE_CLEARANCE', 'Approved clearance ID: 116 for student: Venus Gabriel (134820)', '127.0.0.1', '2026-03-30 06:31:25'),
(791, 12, 'LOGOUT', 'User logged out: Dean Candijay', '127.0.0.1', '2026-03-30 06:31:38'),
(792, 13, 'LOGIN', 'User logged in successfully: Cashier Candijay', '127.0.0.1', '2026-03-30 06:31:48'),
(793, 13, 'APPROVE_CLEARANCE', 'Approved clearance ID: 117 for student: Venus Gabriel (134820)', '127.0.0.1', '2026-03-30 06:31:54'),
(794, 13, 'LOGOUT', 'User logged out: Cashier Candijay', '127.0.0.1', '2026-03-30 06:31:57'),
(795, 10, 'LOGIN', 'User logged in successfully: Registrar Candijay', '127.0.0.1', '2026-03-30 06:32:09'),
(796, 10, 'CLEARANCE_COMPLETED', 'Clearance completed for student: Venus Gabriel (134820) for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 06:32:14'),
(797, 10, 'APPROVE_CLEARANCE', 'Approved clearance ID: 118 for student: Venus Gabriel (134820)', '127.0.0.1', '2026-03-30 06:32:14'),
(798, 10, 'LOGOUT', 'User logged out: Registrar Candijay', '127.0.0.1', '2026-03-30 06:34:08'),
(799, 27, 'REGISTER', 'New student registered: Jhoel Kenneth Gulle', '127.0.0.1', '2026-03-30 06:46:50'),
(800, 27, 'LOGIN', 'User logged in successfully: Jhoel Kenneth Gulle', '127.0.0.1', '2026-03-30 06:49:06'),
(801, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 06:49:15'),
(802, 27, 'APPLY_CLEARANCE', 'Applied for clearance: non_graduating - 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 06:49:40'),
(803, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 06:49:47'),
(804, 15, 'LOGIN', 'User logged in successfully: Earl Gultia', '127.0.0.1', '2026-03-30 06:52:07'),
(805, 15, 'LOGOUT', 'User logged out: Earl Gultia', '127.0.0.1', '2026-03-30 06:53:29'),
(806, 27, 'CANCEL_CLEARANCE', 'Cancelled clearance application for 2nd Semester 2025-2026', '127.0.0.1', '2026-03-30 06:54:48');

-- --------------------------------------------------------

--
-- Table structure for table `clearance`
--

CREATE TABLE `clearance` (
  `clearance_id` int(11) NOT NULL,
  `clearance_name` varchar(100) DEFAULT NULL,
  `users_id` int(11) NOT NULL,
  `clearance_type_id` int(11) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `office_order` int(11) DEFAULT NULL,
  `office_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `lacking_comment` text DEFAULT NULL,
  `lacking_comment_at` datetime DEFAULT NULL,
  `lacking_comment_by` int(11) DEFAULT NULL,
  `proof_file` varchar(255) DEFAULT NULL,
  `proof_remarks` text DEFAULT NULL,
  `proof_uploaded_at` datetime DEFAULT NULL,
  `proof_uploaded_by` int(11) DEFAULT NULL,
  `student_proof_file` varchar(255) DEFAULT NULL,
  `student_proof_remarks` text DEFAULT NULL,
  `student_proof_uploaded_at` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_date` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `organization_status` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON data tracking organization approvals' CHECK (json_valid(`organization_status`)),
  `is_completed` tinyint(1) DEFAULT 0,
  `completed_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clearance`
--

INSERT INTO `clearance` (`clearance_id`, `clearance_name`, `users_id`, `clearance_type_id`, `semester`, `school_year`, `office_order`, `office_id`, `status`, `remarks`, `lacking_comment`, `lacking_comment_at`, `lacking_comment_by`, `proof_file`, `proof_remarks`, `proof_uploaded_at`, `proof_uploaded_by`, `student_proof_file`, `student_proof_remarks`, `student_proof_uploaded_at`, `processed_by`, `processed_date`, `created_at`, `updated_at`, `organization_status`, `is_completed`, `completed_date`) VALUES
(94, 'Clearance for Earl Gultia - 2026-03-30', 15, 2, '2nd Semester', '2025-2026', 1, 1, 'approved', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 11, '2026-03-30 01:17:07', '2026-03-30 01:15:50', '2026-03-30 01:17:07', NULL, 0, NULL),
(95, 'Clearance for Earl Gultia - 2026-03-30', 15, 2, '2nd Semester', '2025-2026', 2, 2, 'approved', ' | SAS Approved: ', 'Wakay attend2x', '2026-03-30 09:23:57', 12, NULL, NULL, NULL, NULL, 'uploads/proofs/student/proof_student_15_95_1774833886.jpg', 'Here\'s the proof', '2026-03-30 09:24:46', 6, '2026-03-30 02:12:15', '2026-03-30 01:15:50', '2026-03-30 02:12:15', NULL, 0, NULL),
(96, 'Clearance for Earl Gultia - 2026-03-30', 15, 2, '2nd Semester', '2025-2026', 3, 3, 'approved', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 12, '2026-03-30 02:13:02', '2026-03-30 01:15:50', '2026-03-30 02:13:02', NULL, 0, NULL),
(97, 'Clearance for Earl Gultia - 2026-03-30', 15, 2, '2nd Semester', '2025-2026', 4, 4, 'approved', ' | Cashier: ', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 13, '2026-03-30 02:13:36', '2026-03-30 01:15:50', '2026-03-30 02:13:36', NULL, 0, NULL),
(98, 'Clearance for Earl Gultia - 2026-03-30', 15, 2, '2nd Semester', '2025-2026', 5, 6, 'approved', NULL, 'Wakay Tuli', '2026-03-30 09:43:22', 13, NULL, NULL, NULL, NULL, 'uploads/proofs/student/proof_student_15_98_1774836890.jpg', '', '2026-03-30 10:14:50', 10, '2026-03-30 02:15:15', '2026-03-30 01:15:50', '2026-03-30 02:15:15', NULL, 0, NULL),
(99, 'Clearance for Angel Tutor - 2026-03-30', 21, 2, '2nd Semester', '2025-2026', 1, 1, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 02:28:54', '2026-03-30 02:28:54', NULL, 0, NULL),
(100, 'Clearance for Angel Tutor - 2026-03-30', 21, 2, '2nd Semester', '2025-2026', 2, 2, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 02:28:54', '2026-03-30 02:28:54', NULL, 0, NULL),
(101, 'Clearance for Angel Tutor - 2026-03-30', 21, 2, '2nd Semester', '2025-2026', 3, 3, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 02:28:54', '2026-03-30 02:28:54', NULL, 0, NULL),
(102, 'Clearance for Angel Tutor - 2026-03-30', 21, 2, '2nd Semester', '2025-2026', 4, 4, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 02:28:54', '2026-03-30 02:28:54', NULL, 0, NULL),
(103, 'Clearance for Angel Tutor - 2026-03-30', 21, 2, '2nd Semester', '2025-2026', 5, 6, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 02:28:54', '2026-03-30 02:28:54', NULL, 0, NULL),
(104, 'Clearance for Kristelle Joyce Lobo - 2026-03-30', 14, 2, '2nd Semester', '2025-2026', 1, 1, 'approved', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 11, '2026-03-30 03:50:36', '2026-03-30 03:50:04', '2026-03-30 03:50:36', NULL, 0, NULL),
(105, 'Clearance for Kristelle Joyce Lobo - 2026-03-30', 14, 2, '2nd Semester', '2025-2026', 2, 2, 'approved', ' | SAS Approved: Goods', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 6, '2026-03-30 03:59:04', '2026-03-30 03:50:04', '2026-03-30 03:59:04', NULL, 0, NULL),
(106, 'Clearance for Kristelle Joyce Lobo - 2026-03-30', 14, 2, '2nd Semester', '2025-2026', 3, 3, 'approved', NULL, 'Kuwang kag grades', '2026-03-30 11:59:40', 12, NULL, NULL, NULL, NULL, 'uploads/proofs/student/proof_student_14_106_1774843235.jpg', '', '2026-03-30 12:00:35', 12, '2026-03-30 04:01:05', '2026-03-30 03:50:04', '2026-03-30 04:01:05', NULL, 0, NULL),
(107, 'Clearance for Kristelle Joyce Lobo - 2026-03-30', 14, 2, '2nd Semester', '2025-2026', 4, 4, 'approved', ' | Cashier: ', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 13, '2026-03-30 04:01:50', '2026-03-30 03:50:04', '2026-03-30 04:01:50', NULL, 0, NULL),
(108, 'Clearance for Kristelle Joyce Lobo - 2026-03-30', 14, 2, '2nd Semester', '2025-2026', 5, 6, 'approved', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 10, '2026-03-30 04:02:10', '2026-03-30 03:50:04', '2026-03-30 04:02:10', NULL, 0, NULL),
(109, 'Clearance for KC ALICARTE - 2026-03-30', 23, 2, '2nd Semester', '2025-2026', 1, 1, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 06:02:30', '2026-03-30 06:02:30', NULL, 0, NULL),
(110, 'Clearance for KC ALICARTE - 2026-03-30', 23, 2, '2nd Semester', '2025-2026', 2, 2, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 06:02:30', '2026-03-30 06:02:30', NULL, 0, NULL),
(111, 'Clearance for KC ALICARTE - 2026-03-30', 23, 2, '2nd Semester', '2025-2026', 3, 3, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 06:02:30', '2026-03-30 06:02:30', NULL, 0, NULL),
(112, 'Clearance for KC ALICARTE - 2026-03-30', 23, 2, '2nd Semester', '2025-2026', 4, 4, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 06:02:30', '2026-03-30 06:02:30', NULL, 0, NULL),
(113, 'Clearance for KC ALICARTE - 2026-03-30', 23, 2, '2nd Semester', '2025-2026', 5, 6, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 06:02:30', '2026-03-30 06:02:30', NULL, 0, NULL),
(114, 'Clearance for Venus Gabriel - 2026-03-30', 24, 2, '2nd Semester', '2025-2026', 1, 1, 'approved', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 11, '2026-03-30 06:27:19', '2026-03-30 06:07:26', '2026-03-30 06:27:19', NULL, 0, NULL),
(115, 'Clearance for Venus Gabriel - 2026-03-30', 24, 2, '2nd Semester', '2025-2026', 2, 2, 'approved', ' | SAS Approved: ', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 6, '2026-03-30 06:29:34', '2026-03-30 06:07:26', '2026-03-30 06:29:34', NULL, 0, NULL),
(116, 'Clearance for Venus Gabriel - 2026-03-30', 24, 2, '2nd Semester', '2025-2026', 3, 3, 'approved', NULL, 'Wakay grado', '2026-03-30 14:30:11', 12, NULL, NULL, NULL, NULL, 'uploads/proofs/student/proof_student_24_116_1774852272.jpg', 'Done submitting', '2026-03-30 14:31:12', 12, '2026-03-30 06:31:25', '2026-03-30 06:07:26', '2026-03-30 06:31:25', NULL, 0, NULL),
(117, 'Clearance for Venus Gabriel - 2026-03-30', 24, 2, '2nd Semester', '2025-2026', 4, 4, 'approved', ' | Cashier: ', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 13, '2026-03-30 06:31:54', '2026-03-30 06:07:26', '2026-03-30 06:31:54', NULL, 0, NULL),
(118, 'Clearance for Venus Gabriel - 2026-03-30', 24, 2, '2nd Semester', '2025-2026', 5, 6, 'approved', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 10, '2026-03-30 06:32:14', '2026-03-30 06:07:26', '2026-03-30 06:32:14', NULL, 0, NULL);

--
-- Triggers `clearance`
--
DELIMITER $$
CREATE TRIGGER `after_clearance_insert` AFTER INSERT ON `clearance` FOR EACH ROW BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE org_id_val INT;
    DECLARE org_office_id_val INT;
    DECLARE sas_office_id INT;
    
    -- Declare cursor FIRST (before any other statements)
    DECLARE org_cursor CURSOR FOR 
        SELECT so.`org_id`, o.`office_id`
        FROM `student_organizations` so
        JOIN `offices` o ON so.`office_id` = o.`office_id`
        WHERE so.`status` = 'active'
        AND o.`parent_office_id` = sas_office_id;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    -- Get SAS office ID (after declarations)
    SELECT `office_id` INTO sas_office_id FROM `offices` WHERE `office_name` = 'Director_SAS' LIMIT 1;
    
    -- Only create organization records for SAS office clearances
    IF NEW.office_id = sas_office_id THEN
        
        OPEN org_cursor;
        
        read_loop: LOOP
            FETCH org_cursor INTO org_id_val, org_office_id_val;
            IF done THEN
                LEAVE read_loop;
            END IF;
            
            -- Insert into organization_clearance for each active organization
            INSERT IGNORE INTO `organization_clearance` 
                (`clearance_id`, `org_id`, `office_id`, `status`, `created_at`, `updated_at`)
            VALUES 
                (NEW.`clearance_id`, org_id_val, org_office_id_val, 'pending', NOW(), NOW());
            
        END LOOP;
        
        CLOSE org_cursor;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `clearance_flow_template`
--

CREATE TABLE `clearance_flow_template` (
  `flow_id` int(11) NOT NULL,
  `clearance_type_id` int(11) NOT NULL,
  `office_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL,
  `is_mandatory` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clearance_flow_template`
--

INSERT INTO `clearance_flow_template` (`flow_id`, `clearance_type_id`, `office_id`, `step_order`, `is_mandatory`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 1, '2026-03-15 13:50:03', '2026-03-15 13:50:03'),
(2, 1, 2, 2, 1, '2026-03-15 13:50:03', '2026-03-15 13:50:03'),
(3, 1, 3, 3, 1, '2026-03-15 13:50:03', '2026-03-15 13:50:03'),
(4, 1, 4, 4, 1, '2026-03-15 13:50:03', '2026-03-15 13:50:03'),
(5, 1, 6, 5, 1, '2026-03-15 13:50:03', '2026-03-15 13:50:03'),
(8, 2, 1, 1, 1, '2026-03-15 13:50:03', '2026-03-15 13:50:03'),
(9, 2, 2, 2, 1, '2026-03-15 13:50:03', '2026-03-15 13:50:03'),
(10, 2, 3, 3, 1, '2026-03-15 13:50:03', '2026-03-15 13:50:03'),
(11, 2, 4, 4, 1, '2026-03-15 13:50:03', '2026-03-15 13:50:03'),
(12, 2, 6, 5, 1, '2026-03-15 13:50:03', '2026-03-15 13:50:03');

-- --------------------------------------------------------

--
-- Table structure for table `clearance_type`
--

CREATE TABLE `clearance_type` (
  `clearance_type_id` int(11) NOT NULL,
  `clearance_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clearance_type`
--

INSERT INTO `clearance_type` (`clearance_type_id`, `clearance_name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'graduating', 'For graduating students completing their degree', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(2, 'non_graduating', 'For continuing students', '2026-02-15 12:46:58', '2026-02-15 12:46:58');

-- --------------------------------------------------------

--
-- Table structure for table `clinic_records`
--

CREATE TABLE `clinic_records` (
  `record_id` int(11) NOT NULL,
  `users_id` int(11) NOT NULL,
  `medical_clearance` tinyint(1) DEFAULT 0,
  `clearance_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `college`
--

CREATE TABLE `college` (
  `college_id` int(11) NOT NULL,
  `college_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `college`
--

INSERT INTO `college` (`college_id`, `college_name`, `created_at`, `updated_at`) VALUES
(1, 'College of Sciences', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(2, 'College of Teachers Education', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(3, 'College of Business Management', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(4, 'College of Fishes and Marine Sciences', '2026-02-15 12:46:58', '2026-02-15 12:46:58');

-- --------------------------------------------------------

--
-- Table structure for table `course`
--

CREATE TABLE `course` (
  `course_id` int(11) NOT NULL,
  `course_name` varchar(100) NOT NULL,
  `course_code` varchar(20) DEFAULT NULL,
  `college_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course`
--

INSERT INTO `course` (`course_id`, `course_name`, `course_code`, `college_id`, `created_at`, `updated_at`) VALUES
(1, 'Computer Science', 'BSCS', 1, '2026-02-15 14:47:28', '2026-02-15 14:47:28'),
(8, 'Environmental Science', 'BSES', 1, '2026-02-15 14:47:28', '2026-02-15 14:47:28'),
(9, 'Elementary Education', 'BEEd', 2, '2026-02-15 14:47:28', '2026-02-15 14:47:28'),
(10, 'Secondary Education', 'BSEd', 2, '2026-02-15 14:47:28', '2026-02-15 14:47:28'),
(20, 'Office Administration', 'BSOA', 3, '2026-02-15 14:47:28', '2026-02-15 14:47:28'),
(21, 'Hotel and Restaurant Management', 'BSHRM', 3, '2026-02-15 14:47:28', '2026-02-15 14:47:28'),
(28, 'Human Resource Management', 'BSHRM', 3, '2026-02-15 14:47:28', '2026-02-15 14:47:28'),
(29, 'Fisheries', 'BSF', 4, '2026-02-15 14:47:28', '2026-02-15 14:47:28'),
(30, 'Marine Biology', 'BSMB', 4, '2026-02-15 14:47:28', '2026-02-15 14:47:28');

-- --------------------------------------------------------

--
-- Table structure for table `department_chairpersons`
--

CREATE TABLE `department_chairpersons` (
  `chairperson_id` int(11) NOT NULL,
  `users_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `college_id` int(11) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `offices`
--

CREATE TABLE `offices` (
  `office_id` int(11) NOT NULL,
  `office_name` varchar(100) NOT NULL,
  `office_description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `office_order` int(11) DEFAULT 0 COMMENT 'Order in clearance flow',
  `is_active` tinyint(1) DEFAULT 1,
  `parent_office_id` int(11) DEFAULT NULL,
  `office_type` enum('main','sub','organization') DEFAULT 'main'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `offices`
--

INSERT INTO `offices` (`office_id`, `office_name`, `office_description`, `created_at`, `updated_at`, `office_order`, `is_active`, `parent_office_id`, `office_type`) VALUES
(1, 'Librarian', 'Library Office - handles library clearance', '2026-02-15 12:46:58', '2026-03-15 13:45:56', 1, 1, NULL, 'main'),
(2, 'Director_SAS', 'Director of Student Affairs and Services - Handles student organizations', '2026-02-15 12:46:58', '2026-03-15 13:45:56', 2, 1, NULL, 'main'),
(3, 'Dean', 'Dean\'s Office - handles college-level clearance', '2026-02-15 12:46:58', '2026-03-15 13:45:56', 3, 1, NULL, 'main'),
(4, 'Cashier', 'Cashier\'s Office - final clearance office', '2026-02-15 12:46:58', '2026-03-15 13:45:56', 4, 1, NULL, 'main'),
(5, 'Management Information System', NULL, '2026-02-16 05:50:07', '2026-03-15 13:50:03', 6, 1, NULL, 'sub'),
(6, 'Registrar', 'Registrar - Handles accepting clearance and overall viewing', '2026-02-27 23:33:02', '2026-03-15 13:45:56', 5, 1, NULL, 'main'),
(7, 'Town Organizations', 'Town-based student organizations', '2026-03-15 13:50:04', '2026-03-15 13:50:04', 21, 1, 2, 'organization'),
(8, 'College Organizations', 'College-based student organizations', '2026-03-15 13:50:04', '2026-03-15 13:50:04', 22, 1, 2, 'organization'),
(9, 'Supreme Student Council', 'Supreme Student Government', '2026-03-15 13:50:04', '2026-03-15 13:50:04', 23, 1, 2, 'organization');

-- --------------------------------------------------------

--
-- Table structure for table `organization_clearance`
--

CREATE TABLE `organization_clearance` (
  `org_clearance_id` int(11) NOT NULL,
  `clearance_id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `office_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `student_proof_file` varchar(255) DEFAULT NULL,
  `student_proof_remarks` text DEFAULT NULL,
  `student_proof_uploaded_at` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `organization_clearance`
--

INSERT INTO `organization_clearance` (`org_clearance_id`, `clearance_id`, `org_id`, `office_id`, `status`, `remarks`, `student_proof_file`, `student_proof_remarks`, `student_proof_uploaded_at`, `processed_by`, `processed_date`, `created_at`, `updated_at`) VALUES
(69, 95, 12, 7, 'approved', ' | Town Org: ', NULL, NULL, NULL, 12, '2026-03-30 09:27:47', '2026-03-30 01:15:50', '2026-03-30 01:27:47'),
(70, 95, 14, 8, 'approved', ' | College Org: ', NULL, NULL, NULL, 14, '2026-03-30 10:11:26', '2026-03-30 01:15:50', '2026-03-30 02:11:26'),
(71, 95, 15, 9, 'approved', ' | SSG: ', NULL, NULL, NULL, 15, '2026-03-30 10:11:46', '2026-03-30 01:15:50', '2026-03-30 02:11:46'),
(72, 95, 13, 6, 'approved', ' | Clinic: ', NULL, NULL, NULL, 13, '2026-03-30 10:10:58', '2026-03-30 01:15:50', '2026-03-30 02:10:58'),
(73, 100, 12, 7, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 02:28:54', '2026-03-30 02:28:54'),
(74, 100, 14, 8, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 02:28:54', '2026-03-30 02:28:54'),
(75, 100, 15, 9, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 02:28:54', '2026-03-30 02:28:54'),
(76, 100, 13, 6, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 02:28:54', '2026-03-30 02:28:54'),
(77, 105, 12, 7, 'approved', '[ORG_LACKING] Pang hinlo | Town Org: ', 'uploads/proofs/student/proof_student_14_105_1774843057.jpg', '[ORG_PROOF] Submitted for Town Organization', '2026-03-30 11:57:37', 12, '2026-03-30 11:58:03', '2026-03-30 03:50:04', '2026-03-30 03:58:03'),
(78, 105, 14, 8, 'approved', '[ORG_LACKING] Wakay sulod2x | College Org: ', 'uploads/proofs/student/proof_student_14_105_1774842845.png', '[ORG_PROOF] Submitted for Compass', '2026-03-30 11:54:05', 14, '2026-03-30 11:54:40', '2026-03-30 03:50:04', '2026-03-30 03:54:40'),
(79, 105, 15, 9, 'approved', '[ORG_LACKING] Waka ka bayad | SSG: ', 'uploads/proofs/student/proof_student_14_105_1774842962.jpg', '[ORG_PROOF] Submitted for SSG', '2026-03-30 11:56:02', 15, '2026-03-30 11:56:32', '2026-03-30 03:50:04', '2026-03-30 03:56:32'),
(80, 105, 13, 6, 'approved', '[CLINIC_LACKING] Waka kabayad | Clinic: ', 'uploads/proofs/student/proof_student_14_105_1774842699.jpg', '[ORG_PROOF] Submitted for Clinic', '2026-03-30 11:51:39', 13, '2026-03-30 11:52:24', '2026-03-30 03:50:04', '2026-03-30 03:52:24'),
(81, 110, 12, 7, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 06:02:30', '2026-03-30 06:02:30'),
(82, 110, 14, 8, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 06:02:30', '2026-03-30 06:02:30'),
(83, 110, 15, 9, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 06:02:30', '2026-03-30 06:02:30'),
(84, 110, 13, 6, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 06:02:30', '2026-03-30 06:02:30'),
(85, 115, 12, 7, 'approved', ' | Town Org: ', NULL, NULL, NULL, 12, '2026-03-30 14:29:15', '2026-03-30 06:07:26', '2026-03-30 06:29:15'),
(86, 115, 14, 8, 'approved', ' | College Org: ', NULL, NULL, NULL, 14, '2026-03-30 14:28:26', '2026-03-30 06:07:26', '2026-03-30 06:28:26'),
(87, 115, 15, 9, 'approved', ' | SSG: ', NULL, NULL, NULL, 15, '2026-03-30 14:28:58', '2026-03-30 06:07:26', '2026-03-30 06:28:58'),
(88, 115, 13, 6, 'approved', ' | Clinic: ', NULL, NULL, NULL, 13, '2026-03-30 14:28:06', '2026-03-30 06:07:26', '2026-03-30 06:28:06');

-- --------------------------------------------------------

--
-- Table structure for table `organization_dashboard_views`
--

CREATE TABLE `organization_dashboard_views` (
  `view_id` int(11) NOT NULL,
  `dashboard_type` varchar(50) NOT NULL COMMENT 'clinic, town, college, ssg',
  `view_name` varchar(100) NOT NULL,
  `view_file` varchar(255) NOT NULL COMMENT 'Path to the dashboard file',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `organization_dashboard_views`
--

INSERT INTO `organization_dashboard_views` (`view_id`, `dashboard_type`, `view_name`, `view_file`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'clinic', 'Clinic Dashboard', 'organization/clinic_dashboard.php', 'Dashboard for clinic organizations', 1, '2026-03-15 14:38:50', '2026-03-15 14:38:50'),
(2, 'town', 'Town Organizations Dashboard', 'organization/town_dashboard.php', 'Dashboard for town-based organizations', 1, '2026-03-15 14:38:50', '2026-03-15 14:38:50'),
(3, 'college', 'College Organizations Dashboard', 'organization/college_dashboard.php', 'Dashboard for college-based organizations', 1, '2026-03-15 14:38:50', '2026-03-15 14:38:50'),
(4, 'ssg', 'SSG Dashboard', 'organization/ssg_dashboard.php', 'Dashboard for Supreme Student Government', 1, '2026-03-15 14:38:50', '2026-03-15 14:38:50');

-- --------------------------------------------------------

--
-- Table structure for table `organization_settings`
--

CREATE TABLE `organization_settings` (
  `setting_id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `reset_id` int(11) NOT NULL,
  `account_email` varchar(255) NOT NULL,
  `user_type` enum('user','organization') NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`reset_id`, `account_email`, `user_type`, `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at`) VALUES
(1, 'earl.gultia@bisu.edu.ph', 'user', 15, '7d90268badec185005756bb12befd4a701934f0c593321c566bdc5569e18fb42', '2026-03-22 13:26:11', '2026-03-22 12:26:46', '2026-03-22 12:26:11');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `session_id` varchar(128) NOT NULL,
  `session_data` text DEFAULT NULL,
  `session_expires` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_friendships`
--

CREATE TABLE `student_friendships` (
  `friendship_id` int(11) NOT NULL,
  `user_one_id` int(11) NOT NULL,
  `user_two_id` int(11) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'accepted',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `accepted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_friendships`
--

INSERT INTO `student_friendships` (`friendship_id`, `user_one_id`, `user_two_id`, `requested_by`, `status`, `created_at`, `accepted_at`) VALUES
(1, 14, 15, 14, 'accepted', '2026-03-22 13:31:40', '2026-03-22 13:49:20'),
(2, 15, 24, 24, 'accepted', '2026-03-30 14:09:16', '2026-03-30 14:09:29'),
(3, 24, 26, 24, 'accepted', '2026-03-30 14:12:09', '2026-03-30 14:12:28');

-- --------------------------------------------------------

--
-- Table structure for table `student_messages`
--

CREATE TABLE `student_messages` (
  `message_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `message_body` text NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_messages`
--

INSERT INTO `student_messages` (`message_id`, `sender_id`, `recipient_id`, `message_body`, `sent_at`, `read_at`) VALUES
(1, 15, 14, 'Hi', '2026-03-22 13:47:31', '2026-03-22 13:52:53'),
(4, 15, 14, 'hi', '2026-03-22 13:50:20', '2026-03-22 13:52:53'),
(5, 14, 15, 'hello', '2026-03-22 13:53:09', '2026-03-22 13:53:52'),
(6, 15, 14, 'Try sad message arhi if madawat ba nimo', '2026-03-30 12:06:40', '2026-03-30 12:14:01'),
(7, 14, 15, 'Oh', '2026-03-30 12:14:34', '2026-03-30 12:15:10'),
(8, 15, 14, 'Wow🤣', '2026-03-30 12:15:24', '2026-03-30 12:17:28'),
(9, 15, 14, 'Hi gwapa😍🤣', '2026-03-30 12:53:41', '2026-03-30 13:13:27'),
(10, 24, 15, 'Hi', '2026-03-30 14:09:41', '2026-03-30 14:09:45'),
(11, 24, 26, 'Hi indayin hahaha', '2026-03-30 14:12:57', '2026-03-30 14:14:33'),
(12, 26, 24, 'hello ate HAHHAHAHA', '2026-03-30 14:15:48', '2026-03-30 14:16:02'),
(13, 24, 26, 'Ahhahaha', '2026-03-30 14:16:16', '2026-03-30 14:16:26');

-- --------------------------------------------------------

--
-- Table structure for table `student_organizations`
--

CREATE TABLE `student_organizations` (
  `org_id` int(11) NOT NULL,
  `org_name` varchar(100) NOT NULL,
  `org_type` enum('town','college','clinic','ssg') NOT NULL,
  `college_id` int(11) DEFAULT NULL,
  `org_email` varchar(100) NOT NULL,
  `org_password` varchar(255) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL COMMENT 'ID of sub_admin who created this organization',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `office_id` int(11) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `login_count` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `dashboard_type` varchar(50) DEFAULT 'organization' COMMENT 'Type of dashboard to load',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON settings for organization dashboard' CHECK (json_valid(`settings`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_organizations`
--

INSERT INTO `student_organizations` (`org_id`, `org_name`, `org_type`, `college_id`, `org_email`, `org_password`, `status`, `created_by`, `created_at`, `updated_at`, `office_id`, `last_login`, `login_count`, `description`, `dashboard_type`, `settings`) VALUES
(12, 'Town Organization', 'town', NULL, 'town.org@bisu.edu.ph', '$2y$12$CENE5F3fp8ELCoDqcIV6w.pKxco/Kd2pWX2AUEKo.xRoMAYS2a.pK', 'active', 6, '2026-03-15 14:57:31', '2026-03-30 06:29:09', 7, '2026-03-30 14:29:09', 20, NULL, 'town', NULL),
(13, 'Clinic', 'clinic', NULL, 'clinic@bisu.edu.ph', '$2y$12$18k./ADY1G3V6Ggd3iPeR.ExfeIk7DGYydlX/cdH8Hk2MhlSC1OOi', 'active', 6, '2026-03-15 14:58:29', '2026-03-30 06:27:39', 6, '2026-03-30 14:27:39', 24, NULL, 'clinic', NULL),
(14, 'Compass', 'college', 1, 'compass@bisu.edu.ph', '$2y$12$fOUgR5..LASzwvSI4jLkUuyBffSBnuap.i5TJ/CCd/5f1DgvkUdaq', 'active', 6, '2026-03-15 21:43:45', '2026-03-30 06:28:17', 8, '2026-03-30 14:28:17', 22, NULL, 'college', NULL),
(15, 'SSG', 'ssg', NULL, 'ssg.candijay@bisu.edu.ph', '$2y$12$XK.N5Pd7pUSN7Ya1DjnIQOMuoJl0rTWEmA5HRi87p8kPKzFaPBIXy', 'active', 6, '2026-03-16 02:45:23', '2026-03-30 06:28:52', 9, '2026-03-30 14:28:52', 12, NULL, 'ssg', NULL);

--
-- Triggers `student_organizations`
--
DELIMITER $$
CREATE TRIGGER `after_organization_insert` AFTER INSERT ON `student_organizations` FOR EACH ROW BEGIN
    -- Automatically set dashboard_type based on org_type if not provided
    IF NEW.`dashboard_type` IS NULL THEN
        UPDATE `student_organizations` 
        SET `dashboard_type` = NEW.`org_type` 
        WHERE `org_id` = NEW.`org_id`;
    END IF;
    
    -- Link to office if not already linked
    IF NEW.`office_id` IS NULL THEN
        CASE NEW.`org_type`
            WHEN 'clinic' THEN
                UPDATE `student_organizations` SET `office_id` = 6 WHERE `org_id` = NEW.`org_id`;
            WHEN 'town' THEN
                UPDATE `student_organizations` SET `office_id` = 7 WHERE `org_id` = NEW.`org_id`;
            WHEN 'college' THEN
                UPDATE `student_organizations` SET `office_id` = 8 WHERE `org_id` = NEW.`org_id`;
            WHEN 'ssg' THEN
                UPDATE `student_organizations` SET `office_id` = 9 WHERE `org_id` = NEW.`org_id`;
            ELSE
                -- For other types, try to find matching office by name
                UPDATE `student_organizations` so
                JOIN `offices` o ON o.`office_name` LIKE CONCAT('%', NEW.`org_type`, '%')
                SET so.`office_id` = o.`office_id`
                WHERE so.`org_id` = NEW.`org_id`
                LIMIT 1;
        END CASE;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `sub_admin_offices`
--

CREATE TABLE `sub_admin_offices` (
  `sub_admin_office_id` int(11) NOT NULL,
  `users_id` int(11) NOT NULL,
  `office_id` int(11) NOT NULL,
  `can_create_accounts` tinyint(1) DEFAULT 1,
  `can_manage_organizations` tinyint(1) DEFAULT 1 COMMENT 'Permission to manage student organizations',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sub_admin_offices`
--

INSERT INTO `sub_admin_offices` (`sub_admin_office_id`, `users_id`, `office_id`, `can_create_accounts`, `can_manage_organizations`, `created_at`) VALUES
(2, 6, 2, 1, 1, '2026-02-16 04:32:51'),
(3, 8, 5, 1, 0, '2026-02-16 05:51:00'),
(4, 10, 6, 1, 0, '2026-02-28 03:56:00'),
(5, 11, 1, 1, 1, '2026-03-08 14:44:53'),
(6, 12, 3, 1, 1, '2026-03-08 15:17:56'),
(7, 13, 4, 1, 1, '2026-03-08 16:17:52'),
(8, 6, 8, 1, 1, '2026-03-15 13:50:04'),
(9, 6, 9, 1, 1, '2026-03-15 13:50:04'),
(10, 6, 7, 1, 1, '2026-03-15 13:50:04');

-- --------------------------------------------------------

--
-- Table structure for table `sub_offices`
--

CREATE TABLE `sub_offices` (
  `sub_office_id` int(11) NOT NULL,
  `sub_office_name` varchar(100) NOT NULL,
  `parent_office_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sub_offices`
--

INSERT INTO `sub_offices` (`sub_office_id`, `sub_office_name`, `parent_office_id`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Town Organizations', 2, 'Town-based student organizations', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(2, 'Clinic', 2, 'University Clinic', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(3, 'College Organizations', 2, 'College-based student organizations', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(4, 'Supreme Student Council', 2, 'SSC Office', '2026-02-15 12:46:58', '2026-02-15 12:46:58');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `users_id` int(11) NOT NULL,
  `fname` varchar(50) NOT NULL,
  `lname` varchar(50) NOT NULL,
  `address` text DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `contacts` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `emails` varchar(100) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `ismis_id` varchar(50) DEFAULT NULL,
  `user_role_id` int(11) DEFAULT NULL,
  `college_id` int(11) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `office_id` int(11) DEFAULT NULL,
  `assignment` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`users_id`, `fname`, `lname`, `address`, `age`, `contacts`, `password`, `emails`, `profile_picture`, `ismis_id`, `user_role_id`, `college_id`, `course_id`, `year_level`, `office_id`, `assignment`, `is_active`, `created_at`, `updated_at`) VALUES
(3, 'Super', 'Admin', 'BISU Main Campus', 35, '09123456789', '$2y$10$Zxlwd.fQickCLuyFROTBYO.o9Xttlj1FJm4FbY.CULg3HcYMZki3G', 'superadmin@bisu.edu', 'uploads/avatars/avatar_3_1772237984.jfif', 'SA-2024-001', 1, NULL, NULL, NULL, NULL, NULL, 1, '2026-02-15 15:21:11', '2026-02-28 00:19:44'),
(6, 'Elsa', 'Cadorna', 'BISU Candijay Campus', 45, '09123456788', '$2y$12$.pw10wTAaQG8tJt0l.xmq.zvuAevSDuyf9bNl1DRSiFlD8HceeFcK', 'sas.director@bisu.edu.ph', 'uploads/avatars/avatar_6_69c9d2ee4b3d4458856387.jpg', 'SAS-001', 2, NULL, NULL, NULL, 2, NULL, 1, '2026-02-16 04:32:51', '2026-03-30 01:33:34'),
(8, 'MIS', 'Candijay', 'BISU Candijay Campus', 40, '09123456787', '$2y$12$a2O0MD4KbzeFCdWXgpW7B.pHV.U8HgVkuXW8F8nxYKfa.uDdSXg0.', 'mis.candijay@bisu.edu.ph', NULL, 'MIS-001', 2, NULL, NULL, NULL, 5, NULL, 1, '2026-02-16 05:51:00', '2026-02-16 05:51:00'),
(10, 'Registrar', 'Candijay', 'Cogtong Candijay, Bohol', 38, '09123456786', '$2y$12$GFW.BqiO7huZ6vQr9dM1AuGv5nCXgcOn.hryUz3ORA5t9MjqCZqbW', 'registrar.candijay@bisu.edu.ph', NULL, 'REG-001', 2, NULL, NULL, NULL, 6, NULL, 1, '2026-02-28 03:56:00', '2026-02-28 03:56:00'),
(11, 'Librarian', 'Candijay', 'Cogtong, Candijay, Bohol', NULL, '', '$2y$12$lKpdJy.Qki9yMBk7MlSBae7TjpO7Pq6vA1drkpSa.ThpHYa/96XM2', 'librarian.candijay@bisu.edu.ph', 'uploads/avatars/avatar_11_69b9fbb98c55b445900489.jpg', NULL, 2, NULL, NULL, NULL, 1, NULL, 1, '2026-03-08 14:44:53', '2026-03-18 01:11:21'),
(12, 'Dean', 'Candijay', 'Cogtong, Candijay, Bohol', NULL, '', '$2y$12$jpVrY6elPoHRC4933cko6.hSIP8kuc4W11S34xxrVkIzgJQJAgKQu', 'dean.candijay@bisu.edu.ph', NULL, NULL, 2, NULL, NULL, NULL, 3, NULL, 1, '2026-03-08 15:17:56', '2026-03-08 15:17:56'),
(13, 'Cashier', 'Candijay', 'Cogtong, Candijay, Bohol', NULL, '', '$2y$12$LtXX026YRelUqwM4XwmnuOf0aLWN.LwYuVQLqXk1WS4N6XgazSZXG', 'cashier.candijay@bisu.edu.ph', 'uploads/avatars/avatar_13_69ba21b4783e7104912562.jpg', NULL, 2, NULL, NULL, NULL, 4, NULL, 1, '2026-03-08 16:17:52', '2026-03-18 03:53:24'),
(14, 'Kristelle', 'Joyce Lobo', 'Purok 2, Cansuhay, Duero, Bohol', 24, '09518967986', '$2y$12$kiCiBBfd9P3bnpv8OinWOeuJSL1qCDkjDU47Q6FKLBSt50HwhNGya', 'kristellejoyce.lobo@bisu.edu.ph', 'uploads/avatars/avatar_14_69c9fe4ec3cf0039241484.jpg', '937432', 4, 1, 8, '1st Year', NULL, NULL, 1, '2026-03-15 14:18:47', '2026-03-30 05:12:56'),
(15, 'Earl', 'Gultia', 'Purok 3, Alejawan Lutao, Duero, Bohol', 25, '09944462851', '$2y$10$RYMEGIdrM32gMJ3u34CxRO8RafYGDq27/F.ag3LpTYEGbTryqjWvy', 'earl.gultia@bisu.edu.ph', 'uploads/avatars/avatar_15_69ba6208d5d61700281809.jpg', '325681', 4, 1, 1, '1st Year', NULL, NULL, 1, '2026-03-15 15:35:52', '2026-03-22 04:26:46'),
(18, 'Jessica', 'Mapute', 'Mabago, Catungawan Sur, Guindulman Bohol', 22, '09927236148', '$2y$12$bmUTOBCyenxdDCO6l0N4juCAd5Du9/UMrBwPgzuPzrNyAsjo6hpWK', 'jessica.mapute@bisu.edu.ph', 'uploads/avatars/avatar_18_69bcbf60139cb519878668.jpg', '483633', 4, 1, 1, NULL, NULL, NULL, 1, '2026-03-20 03:28:50', '2026-03-20 03:30:40'),
(20, 'Jaye Melle', 'Dela Peña', 'Calanggaman, Ubay, Bohol', 22, '09932775588', '$2y$12$CWQOS3nMnH43WaRXNATW1OiFr0eDaJL9quK/ceMHXce207PFtswDe', 'jayemelle.delapena@bisu.edu.ph', NULL, '992456', 4, 1, 1, NULL, NULL, NULL, 1, '2026-03-25 05:57:05', '2026-03-25 05:57:05'),
(21, 'Angel', 'Tutor', 'Bulawan Mabini Bohol', 21, '099786512578', '$2y$12$bhNuzUPTv1eunHyXbsyNiuzPDHzwWS6FRm.tnV8o0ftwm45fWuToO', 'angel.tutor@bisu.edu.ph', 'uploads/avatars/avatar_21_69c9e0833b706873949132.jpg', '244087', 4, 1, 1, NULL, NULL, NULL, 1, '2026-03-30 02:27:54', '2026-03-30 02:31:31'),
(22, 'Myla', 'Peleño', 'Canawa, Candijay, Bohol', 21, '09934208188', '$2y$12$6MjMYDbdbFlh9WhIi3ipHOdYJdm19/m4s1YHbvdqxn/tvgzL20U5e', 'myla.peleno@bisu.edu.ph', 'uploads/avatars/avatar_22_69ca07316f522022879335.jpg', '313202', 4, 1, 8, NULL, NULL, NULL, 1, '2026-03-30 05:08:13', '2026-03-30 05:16:33'),
(23, 'KC', 'ALICARTE', 'Purok 1 Biabas, Ubay, Bohol', 20, '09656089278', '$2y$12$mhAVIi7ffAGz.ink9Gdxg.cMrrI6ptANx/tzUZhlKfVAZkRJ06e2e', 'kc.alicarte@bisu.edu.ph', 'uploads/avatars/avatar_23_69ca1212e0afb925386399.png', '599206', 4, 1, 1, NULL, NULL, NULL, 1, '2026-03-30 06:01:39', '2026-03-30 06:02:58'),
(24, 'Venus', 'Gabriel', 'Purok 6 Lundag, Anda, Bohol', 23, '09948556819', '$2y$12$Wc8bUGHEFLut43RJpj/XTOTpki8pw2N2XUaBCZP2JeiuDHoN6Od7K', 'venus.pelin@bisu.edu.ph', 'uploads/avatars/avatar_24_69ca130a6b653669795690.jpg', '134820', 4, 1, 1, NULL, NULL, NULL, 1, '2026-03-30 06:04:51', '2026-03-30 06:07:06'),
(25, 'Mary Ann', 'Butong', 'Purok 6 Tubod , Candijay,Bohol', 21, '09933994899', '$2y$12$hCCA9N3NoRkhtpxKkY4uMemaY4DAGQ.DDIsiliTQj073FvkvDhfnK', 'maryann.butong@bisu.edu.ph', NULL, '961758', 4, 4, 29, NULL, NULL, NULL, 1, '2026-03-30 06:05:34', '2026-03-30 06:05:34'),
(26, 'ROSELLE', 'MERENILLO', 'BIABAS GUINDULMAN BOHOL', 19, '09910354251', '$2y$12$IGe8t8sGsKDOAarOJCElRefY.zMy7Fqf89WjTRxAdumC9tAY.og4.', 'roselle.merenillo@bisu.edu.ph', NULL, '686475', 4, 4, 29, NULL, NULL, NULL, 1, '2026-03-30 06:07:28', '2026-03-30 06:07:28'),
(27, 'Jhoel Kenneth', 'Gulle', 'Cogtong, Candijay, Bohol', 29, '0993 123 1234', '$2y$12$G0AMt2sUzaYEMDTImGIDC.jHDtXiZQvKGOEFlSBQSpUsw/JMD/3c6', 'jhoelkenneth.gulle@bisu.edu.ph', NULL, '069701', 4, 1, 1, NULL, NULL, NULL, 1, '2026-03-30 06:46:50', '2026-03-30 06:46:50');

-- --------------------------------------------------------

--
-- Table structure for table `user_role`
--

CREATE TABLE `user_role` (
  `user_role_id` int(11) NOT NULL,
  `user_role_name` varchar(50) NOT NULL,
  `user_description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_role`
--

INSERT INTO `user_role` (`user_role_id`, `user_role_name`, `user_description`, `created_at`, `updated_at`) VALUES
(1, 'super_admin', 'System Administrator with full access', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(2, 'sub_admin', 'Office Administrator (Librarian, Director SAS, Dean, Cashier)', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(3, 'office_staff', 'Staff member under an office', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(4, 'student', 'Regular student requesting clearance', '2026-02-15 12:46:58', '2026-02-15 12:46:58'),
(5, 'organization', 'Student organization account', '2026-03-01 12:30:00', '2026-03-01 12:30:00');

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_clearance_progress`
-- (See below for the actual view)
--
CREATE TABLE `view_clearance_progress` (
`clearance_id` int(11)
,`users_id` int(11)
,`student_name` varchar(101)
,`ismis_id` varchar(50)
,`course_name` varchar(100)
,`college_name` varchar(100)
,`clearance_type` varchar(50)
,`semester` varchar(20)
,`school_year` varchar(20)
,`main_status` enum('pending','approved','rejected')
,`applied_date` timestamp
,`librarian_status` varchar(8)
,`sas_status` varchar(8)
,`dean_status` varchar(8)
,`cashier_status` varchar(8)
,`registrar_status` varchar(8)
,`orgs_approved` bigint(21)
,`total_orgs` bigint(21)
,`progress_status` varchar(9)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_organization_summary`
-- (See below for the actual view)
--
CREATE TABLE `view_organization_summary` (
`org_id` int(11)
,`org_name` varchar(100)
,`org_type` enum('town','college','clinic','ssg')
,`org_email` varchar(100)
,`status` enum('active','inactive')
,`dashboard_type` varchar(50)
,`office_id` int(11)
,`office_name` varchar(100)
,`office_description` text
,`parent_office_id` int(11)
,`parent_office_name` varchar(100)
,`last_login` datetime
,`login_count` int(11)
,`created_at` timestamp
,`pending_clearances` bigint(21)
,`approved_clearances` bigint(21)
,`dashboard_file` varchar(255)
);

-- --------------------------------------------------------

--
-- Structure for view `view_clearance_progress`
--
DROP TABLE IF EXISTS `view_clearance_progress`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_clearance_progress`  AS SELECT `c`.`clearance_id` AS `clearance_id`, `c`.`users_id` AS `users_id`, concat(`u`.`fname`,' ',`u`.`lname`) AS `student_name`, `u`.`ismis_id` AS `ismis_id`, `cr`.`course_name` AS `course_name`, `col`.`college_name` AS `college_name`, `ct`.`clearance_name` AS `clearance_type`, `c`.`semester` AS `semester`, `c`.`school_year` AS `school_year`, `c`.`status` AS `main_status`, `c`.`created_at` AS `applied_date`, max(case when `o`.`office_name` = 'Librarian' then `c_office`.`status` end) AS `librarian_status`, max(case when `o`.`office_name` = 'Director_SAS' then `c_office`.`status` end) AS `sas_status`, max(case when `o`.`office_name` = 'Dean' then `c_office`.`status` end) AS `dean_status`, max(case when `o`.`office_name` = 'Cashier' then `c_office`.`status` end) AS `cashier_status`, max(case when `o`.`office_name` = 'Registrar' then `c_office`.`status` end) AS `registrar_status`, (select count(0) from `organization_clearance` `oc` where `oc`.`clearance_id` = `c`.`clearance_id` and `oc`.`status` = 'approved') AS `orgs_approved`, (select count(0) from `organization_clearance` `oc` where `oc`.`clearance_id` = `c`.`clearance_id`) AS `total_orgs`, CASE WHEN `c`.`status` = 'approved' AND (select count(0) from `organization_clearance` `oc` where `oc`.`clearance_id` = `c`.`clearance_id` AND `oc`.`status` <> 'approved') = 0 THEN 'completed' ELSE `c`.`status` END AS `progress_status` FROM ((((((`clearance` `c` join `users` `u` on(`c`.`users_id` = `u`.`users_id`)) left join `course` `cr` on(`u`.`course_id` = `cr`.`course_id`)) left join `college` `col` on(`u`.`college_id` = `col`.`college_id`)) left join `clearance_type` `ct` on(`c`.`clearance_type_id` = `ct`.`clearance_type_id`)) left join `clearance` `c_office` on(`c_office`.`users_id` = `c`.`users_id` and `c_office`.`semester` = `c`.`semester` and `c_office`.`school_year` = `c`.`school_year`)) left join `offices` `o` on(`c_office`.`office_id` = `o`.`office_id`)) GROUP BY `c`.`clearance_id` ;

-- --------------------------------------------------------

--
-- Structure for view `view_organization_summary`
--
DROP TABLE IF EXISTS `view_organization_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_organization_summary`  AS SELECT `so`.`org_id` AS `org_id`, `so`.`org_name` AS `org_name`, `so`.`org_type` AS `org_type`, `so`.`org_email` AS `org_email`, `so`.`status` AS `status`, coalesce(`so`.`dashboard_type`,`so`.`org_type`) AS `dashboard_type`, `o`.`office_id` AS `office_id`, `o`.`office_name` AS `office_name`, `o`.`office_description` AS `office_description`, `o`.`parent_office_id` AS `parent_office_id`, `po`.`office_name` AS `parent_office_name`, `so`.`last_login` AS `last_login`, `so`.`login_count` AS `login_count`, `so`.`created_at` AS `created_at`, (select count(0) from `clearance` `c` where `c`.`office_id` = `o`.`office_id` and `c`.`status` = 'pending') AS `pending_clearances`, (select count(0) from `clearance` `c` where `c`.`office_id` = `o`.`office_id` and `c`.`status` = 'approved') AS `approved_clearances`, `odv`.`view_file` AS `dashboard_file` FROM (((`student_organizations` `so` left join `offices` `o` on(`so`.`office_id` = `o`.`office_id`)) left join `offices` `po` on(`o`.`parent_office_id` = `po`.`office_id`)) left join `organization_dashboard_views` `odv` on(coalesce(`so`.`dashboard_type`,`so`.`org_type`) = `odv`.`dashboard_type` and `odv`.`is_active` = 1)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user` (`users_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `clearance`
--
ALTER TABLE `clearance`
  ADD PRIMARY KEY (`clearance_id`),
  ADD KEY `clearance_type_id` (`clearance_type_id`),
  ADD KEY `processed_by` (`processed_by`),
  ADD KEY `idx_user` (`users_id`),
  ADD KEY `idx_office` (`office_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_is_completed` (`is_completed`),
  ADD KEY `idx_completed_date` (`completed_date`),
  ADD KEY `fk_lacking_comment_by` (`lacking_comment_by`),
  ADD KEY `fk_proof_uploaded_by` (`proof_uploaded_by`);

--
-- Indexes for table `clearance_flow_template`
--
ALTER TABLE `clearance_flow_template`
  ADD PRIMARY KEY (`flow_id`),
  ADD UNIQUE KEY `unique_flow_step` (`clearance_type_id`,`office_id`),
  ADD KEY `idx_clearance_type` (`clearance_type_id`),
  ADD KEY `idx_office` (`office_id`),
  ADD KEY `idx_step_order` (`step_order`);

--
-- Indexes for table `clearance_type`
--
ALTER TABLE `clearance_type`
  ADD PRIMARY KEY (`clearance_type_id`),
  ADD UNIQUE KEY `clearance_name` (`clearance_name`);

--
-- Indexes for table `clinic_records`
--
ALTER TABLE `clinic_records`
  ADD PRIMARY KEY (`record_id`),
  ADD UNIQUE KEY `unique_user_clinic` (`users_id`),
  ADD KEY `processed_by` (`processed_by`);

--
-- Indexes for table `college`
--
ALTER TABLE `college`
  ADD PRIMARY KEY (`college_id`),
  ADD UNIQUE KEY `college_name` (`college_name`);

--
-- Indexes for table `course`
--
ALTER TABLE `course`
  ADD PRIMARY KEY (`course_id`),
  ADD UNIQUE KEY `unique_course` (`course_name`,`college_id`),
  ADD KEY `college_id` (`college_id`);

--
-- Indexes for table `department_chairpersons`
--
ALTER TABLE `department_chairpersons`
  ADD PRIMARY KEY (`chairperson_id`),
  ADD UNIQUE KEY `users_id` (`users_id`),
  ADD KEY `college_id` (`college_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `offices`
--
ALTER TABLE `offices`
  ADD PRIMARY KEY (`office_id`),
  ADD UNIQUE KEY `office_name` (`office_name`),
  ADD KEY `idx_office_order` (`office_order`),
  ADD KEY `idx_office_type` (`office_type`),
  ADD KEY `idx_parent_office` (`parent_office_id`);

--
-- Indexes for table `organization_clearance`
--
ALTER TABLE `organization_clearance`
  ADD PRIMARY KEY (`org_clearance_id`),
  ADD UNIQUE KEY `unique_org_clearance` (`clearance_id`,`org_id`),
  ADD KEY `idx_clearance` (`clearance_id`),
  ADD KEY `idx_org` (`org_id`),
  ADD KEY `idx_office` (`office_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_org_clearance_processor` (`processed_by`),
  ADD KEY `idx_org_status` (`org_id`,`status`),
  ADD KEY `idx_clearance_org` (`clearance_id`,`org_id`);

--
-- Indexes for table `organization_dashboard_views`
--
ALTER TABLE `organization_dashboard_views`
  ADD PRIMARY KEY (`view_id`),
  ADD UNIQUE KEY `unique_dashboard_type` (`dashboard_type`);

--
-- Indexes for table `organization_settings`
--
ALTER TABLE `organization_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `unique_org_setting` (`org_id`,`setting_key`),
  ADD KEY `idx_org` (`org_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`reset_id`),
  ADD KEY `idx_account_email` (`account_email`),
  ADD KEY `idx_token_hash` (`token_hash`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `idx_expires` (`session_expires`);

--
-- Indexes for table `student_friendships`
--
ALTER TABLE `student_friendships`
  ADD PRIMARY KEY (`friendship_id`),
  ADD UNIQUE KEY `uniq_friend_pair` (`user_one_id`,`user_two_id`),
  ADD KEY `idx_user_one` (`user_one_id`),
  ADD KEY `idx_user_two` (`user_two_id`);

--
-- Indexes for table `student_messages`
--
ALTER TABLE `student_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_recipient_read` (`recipient_id`,`read_at`),
  ADD KEY `idx_participants` (`sender_id`,`recipient_id`),
  ADD KEY `idx_sent_at` (`sent_at`);

--
-- Indexes for table `student_organizations`
--
ALTER TABLE `student_organizations`
  ADD PRIMARY KEY (`org_id`),
  ADD UNIQUE KEY `org_email` (`org_email`),
  ADD KEY `idx_org_type` (`org_type`),
  ADD KEY `idx_org_email` (`org_email`),
  ADD KEY `idx_org_status` (`status`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_org_office` (`office_id`),
  ADD KEY `idx_org_type_status` (`org_type`,`status`),
  ADD KEY `idx_org_college` (`college_id`);

--
-- Indexes for table `sub_admin_offices`
--
ALTER TABLE `sub_admin_offices`
  ADD PRIMARY KEY (`sub_admin_office_id`),
  ADD UNIQUE KEY `unique_admin_office` (`users_id`,`office_id`),
  ADD KEY `office_id` (`office_id`);

--
-- Indexes for table `sub_offices`
--
ALTER TABLE `sub_offices`
  ADD PRIMARY KEY (`sub_office_id`),
  ADD UNIQUE KEY `unique_sub_office` (`sub_office_name`,`parent_office_id`),
  ADD KEY `parent_office_id` (`parent_office_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`users_id`),
  ADD UNIQUE KEY `emails` (`emails`),
  ADD UNIQUE KEY `ismis_id` (`ismis_id`),
  ADD KEY `college_id` (`college_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `idx_email` (`emails`),
  ADD KEY `idx_role` (`user_role_id`),
  ADD KEY `idx_office` (`office_id`),
  ADD KEY `idx_college_id` (`college_id`),
  ADD KEY `idx_year_level` (`year_level`);

--
-- Indexes for table `user_role`
--
ALTER TABLE `user_role`
  ADD PRIMARY KEY (`user_role_id`),
  ADD UNIQUE KEY `user_role_name` (`user_role_name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=807;

--
-- AUTO_INCREMENT for table `clearance`
--
ALTER TABLE `clearance`
  MODIFY `clearance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=124;

--
-- AUTO_INCREMENT for table `clearance_flow_template`
--
ALTER TABLE `clearance_flow_template`
  MODIFY `flow_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `clearance_type`
--
ALTER TABLE `clearance_type`
  MODIFY `clearance_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `clinic_records`
--
ALTER TABLE `clinic_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `college`
--
ALTER TABLE `college`
  MODIFY `college_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `course`
--
ALTER TABLE `course`
  MODIFY `course_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `department_chairpersons`
--
ALTER TABLE `department_chairpersons`
  MODIFY `chairperson_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `offices`
--
ALTER TABLE `offices`
  MODIFY `office_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `organization_clearance`
--
ALTER TABLE `organization_clearance`
  MODIFY `org_clearance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `organization_dashboard_views`
--
ALTER TABLE `organization_dashboard_views`
  MODIFY `view_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `organization_settings`
--
ALTER TABLE `organization_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `reset_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `student_friendships`
--
ALTER TABLE `student_friendships`
  MODIFY `friendship_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `student_messages`
--
ALTER TABLE `student_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `student_organizations`
--
ALTER TABLE `student_organizations`
  MODIFY `org_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `sub_admin_offices`
--
ALTER TABLE `sub_admin_offices`
  MODIFY `sub_admin_office_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `sub_offices`
--
ALTER TABLE `sub_offices`
  MODIFY `sub_office_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `users_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `user_role`
--
ALTER TABLE `user_role`
  MODIFY `user_role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`users_id`) REFERENCES `users` (`users_id`) ON DELETE SET NULL;

--
-- Constraints for table `clearance`
--
ALTER TABLE `clearance`
  ADD CONSTRAINT `clearance_ibfk_1` FOREIGN KEY (`users_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `clearance_ibfk_2` FOREIGN KEY (`clearance_type_id`) REFERENCES `clearance_type` (`clearance_type_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `clearance_ibfk_3` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `clearance_ibfk_4` FOREIGN KEY (`processed_by`) REFERENCES `users` (`users_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_lacking_comment_by` FOREIGN KEY (`lacking_comment_by`) REFERENCES `users` (`users_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_proof_uploaded_by` FOREIGN KEY (`proof_uploaded_by`) REFERENCES `users` (`users_id`) ON DELETE SET NULL;

--
-- Constraints for table `clearance_flow_template`
--
ALTER TABLE `clearance_flow_template`
  ADD CONSTRAINT `fk_flow_clearance_type` FOREIGN KEY (`clearance_type_id`) REFERENCES `clearance_type` (`clearance_type_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_flow_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE;

--
-- Constraints for table `clinic_records`
--
ALTER TABLE `clinic_records`
  ADD CONSTRAINT `clinic_records_ibfk_1` FOREIGN KEY (`users_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `clinic_records_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`users_id`) ON DELETE SET NULL;

--
-- Constraints for table `course`
--
ALTER TABLE `course`
  ADD CONSTRAINT `course_ibfk_1` FOREIGN KEY (`college_id`) REFERENCES `college` (`college_id`) ON DELETE SET NULL;

--
-- Constraints for table `department_chairpersons`
--
ALTER TABLE `department_chairpersons`
  ADD CONSTRAINT `department_chairpersons_ibfk_1` FOREIGN KEY (`users_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `department_chairpersons_ibfk_2` FOREIGN KEY (`college_id`) REFERENCES `college` (`college_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `department_chairpersons_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`users_id`) ON DELETE SET NULL;

--
-- Constraints for table `organization_clearance`
--
ALTER TABLE `organization_clearance`
  ADD CONSTRAINT `fk_org_clearance_clearance` FOREIGN KEY (`clearance_id`) REFERENCES `clearance` (`clearance_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_org_clearance_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_org_clearance_org` FOREIGN KEY (`org_id`) REFERENCES `student_organizations` (`org_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_org_clearance_processor` FOREIGN KEY (`processed_by`) REFERENCES `users` (`users_id`) ON DELETE SET NULL;

--
-- Constraints for table `organization_settings`
--
ALTER TABLE `organization_settings`
  ADD CONSTRAINT `fk_org_settings_org` FOREIGN KEY (`org_id`) REFERENCES `student_organizations` (`org_id`) ON DELETE CASCADE;

--
-- Constraints for table `student_friendships`
--
ALTER TABLE `student_friendships`
  ADD CONSTRAINT `fk_friend_user_one` FOREIGN KEY (`user_one_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_friend_user_two` FOREIGN KEY (`user_two_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE;

--
-- Constraints for table `student_messages`
--
ALTER TABLE `student_messages`
  ADD CONSTRAINT `fk_student_messages_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_student_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE;

--
-- Constraints for table `student_organizations`
--
ALTER TABLE `student_organizations`
  ADD CONSTRAINT `fk_student_org_college` FOREIGN KEY (`college_id`) REFERENCES `college` (`college_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_student_organizations_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `student_organizations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`users_id`) ON DELETE SET NULL;

--
-- Constraints for table `sub_admin_offices`
--
ALTER TABLE `sub_admin_offices`
  ADD CONSTRAINT `sub_admin_offices_ibfk_1` FOREIGN KEY (`users_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sub_admin_offices_ibfk_2` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE;

--
-- Constraints for table `sub_offices`
--
ALTER TABLE `sub_offices`
  ADD CONSTRAINT `sub_offices_ibfk_1` FOREIGN KEY (`parent_office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`user_role_id`) REFERENCES `user_role` (`user_role_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`college_id`) REFERENCES `college` (`college_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_ibfk_3` FOREIGN KEY (`course_id`) REFERENCES `course` (`course_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_ibfk_4` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

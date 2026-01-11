-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 01, 2026 at 01:42 PM
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
-- Database: `advance_studies`
--

-- --------------------------------------------------------

--
-- Table structure for table `advisory_messages`
--

CREATE TABLE `advisory_messages` (
  `id` int(11) NOT NULL,
  `adviser_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_role` enum('adviser','student') NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `committee_invitations`
--

CREATE TABLE `committee_invitations` (
  `id` int(11) NOT NULL,
  `defense_id` int(11) NOT NULL,
  `committee_chair_id` int(11) NOT NULL,
  `status` enum('Pending','Accepted','Declined') NOT NULL DEFAULT 'Pending',
  `message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `responded_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `concept_papers`
--

CREATE TABLE `concept_papers` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `assigned_faculty` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `concept_papers`
--

INSERT INTO `concept_papers` (`id`, `title`, `description`, `student_id`, `assigned_faculty`, `created_at`) VALUES
(79, 'test', 'submission_ref:46:1', 81, NULL, '2026-01-01 12:04:06'),
(80, 'for', 'submission_ref:46:2', 81, NULL, '2026-01-01 12:04:06'),
(81, 'me', 'submission_ref:46:3', 81, NULL, '2026-01-01 12:04:06');

-- --------------------------------------------------------

--
-- Table structure for table `concept_reviewer_assignments`
--

CREATE TABLE `concept_reviewer_assignments` (
  `id` int(11) NOT NULL,
  `concept_paper_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `reviewer_role` varchar(50) NOT NULL,
  `status` enum('pending','in_progress','completed','declined') NOT NULL DEFAULT 'pending',
  `assigned_by` int(11) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `due_at` date DEFAULT NULL,
  `decline_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `concept_reviews`
--

CREATE TABLE `concept_reviews` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `concept_paper_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `reviewer_role` varchar(50) NOT NULL,
  `score` tinyint(4) DEFAULT NULL,
  `recommendation` varchar(20) DEFAULT NULL,
  `rank_order` tinyint(4) DEFAULT NULL,
  `is_preferred` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `comment_suggestions` text DEFAULT NULL,
  `adviser_interest` tinyint(1) NOT NULL DEFAULT 0,
  `chair_feedback` text DEFAULT NULL,
  `chair_feedback_at` timestamp NULL DEFAULT NULL,
  `chair_feedback_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `concept_review_messages`
--

CREATE TABLE `concept_review_messages` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `concept_paper_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_role` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `defense_panels`
--

CREATE TABLE `defense_panels` (
  `id` int(11) NOT NULL,
  `defense_id` int(11) NOT NULL,
  `panel_member_id` int(11) DEFAULT NULL,
  `panel_role` enum('adviser','committee_chair','panel_member') DEFAULT 'panel_member',
  `panel_member` varchar(255) NOT NULL,
  `response` enum('Pending','Accepted','Declined') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `defense_schedules`
--

CREATE TABLE `defense_schedules` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `defense_date` date NOT NULL,
  `defense_time` time NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `schedule_date` date NOT NULL,
  `schedule_time` time NOT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Confirmed','Completed') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `defense_schedules`
--

INSERT INTO `defense_schedules` (`id`, `student_id`, `defense_date`, `defense_time`, `start_time`, `end_time`, `remarks`, `created_at`, `schedule_date`, `schedule_time`, `venue`, `status`) VALUES
(40, 81, '2025-11-27', '08:20:00', '08:20:00', '10:20:00', NULL, '2025-11-27 08:17:56', '0000-00-00', '00:00:00', 'IAdS Conference Room', 'Pending'),
(41, 75, '2025-11-27', '13:30:00', '13:30:00', '17:30:00', NULL, '2025-11-27 08:27:24', '0000-00-00', '00:00:00', 'Online Platform (MS Teams)', 'Pending'),
(43, 75, '2025-12-10', '10:00:00', '10:00:00', '11:00:00', NULL, '2025-12-09 14:59:07', '0000-00-00', '00:00:00', 'IAAS', 'Pending');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `all_day` tinyint(1) DEFAULT 1,
  `category` varchar(50) DEFAULT 'General',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `final_concept_submissions`
--

CREATE TABLE `final_concept_submissions` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `concept_paper_id` int(11) NOT NULL,
  `final_title` varchar(255) NOT NULL,
  `abstract` text NOT NULL,
  `keywords` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` enum('Pending','Under Review','Approved','Returned') DEFAULT 'Pending',
  `remarks` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `final_paper_submissions`
--

CREATE TABLE `final_paper_submissions` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `final_title` varchar(255) NOT NULL,
  `introduction` text DEFAULT NULL,
  `background` text DEFAULT NULL,
  `methodology` text DEFAULT NULL,
  `submission_notes` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `status` enum('Submitted','Under Review','Needs Revision','Minor Revision','Major Revision','Approved','Rejected') DEFAULT 'Submitted',
  `version` int(11) NOT NULL DEFAULT 1,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `final_decision_by` int(11) DEFAULT NULL,
  `final_decision_notes` text DEFAULT NULL,
  `final_decision_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `final_paper_reviews`
--

CREATE TABLE `final_paper_reviews` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `reviewer_role` enum('adviser','committee_chairperson','panel') NOT NULL,
  `status` enum('Pending','Approved','Rejected','Needs Revision','Minor Revision','Major Revision') DEFAULT 'Pending',
  `comments` text DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `final_endorsement_submissions`
--

CREATE TABLE `final_endorsement_submissions` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('Submitted','Approved','Rejected') DEFAULT 'Submitted',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `defense_outcomes`
--

CREATE TABLE `defense_outcomes` (
  `id` int(11) NOT NULL,
  `defense_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `outcome` enum('Thesis Defended','Capstone Defended','Dissertation Defended') NOT NULL,
  `notes` text DEFAULT NULL,
  `set_by` int(11) DEFAULT NULL,
  `set_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `role`, `title`, `message`, `link`, `is_read`, `created_at`) VALUES
(475, 75, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Pending to Under Review.', 'student_dashboard.php', 0, '2025-11-26 22:04:34'),
(485, 75, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect feedback soon.', 'student_dashboard.php', 0, '2025-11-26 22:06:19'),
(486, 75, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect their recommendation on or before November 27, 2025.', 'student_dashboard.php', 0, '2025-11-26 22:07:25'),
(489, 75, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect their recommendation on or before November 27, 2025.', 'student_dashboard.php', 0, '2025-11-26 22:09:47'),
(490, 75, NULL, 'Feedback on your concept titles', 'Hi kc caminade, your adviser marked \"test\" as Rank #1. Let\'s continue refining this title for your research work.', 'student_dashboard.php', 1, '2025-11-26 22:10:47'),
(493, 75, NULL, 'Final concept review update', 'Your final concept titled \"test\" is now Approved.', 'submit_paper.php', 0, '2025-11-26 22:13:25'),
(494, 75, NULL, 'Adviser assigned', 'You have been added to an adviser in the DNSC IAdS system. Open your dashboard to start collaborating.', 'student_dashboard.php', 0, '2025-11-26 22:15:46'),
(495, 75, NULL, 'Defense schedule created', 'Your defense has been scheduled on November 27, 2025 6:22 AM - 9:22 AM at Online Platform (MS Teams).', 'view_defense_schedule.php', 0, '2025-11-26 22:22:50'),
(501, 75, NULL, 'Payment status updated', 'Your payment submission #38 is now Payment Accepted.', 'proof_of_payment.php', 0, '2025-11-26 22:27:27'),
(504, 75, NULL, 'Payment status updated', 'Your payment submission #38 is now Payment Declined. Remarks: blurry.', 'proof_of_payment.php', 0, '2025-11-26 22:28:49'),
(507, 75, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Pending to Approved.', 'student_dashboard.php', 0, '2025-11-26 22:29:59'),
(510, 75, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Approved to Pending.', 'student_dashboard.php', 0, '2025-11-26 22:30:18'),
(513, 75, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Pending to Approved.', 'student_dashboard.php', 0, '2025-11-26 22:30:41'),
(516, 75, NULL, 'Research archived', 'Your approved research has been archived for publication reference.', 'student_dashboard.php', 0, '2025-11-26 22:31:26'),
(533, 81, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect feedback soon.', 'student_dashboard.php', 1, '2025-11-27 08:02:18'),
(534, 81, NULL, 'Feedback on your concept titles', 'Hi johncarlo castro, your adviser marked \"for\" as Rank #1. Let\'s continue refining this title for your research work.', 'student_dashboard.php', 1, '2025-11-27 08:07:59'),
(542, 81, NULL, 'Feedback on your concept titles', 'Hi johncarlo castro, your adviser marked \"for\" as Rank #1. Let\'s continue refining this title for your research work.', 'student_dashboard.php', 1, '2025-11-27 08:10:05'),
(544, 81, NULL, 'Final concept review update', 'Your final concept titled \"for\" is now Pending.', 'submit_paper.php', 1, '2025-11-27 08:10:29'),
(545, 81, NULL, 'Final concept review update', 'Your final concept titled \"for\" is now Approved.', 'submit_paper.php', 1, '2025-11-27 08:11:05'),
(549, 81, NULL, 'Payment status updated', 'Your payment submission #41 is now Payment Accepted.', 'proof_of_payment.php', 1, '2025-11-27 08:14:15'),
(550, 81, NULL, 'Payment status updated', 'Your payment submission #41 is now Payment Declined. Remarks: blurry.', 'proof_of_payment.php', 1, '2025-11-27 08:14:29'),
(554, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Pending to Approved.', 'student_dashboard.php', 1, '2025-11-27 08:15:11'),
(557, 81, NULL, 'Defense schedule created', 'Your defense has been scheduled on November 27, 2025 8:20 AM - 10:20 AM at IAdS Conference Room.', 'view_defense_schedule.php', 1, '2025-11-27 08:17:56'),
(560, 75, NULL, 'Defense schedule created', 'Your defense has been scheduled on November 27, 2025 1:30 PM - 5:30 PM at Online Platform (MS Teams).', 'view_defense_schedule.php', 0, '2025-11-27 08:27:25'),
(623, 81, NULL, 'New feedback from Program Chair', 'Feedback on \"\": hmpp', 'student_dashboard.php', 1, '2025-12-05 01:42:16'),
(624, 81, NULL, 'New feedback from Program Chair', 'Feedback on \"\": ughmm', 'student_dashboard.php', 1, '2025-12-05 01:42:29'),
(625, 81, NULL, 'New feedback from Program Chair', 'Feedback on \"\": lolollol', 'student_dashboard.php', 1, '2025-12-05 01:42:43'),
(626, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Pending to Reviewing.', 'student_dashboard.php', 1, '2025-12-05 01:42:53'),
(633, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewing.', 'student_dashboard.php', 1, '2025-12-06 03:10:43'),
(636, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Pending to Reviewer Assigning.', 'student_dashboard.php', 1, '2025-12-06 03:11:33'),
(639, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewer Assigning.', 'student_dashboard.php', 1, '2025-12-06 03:12:05'),
(642, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Pending to Reviewer Assigning.', 'student_dashboard.php', 1, '2025-12-06 04:36:45'),
(645, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewer Assigning to Reviewer Assigning.', 'student_dashboard.php', 1, '2025-12-06 14:20:11'),
(648, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Pending to Reviewing.', 'student_dashboard.php', 1, '2025-12-06 15:23:13'),
(651, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewer Assigning.', 'student_dashboard.php', 1, '2025-12-06 15:25:00'),
(654, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewer Assigning to Reviewing.', 'student_dashboard.php', 1, '2025-12-06 15:25:29'),
(657, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewing.', 'student_dashboard.php', 1, '2025-12-06 15:26:41'),
(660, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewing.', 'student_dashboard.php', 1, '2025-12-06 15:27:57'),
(663, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewing.', 'student_dashboard.php', 1, '2025-12-06 15:36:43'),
(666, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewing.', 'student_dashboard.php', 1, '2025-12-06 15:37:21'),
(669, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewer Assigning to Reviewer Assigning.', 'student_dashboard.php', 1, '2025-12-06 15:39:03'),
(703, 81, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect feedback soon.', 'student_dashboard.php', 1, '2025-12-06 16:02:12'),
(708, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Pending to Reviewing.', 'student_dashboard.php', 0, '2025-12-08 15:30:16'),
(711, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewing.', 'student_dashboard.php', 0, '2025-12-08 15:42:49'),
(714, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewing.', 'student_dashboard.php', 0, '2025-12-08 15:45:24'),
(717, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewing.', 'student_dashboard.php', 0, '2025-12-08 16:32:32'),
(720, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewing.', 'student_dashboard.php', 0, '2025-12-08 16:42:54'),
(723, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewing.', 'student_dashboard.php', 0, '2025-12-08 19:18:32'),
(726, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewer Assigning.', 'student_dashboard.php', 0, '2025-12-08 19:18:57'),
(729, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewer Assigning to Reviewer Assigning.', 'student_dashboard.php', 0, '2025-12-09 14:25:54'),
(732, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewer Assigning to Reviewing.', 'student_dashboard.php', 0, '2025-12-09 14:26:22'),
(735, 75, NULL, 'Defense schedule created', 'Your defense has been scheduled on December 10, 2025 10:00 AM - 11:00 AM at IAdS Conference Room.', 'view_defense_schedule.php', 0, '2025-12-09 14:59:07'),
(738, 75, NULL, 'Defense schedule updated', 'Your defense schedule has been updated to December 10, 2025 10:00 AM - 11:00 AM at Online Platform (MS Teams) (Pending).', 'view_defense_schedule.php', 0, '2025-12-09 15:08:30'),
(744, 75, NULL, 'Defense schedule updated', 'Your defense schedule has been updated to December 10, 2025 10:00 AM - 11:00 AM at IAAS (Pending).', 'view_defense_schedule.php', 0, '2025-12-09 15:23:21'),
(747, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewer Assigning.', 'student_dashboard.php', 0, '2025-12-09 15:25:45'),
(759, 81, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect their recommendation on or before December 16, 2025.', 'student_dashboard.php', 0, '2025-12-09 15:26:41'),
(760, 81, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect their recommendation on or before December 16, 2025.', 'student_dashboard.php', 0, '2025-12-09 16:22:07'),
(761, 81, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect their recommendation on or before December 16, 2025.', 'student_dashboard.php', 0, '2025-12-09 16:24:07'),
(762, 81, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect their recommendation on or before December 16, 2025.', 'student_dashboard.php', 0, '2025-12-09 16:43:14'),
(763, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewer Assigning to Reviewer Assigning.', 'student_dashboard.php', 0, '2025-12-10 17:06:28'),
(775, 81, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect feedback soon.', 'student_dashboard.php', 0, '2025-12-10 17:07:42'),
(776, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewer Assigning to Reviewer Assigning.', 'student_dashboard.php', 0, '2025-12-10 17:10:06'),
(788, 81, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect feedback soon.', 'student_dashboard.php', 0, '2025-12-10 17:10:21'),
(789, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewer Assigning to Reviewing.', 'student_dashboard.php', 0, '2025-12-15 05:13:49'),
(792, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewing to Reviewer Assigning.', 'student_dashboard.php', 0, '2025-12-15 05:14:41'),
(795, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewer Assigning to Reviewer Assigning.', 'student_dashboard.php', 0, '2025-12-15 05:15:23'),
(798, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Reviewer Assigning to Reviewer Assigning.', 'student_dashboard.php', 0, '2025-12-15 14:38:42'),
(833, 84, NULL, 'New paper submission', 'johncarlo castro submitted a new paper.', 'submissions.php', 0, '2026-01-01 12:02:33'),
(834, 87, NULL, 'New paper submission', 'johncarlo castro submitted a new paper.', 'submissions.php', 0, '2026-01-01 12:02:33'),
(835, 85, NULL, 'New paper submission', 'johncarlo castro submitted a new paper.', 'submissions.php', 0, '2026-01-01 12:02:33'),
(836, 81, NULL, 'Submission status updated', 'Your submission \"\" status was updated from Pending to Reviewer Assigning.', 'student_dashboard.php', 0, '2026-01-01 12:02:59'),
(837, 85, NULL, 'Submission status updated', 'The submission \"\" status changed from Pending to Reviewer Assigning.', 'submissions.php', 0, '2026-01-01 12:02:59'),
(838, 87, NULL, 'Submission status updated', 'The submission \"\" status changed from Pending to Reviewer Assigning.', 'submissions.php', 0, '2026-01-01 12:03:00'),
(839, 86, NULL, 'Review assignment', 'You have been assigned as Panel Members to review the concept titles submitted by johncarlo castro. Please rate each title and recommend which one to pursue.', 'subject_specialist_dashboard.php', 1, '2026-01-01 12:03:17'),
(840, 86, NULL, 'Review assignment', 'You have been assigned as Panel Members to review the concept titles submitted by johncarlo castro. Please rate each title and recommend which one to pursue.', 'subject_specialist_dashboard.php', 0, '2026-01-01 12:03:17'),
(841, 86, NULL, 'Review assignment', 'You have been assigned as Panel Members to review the concept titles submitted by johncarlo castro. Please rate each title and recommend which one to pursue.', 'subject_specialist_dashboard.php', 0, '2026-01-01 12:03:17'),
(842, 85, NULL, 'Review assignment', 'You have been assigned as Advisers to review the concept titles submitted by johncarlo castro. Please rate each title and recommend which one to pursue.', 'subject_specialist_dashboard.php', 0, '2026-01-01 12:03:17'),
(843, 85, NULL, 'Review assignment', 'You have been assigned as Advisers to review the concept titles submitted by johncarlo castro. Please rate each title and recommend which one to pursue.', 'subject_specialist_dashboard.php', 0, '2026-01-01 12:03:17'),
(844, 85, NULL, 'Review assignment', 'You have been assigned as Advisers to review the concept titles submitted by johncarlo castro. Please rate each title and recommend which one to pursue.', 'subject_specialist_dashboard.php', 0, '2026-01-01 12:03:17'),
(845, 87, NULL, 'Review assignment', 'You have been assigned as Committee Chairs to review the concept titles submitted by johncarlo castro. Please rate each title and recommend which one to pursue.', 'committee_chair_dashboard.php', 0, '2026-01-01 12:03:17'),
(846, 87, NULL, 'Review assignment', 'You have been assigned as Committee Chairs to review the concept titles submitted by johncarlo castro. Please rate each title and recommend which one to pursue.', 'committee_chair_dashboard.php', 0, '2026-01-01 12:03:17'),
(847, 87, NULL, 'Review assignment', 'You have been assigned as Committee Chairs to review the concept titles submitted by johncarlo castro. Please rate each title and recommend which one to pursue.', 'committee_chair_dashboard.php', 0, '2026-01-01 12:03:17'),
(848, 81, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect feedback soon.', 'student_dashboard.php', 0, '2026-01-01 12:03:17'),
(849, 81, NULL, 'Reviewers assigned', 'Reviewers were assigned to evaluate your concept papers. Expect feedback soon.', 'student_dashboard.php', 0, '2026-01-01 12:03:54'),
(850, 84, NULL, 'New paper submission', 'johncarlo castro submitted a new paper.', 'submissions.php', 0, '2026-01-01 12:04:06'),
(851, 87, NULL, 'New paper submission', 'johncarlo castro submitted a new paper.', 'submissions.php', 0, '2026-01-01 12:04:06'),
(852, 85, NULL, 'New paper submission', 'johncarlo castro submitted a new paper.', 'submissions.php', 0, '2026-01-01 12:04:06'),
(853, 84, NULL, 'New paper submission', 'johncarlo castro submitted a new paper.', 'submissions.php', 1, '2026-01-01 12:04:14'),
(854, 87, NULL, 'New paper submission', 'johncarlo castro submitted a new paper.', 'submissions.php', 1, '2026-01-01 12:04:14'),
(855, 85, NULL, 'New paper submission', 'johncarlo castro submitted a new paper.', 'submissions.php', 0, '2026-01-01 12:04:14');

-- --------------------------------------------------------

--
-- Table structure for table `payment_proofs`
--

CREATE TABLE `payment_proofs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `reference_number` varchar(255) DEFAULT NULL,
  `status` enum('pending','payment_declined','payment_accepted') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_proofs`
--

INSERT INTO `payment_proofs` (`id`, `user_id`, `user_email`, `file_path`, `reference_number`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(38, 75, '', 'uploads/payments/proof_69277e9811a395.73862726.png', '112334', 'payment_declined', 'blurry', '2025-11-26 22:26:32', '2025-11-26 22:28:49'),
(39, 75, '', 'uploads/payments/proof_69277eda6a7962.61764811.png', '112334', 'pending', NULL, '2025-11-26 22:27:38', '2025-11-26 22:27:38'),
(40, 75, '', 'uploads/payments/proof_69277f2a6ff4a6.11097975.png', '112334', 'pending', NULL, '2025-11-26 22:28:58', '2025-11-26 22:28:58'),
(41, 81, '', 'uploads/payments/proof_692807e21e2ee2.18067898.png', '1212', 'payment_declined', 'blurry', '2025-11-27 08:12:18', '2025-11-27 08:14:29'),
(42, 81, '', 'uploads/payments/proof_69280873709871.66675226.png', '1212', 'pending', NULL, '2025-11-27 08:14:43', '2025-11-27 08:14:43');

-- --------------------------------------------------------

--
-- Table structure for table `research_archive`
--

CREATE TABLE `research_archive` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `doc_type` varchar(50) NOT NULL,
  `publication_type` varchar(100) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `keywords` varchar(255) DEFAULT NULL,
  `abstract` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviewer_invite_feedback`
--

CREATE TABLE `reviewer_invite_feedback` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `concept_paper_id` int(11) DEFAULT NULL,
  `reviewer_role` varchar(50) DEFAULT NULL,
  `reason` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `code` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `dashboard` varchar(255) NOT NULL,
  `is_switchable` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`code`, `label`, `dashboard`, `is_switchable`, `created_at`, `updated_at`) VALUES
('adviser', 'Adviser', 'adviser.php', 1, '2025-12-18 17:13:16', NULL),
('committee_chair', 'Committee Chair', 'my_committee_defense.php', 1, '2025-12-18 17:13:16', '2025-12-30 20:49:49'),
('committee_chairperson', 'Committee Chairperson', 'my_committee_defense.php', 1, '2025-12-18 17:13:16', NULL),
('dean', 'Dean', 'dean.php', 0, '2025-12-18 17:13:16', NULL),
('faculty', 'Faculty', 'subject_specialist_dashboard.php', 1, '2025-12-18 17:13:16', '2025-12-30 19:38:46'),
('panel', 'Panel Member', 'my_assign_defense.php', 1, '2025-12-18 17:13:16', '2026-01-01 12:41:35'),
('program_chairperson', 'Program Chairperson', 'program_chairperson.php', 1, '2025-12-18 17:13:16', NULL),
('reviewer', 'Faculty Reviewer', 'reviewer_dashboard.php', 0, '2025-12-29 10:11:44', '2025-12-29 19:42:23'),
('student', 'Student', 'student_dashboard.php', 0, '2025-12-18 17:13:16', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `status_logs`
--

CREATE TABLE `status_logs` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `updated_by` int(11) NOT NULL,
  `old_status` enum('Pending','Under Review','Revision Required','Approved','Rejected') NOT NULL,
  `new_status` enum('Pending','Under Review','Revision Required','Approved','Rejected') NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `status_updates`
--

CREATE TABLE `status_updates` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `updated_by` int(11) NOT NULL,
  `old_status` enum('Pending','Under Review','Approved','Revision Required','Rejected') DEFAULT NULL,
  `new_status` enum('Pending','Under Review','Approved','Revision Required','Rejected') NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `submissions`
--

CREATE TABLE `submissions` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` enum('Concept Paper','Thesis','Dissertation') NOT NULL,
  `abstract` text NOT NULL,
  `keywords` varchar(255) DEFAULT NULL,
  `concept_proposal_1` varchar(255) DEFAULT NULL,
  `concept_proposal_2` varchar(255) DEFAULT NULL,
  `concept_proposal_3` varchar(255) DEFAULT NULL,
  `concept_file_1` varchar(255) DEFAULT NULL,
  `concept_file_2` varchar(255) DEFAULT NULL,
  `concept_file_3` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` varchar(75) NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `archived_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `submissions`
--

INSERT INTO `submissions` (`id`, `student_id`, `title`, `type`, `abstract`, `keywords`, `concept_proposal_1`, `concept_proposal_2`, `concept_proposal_3`, `concept_file_1`, `concept_file_2`, `concept_file_3`, `file_path`, `status`, `created_at`, `archived_at`) VALUES
(46, 81, '', 'Concept Paper', '', '', 'test', 'for', 'me', 'uploads/submissions/concept1_695662b60d50b7.71473294_heheh.pdf', 'uploads/submissions/concept2_695662b60dcc32.36282636_huhuhuhu.pdf', 'uploads/submissions/concept3_695662b60e2916.49648950_hihihihih.pdf', 'uploads/submissions/concept1_695662b60d50b7.71473294_heheh.pdf', 'Pending', '2026-01-01 12:04:06', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `submission_feedback`
--

CREATE TABLE `submission_feedback` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `chair_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `submission_reviews`
--

CREATE TABLE `submission_reviews` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `overall_rating` tinyint(4) DEFAULT NULL,
  `recommendation` varchar(100) DEFAULT NULL,
  `strengths` text DEFAULT NULL,
  `improvements` text DEFAULT NULL,
  `suggestions` text DEFAULT NULL,
  `overall_comments` text DEFAULT NULL,
  `methodology_rating` tinyint(4) DEFAULT NULL,
  `data_analysis_rating` tinyint(4) DEFAULT NULL,
  `literature_review_rating` tinyint(4) DEFAULT NULL,
  `writing_quality_rating` tinyint(4) DEFAULT NULL,
  `is_draft` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reviewer_role` varchar(50) NOT NULL DEFAULT '',
  `comments` text DEFAULT NULL,
  `data_rating` tinyint(4) DEFAULT NULL,
  `literature_rating` tinyint(4) DEFAULT NULL,
  `writing_rating` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `firstname` varchar(50) NOT NULL,
  `lastname` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('student','dean','program_chairperson','faculty','adviser','committee_chair','panel') NOT NULL,
  `adviser_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `contact` varchar(50) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `college` varchar(100) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `advisor_id` int(11) DEFAULT NULL,
  `student_id` varchar(100) DEFAULT NULL,
  `program` varchar(255) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `firstname`, `lastname`, `username`, `password`, `email`, `role`, `adviser_id`, `created_at`, `contact`, `gender`, `department`, `college`, `photo`, `position`, `advisor_id`, `student_id`, `program`, `year_level`) VALUES
(32, 'Dean', 'IAdS', 'Dean IAdS', '$2y$10$XOifNXWO8YKEnO3AMFS42em.LfaEaqpMNGkVH9sRLSWx8jG7nY.zm', 'dean01@mail.com', 'dean', NULL, '2025-08-11 14:11:05', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(75, 'kc', 'caminade', '202201465', '$2y$10$Vw5uqv/LY5oMVKKspZwJju/.4ncQi66q4ElGFI6rsMVDLtCv5yf06', 'caminade.kc@dnsc.edu.ph', 'student', 74, '2025-11-26 21:59:49', NULL, NULL, NULL, NULL, NULL, NULL, 74, '202201465', 'MST-BIOLOGY', '1st Year'),
(81, 'johncarlo', 'castro', '202201561', '$2y$10$svHeBz4GhMytHmYOaXCo2u1Owp9hFOiiLCLes87LLYofbqpLVVlrq', 'castro.jhon@dnsc.edu.ph', 'student', NULL, '2025-11-27 07:44:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '202201561', 'MIT', '1st Year'),
(84, 'john', 'Jamelo', 'john Jamelo', '$2y$10$SG4/gFG1OdBK3LmQKbWk2e4Js6YqX.5TmA1Dez9P8XxQ8wSm/Xfhe', 'jamelo.john@dnsc.edu.ph', 'program_chairperson', NULL, '2025-12-29 19:27:21', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(85, 'johny', 'johny', '@sin', '$2y$10$w9wekTs/SJTIkwaPOnuM9uj.N8Bi/sbI/MKoaVX2zMDlzvQW9i6yu', 'sin.johny@dnsc.edu.ph', 'adviser', NULL, '2025-12-29 19:37:14', '09451553521', 'Male', 'MIT', 'Institute of Advance Studies', NULL, NULL, NULL, NULL, NULL, NULL),
(86, 'Richard', 'Dap-og', '@richard', '$2y$10$46tv8roKdyjpVvPyuVjnQ.pgN9BKJtDo.p1Zic2Go61GegnIU7EN2', 'dap-og.richard@dnsc.edu.ph', 'panel', NULL, '2026-01-01 11:53:33', '09451553521', 'Male', 'MIT', 'Institute of Advance Studies', NULL, NULL, NULL, NULL, NULL, NULL),
(87, 'Carlo', 'Mosqueda', '@carlo', '$2y$10$kRu7sV2Ipw/Gj0lzEpeMSOoBz7WNjlYdvm5UTcBNbzQFkAdo7yzQu', 'mosqueda.carlo@dnsc.edu.ph', 'committee_chair', NULL, '2026-01-01 11:57:33', '09451553521', 'Male', 'MIT', 'Institute of Advance Studies', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_events`
--

CREATE TABLE `user_events` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `category` enum('Defense','Meeting','Call','Academic','Personal','Other') DEFAULT 'Other',
  `color` varchar(7) DEFAULT '#16562c',
  `is_all_day` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_events`
--

INSERT INTO `user_events` (`id`, `user_id`, `role`, `title`, `description`, `start_datetime`, `end_datetime`, `category`, `color`, `is_all_day`, `created_at`, `updated_at`) VALUES
(1, 84, 'committee_chair', 'test', 'test', '2026-01-06 00:00:00', '2026-01-16 12:53:00', 'Personal', '#16562c', 0, '2026-01-01 04:53:35', '2026-01-01 04:53:35'),
(2, 84, 'committee_chair', 'meeting', 'test', '2026-01-21 00:00:00', '2026-01-21 06:53:00', 'Academic', '#d3e10e', 0, '2026-01-01 04:54:29', '2026-01-01 04:54:29'),
(4, 84, 'committee_chair', 'practice', 'teste', '2026-01-22 00:00:00', '2026-01-24 00:56:00', 'Defense', '#551653', 0, '2026-01-01 04:57:16', '2026-01-01 04:57:16'),
(5, 81, 'student', 'tets', 'test', '2026-01-01 00:00:00', '2026-01-03 13:00:00', 'Academic', '#555316', 0, '2026-01-01 05:00:38', '2026-01-01 05:00:38'),
(6, 81, 'student', 'test', 'test', '2026-01-07 00:00:00', '2026-01-07 01:19:00', 'Academic', '#551634', 0, '2026-01-01 05:20:09', '2026-01-01 05:20:09'),
(7, 81, 'student', 'test', 'test', '2026-01-21 00:00:00', '2026-01-28 17:25:00', 'Personal', '#f10914', 0, '2026-01-01 05:25:59', '2026-01-01 05:25:59'),
(8, 84, 'faculty', 'test', 'test', '2026-01-05 00:00:00', '2026-01-10 17:46:00', 'Personal', '#551c16', 0, '2026-01-01 09:46:24', '2026-01-01 09:46:24'),
(9, 84, 'program_chairperson', 'test', 'test', '2026-01-05 00:00:00', '2026-01-10 17:54:00', 'Academic', '#1e1f1e', 0, '2026-01-01 09:55:04', '2026-01-01 09:55:04'),
(10, 84, 'program_chairperson', 'practice', 'test', '2026-01-13 00:00:00', NULL, 'Meeting', '#eb0f1a', 0, '2026-01-01 10:01:23', '2026-01-01 10:01:23'),
(11, 84, 'program_chairperson', 'practice', 'test', '2026-01-13 00:00:00', '2026-01-17 18:01:00', 'Meeting', '#eb0f1a', 0, '2026-01-01 10:01:50', '2026-01-01 10:01:50'),
(12, 84, 'program_chairperson', 'practice', 'test', '2026-01-13 00:00:00', '2026-01-17 18:01:00', 'Meeting', '#d1eb0f', 0, '2026-01-01 10:02:06', '2026-01-01 10:02:06'),
(13, 84, 'program_chairperson', 'practice', 'test', '2026-01-13 00:00:00', '2026-01-17 18:01:00', 'Meeting', '#bad110', 0, '2026-01-01 10:02:21', '2026-01-01 10:02:21'),
(14, 84, 'program_chairperson', 'practice', 'test', '2026-01-13 00:00:00', NULL, 'Academic', '#9a05ad', 0, '2026-01-01 10:02:45', '2026-01-01 10:02:45'),
(15, 84, 'program_chairperson', 'practice', NULL, '2026-01-19 00:00:00', '2026-01-30 18:05:00', 'Personal', '#515516', 0, '2026-01-01 10:06:02', '2026-01-01 10:06:02');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int(11) NOT NULL,
  `role_code` varchar(50) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_code`, `is_primary`, `created_at`) VALUES
(81, 'student', 1, '2025-12-30 17:24:37'),
(84, 'adviser', 0, '2025-12-29 19:27:37'),
(84, 'committee_chair', 1, '2025-12-29 19:27:37'),
(84, 'faculty', 0, '2025-12-29 19:27:37'),
(84, 'panel', 0, '2025-12-29 19:27:37'),
(84, 'program_chairperson', 0, '2025-12-29 19:27:37'),
(84, 'reviewer', 0, '2025-12-29 19:27:37'),
(85, 'adviser', 0, '2025-12-29 19:37:54'),
(85, 'committee_chair', 0, '2025-12-29 19:47:30'),
(85, 'faculty', 1, '2025-12-29 19:51:01'),
(85, 'panel', 0, '2025-12-29 19:47:30'),
(85, 'reviewer', 0, '2025-12-29 19:47:30'),
(86, 'adviser', 0, '2026-01-01 11:53:46'),
(86, 'committee_chair', 0, '2026-01-01 11:53:46'),
(86, 'faculty', 1, '2026-01-01 11:53:46'),
(86, 'panel', 0, '2026-01-01 11:53:45'),
(86, 'reviewer', 0, '2026-01-01 11:53:46'),
(87, 'adviser', 0, '2026-01-01 12:09:03'),
(87, 'committee_chair', 0, '2026-01-01 12:09:03'),
(87, 'faculty', 0, '2026-01-01 12:09:03'),
(87, 'panel', 1, '2026-01-01 12:09:03'),
(87, 'reviewer', 0, '2026-01-01 12:09:03');

-- --------------------------------------------------------

--
-- Table structure for table `user_role_switch_logs`
--

CREATE TABLE `user_role_switch_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `from_role` varchar(50) NOT NULL,
  `to_role` varchar(50) NOT NULL,
  `switched_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_role_switch_logs`
--

INSERT INTO `user_role_switch_logs` (`id`, `user_id`, `from_role`, `to_role`, `switched_at`) VALUES
(57, 84, '', 'program_chairperson', '2025-12-29 19:27:37'),
(58, 84, 'program_chairperson', 'faculty', '2025-12-29 19:31:12'),
(59, 84, '', 'faculty', '2025-12-29 19:31:31'),
(60, 84, '', 'faculty', '2025-12-29 19:31:56'),
(61, 84, 'faculty', 'reviewer', '2025-12-29 19:32:40'),
(62, 84, 'reviewer', 'faculty', '2025-12-29 19:33:50'),
(63, 84, 'faculty', 'program_chairperson', '2025-12-29 19:34:24'),
(64, 85, '', 'adviser', '2025-12-29 19:37:54'),
(65, 85, 'adviser', 'reviewer', '2025-12-29 19:38:01'),
(66, 85, '', 'reviewer', '2025-12-29 19:38:17'),
(67, 85, '', 'reviewer', '2025-12-29 19:38:30'),
(68, 84, '', 'program_chairperson', '2025-12-29 19:38:44'),
(69, 85, '', 'reviewer', '2025-12-29 19:39:38'),
(70, 84, '', 'program_chairperson', '2025-12-29 19:39:50'),
(71, 84, 'program_chairperson', 'faculty', '2025-12-29 19:39:58'),
(72, 84, '', 'faculty', '2025-12-29 19:40:12'),
(73, 85, '', 'reviewer', '2025-12-29 19:44:11'),
(74, 85, '', 'reviewer', '2025-12-29 19:45:03'),
(75, 84, '', 'faculty', '2025-12-29 19:45:43'),
(76, 85, '', 'panel', '2025-12-29 19:47:30'),
(77, 85, '', 'panel', '2025-12-29 19:49:04'),
(78, 85, 'panel', 'adviser', '2025-12-29 19:49:14'),
(79, 85, 'adviser', 'faculty', '2025-12-29 19:49:33'),
(80, 85, '', 'faculty', '2025-12-29 19:49:52'),
(81, 85, 'faculty', 'panel', '2025-12-29 19:50:03'),
(82, 85, 'panel', 'committee_chair', '2025-12-29 19:50:06'),
(83, 85, 'committee_chair', 'adviser', '2025-12-29 19:50:09'),
(84, 85, 'adviser', 'committee_chair', '2025-12-29 19:50:12'),
(85, 85, '', 'committee_chair', '2025-12-29 19:50:26'),
(86, 85, 'committee_chair', 'faculty', '2025-12-29 19:51:06'),
(87, 85, 'faculty', 'adviser', '2025-12-29 19:51:40'),
(88, 85, 'adviser', 'faculty', '2025-12-29 19:52:22'),
(89, 84, '', 'faculty', '2025-12-29 20:01:57'),
(90, 84, 'faculty', 'panel', '2025-12-29 20:05:11'),
(91, 84, 'panel', 'committee_chair', '2025-12-29 20:05:18'),
(92, 84, 'committee_chair', 'faculty', '2025-12-29 20:05:35'),
(93, 84, 'faculty', 'adviser', '2025-12-29 20:15:56'),
(94, 84, 'adviser', 'committee_chair', '2025-12-29 20:16:13'),
(95, 84, 'committee_chair', 'faculty', '2025-12-29 20:16:24'),
(96, 84, 'faculty', 'program_chairperson', '2025-12-29 20:22:25'),
(97, 84, 'program_chairperson', 'panel', '2025-12-29 20:22:33'),
(98, 84, 'panel', 'faculty', '2025-12-29 20:22:51'),
(99, 84, 'faculty', 'adviser', '2025-12-29 20:25:49'),
(100, 84, 'adviser', 'committee_chair', '2025-12-29 20:26:03'),
(101, 84, 'committee_chair', 'panel', '2025-12-29 20:26:17'),
(102, 84, 'panel', 'faculty', '2025-12-29 20:26:37'),
(103, 84, 'faculty', 'program_chairperson', '2025-12-29 20:27:11'),
(104, 84, 'program_chairperson', 'committee_chair', '2025-12-29 20:27:17'),
(105, 84, 'committee_chair', 'panel', '2025-12-29 20:27:24'),
(106, 84, 'panel', 'faculty', '2025-12-29 20:27:29'),
(107, 84, 'faculty', 'adviser', '2025-12-29 20:42:28'),
(108, 84, 'adviser', 'panel', '2025-12-29 20:42:48'),
(109, 84, 'panel', 'adviser', '2025-12-29 20:46:56'),
(110, 84, 'adviser', 'faculty', '2025-12-29 20:47:53'),
(111, 84, 'faculty', 'panel', '2025-12-29 20:48:06'),
(112, 84, 'panel', 'faculty', '2025-12-29 20:48:12'),
(113, 84, 'faculty', 'panel', '2025-12-29 20:48:42'),
(114, 84, 'panel', 'faculty', '2025-12-29 20:48:48'),
(115, 84, 'faculty', 'adviser', '2025-12-29 20:49:26'),
(116, 84, 'adviser', 'committee_chair', '2025-12-29 20:54:24'),
(117, 84, 'committee_chair', 'panel', '2025-12-29 20:54:32'),
(118, 84, 'panel', 'adviser', '2025-12-29 20:54:37'),
(119, 84, 'adviser', 'committee_chair', '2025-12-29 20:56:51'),
(120, 84, 'committee_chair', 'faculty', '2025-12-29 20:59:10'),
(121, 84, 'faculty', 'committee_chair', '2025-12-29 21:00:26'),
(122, 84, 'committee_chair', 'panel', '2025-12-29 21:01:46'),
(123, 84, 'panel', 'committee_chair', '2025-12-29 21:01:53'),
(124, 84, 'committee_chair', 'faculty', '2025-12-29 21:03:04'),
(125, 84, 'faculty', 'adviser', '2025-12-29 21:11:25'),
(126, 84, 'adviser', 'committee_chair', '2025-12-30 15:00:36'),
(127, 84, 'committee_chair', 'program_chairperson', '2025-12-30 15:03:58'),
(128, 84, 'program_chairperson', 'adviser', '2025-12-30 15:14:12'),
(129, 84, 'adviser', 'program_chairperson', '2025-12-30 15:14:38'),
(130, 84, 'program_chairperson', 'committee_chair', '2025-12-30 15:14:45'),
(131, 84, 'committee_chair', 'faculty', '2025-12-30 15:14:50'),
(132, 84, 'faculty', 'program_chairperson', '2025-12-30 16:47:40'),
(133, 84, 'program_chairperson', 'faculty', '2025-12-30 16:50:13'),
(134, 81, '', 'student', '2025-12-30 17:24:37'),
(135, 85, '', 'faculty', '2025-12-30 19:27:55'),
(136, 84, '', 'faculty', '2025-12-30 19:28:15'),
(137, 85, '', 'faculty', '2025-12-30 19:28:42'),
(138, 84, '', 'faculty', '2025-12-30 19:30:54'),
(139, 84, 'faculty', 'adviser', '2025-12-30 19:38:55'),
(140, 84, 'adviser', 'faculty', '2025-12-30 19:41:09'),
(141, 84, 'faculty', 'committee_chair', '2025-12-30 19:58:04'),
(142, 84, 'committee_chair', 'faculty', '2025-12-30 19:58:11'),
(143, 84, 'faculty', 'panel', '2025-12-30 20:29:01'),
(144, 84, 'panel', 'faculty', '2025-12-30 20:29:14'),
(145, 84, 'faculty', 'committee_chair', '2025-12-30 20:36:01'),
(146, 84, 'committee_chair', 'adviser', '2025-12-30 20:38:14'),
(147, 84, 'adviser', 'faculty', '2025-12-30 20:39:58'),
(148, 84, 'faculty', 'committee_chair', '2025-12-30 20:40:05'),
(149, 84, 'committee_chair', 'adviser', '2025-12-30 20:43:11'),
(150, 84, 'adviser', 'committee_chair', '2025-12-30 20:43:18'),
(151, 84, 'committee_chair', 'adviser', '2025-12-30 20:43:48'),
(152, 84, 'adviser', 'committee_chair', '2025-12-30 20:43:54'),
(153, 84, 'committee_chair', 'faculty', '2025-12-30 20:49:56'),
(154, 84, 'faculty', 'committee_chair', '2025-12-30 20:50:01'),
(155, 84, 'committee_chair', 'panel', '2025-12-30 20:50:31'),
(156, 84, 'panel', 'faculty', '2025-12-30 20:51:25'),
(157, 84, 'faculty', 'committee_chair', '2025-12-30 20:52:16'),
(158, 84, 'committee_chair', 'adviser', '2025-12-30 20:52:20'),
(159, 84, 'adviser', 'committee_chair', '2025-12-30 20:52:25'),
(160, 84, 'committee_chair', 'program_chairperson', '2025-12-30 20:52:29'),
(161, 84, 'program_chairperson', 'faculty', '2025-12-30 20:52:34'),
(162, 84, 'faculty', 'panel', '2025-12-30 20:52:39'),
(163, 84, 'panel', 'faculty', '2025-12-30 20:54:00'),
(164, 84, 'faculty', 'panel', '2025-12-30 20:54:03'),
(165, 84, 'panel', 'adviser', '2025-12-30 20:54:13'),
(166, 84, 'adviser', 'panel', '2025-12-30 20:55:24'),
(167, 84, '', 'panel', '2025-12-30 21:04:44'),
(168, 84, 'panel', 'committee_chair', '2025-12-30 21:13:50'),
(169, 84, 'committee_chair', 'faculty', '2025-12-30 21:13:58'),
(170, 84, 'faculty', 'panel', '2025-12-30 21:36:56'),
(171, 84, 'panel', 'adviser', '2025-12-30 21:37:03'),
(172, 84, 'adviser', 'committee_chair', '2025-12-30 21:37:13'),
(173, 84, 'committee_chair', 'adviser', '2025-12-30 21:37:26'),
(174, 84, 'adviser', 'faculty', '2025-12-30 21:37:34'),
(175, 84, 'faculty', 'panel', '2025-12-30 21:37:42'),
(176, 84, '', 'panel', '2026-01-01 03:05:57'),
(177, 84, 'panel', 'faculty', '2026-01-01 03:06:07'),
(178, 84, 'faculty', 'program_chairperson', '2026-01-01 03:08:03'),
(179, 84, 'program_chairperson', 'committee_chair', '2026-01-01 03:08:25'),
(180, 84, 'committee_chair', 'adviser', '2026-01-01 03:08:33'),
(181, 85, '', 'faculty', '2026-01-01 03:10:59'),
(182, 81, '', 'student', '2026-01-01 03:11:52'),
(183, 84, 'adviser', 'program_chairperson', '2026-01-01 03:49:36'),
(184, 84, 'program_chairperson', 'faculty', '2026-01-01 04:10:02'),
(185, 84, 'faculty', 'panel', '2026-01-01 04:10:08'),
(186, 84, 'panel', 'committee_chair', '2026-01-01 04:10:23'),
(187, 84, 'committee_chair', 'program_chairperson', '2026-01-01 04:59:00'),
(188, 84, 'program_chairperson', 'panel', '2026-01-01 04:59:20'),
(189, 84, 'panel', 'faculty', '2026-01-01 04:59:25'),
(190, 84, 'faculty', 'adviser', '2026-01-01 04:59:35'),
(191, 84, 'adviser', 'faculty', '2026-01-01 04:59:44'),
(192, 84, 'faculty', 'committee_chair', '2026-01-01 05:00:00'),
(193, 84, 'committee_chair', 'program_chairperson', '2026-01-01 05:01:18'),
(194, 84, 'program_chairperson', 'faculty', '2026-01-01 09:45:50'),
(195, 84, 'faculty', 'program_chairperson', '2026-01-01 09:46:51'),
(196, 84, 'program_chairperson', 'panel', '2026-01-01 11:04:50'),
(197, 84, 'panel', 'faculty', '2026-01-01 11:05:00'),
(198, 84, 'faculty', 'committee_chair', '2026-01-01 11:05:09'),
(199, 84, 'committee_chair', 'panel', '2026-01-01 11:10:00'),
(200, 84, 'panel', 'adviser', '2026-01-01 11:10:26'),
(201, 84, 'adviser', 'faculty', '2026-01-01 11:11:07'),
(202, 84, 'faculty', 'program_chairperson', '2026-01-01 11:11:16'),
(203, 84, 'program_chairperson', 'panel', '2026-01-01 11:11:25'),
(204, 84, 'panel', 'program_chairperson', '2026-01-01 11:11:38'),
(205, 84, '', 'program_chairperson', '2026-01-01 11:24:59'),
(206, 84, 'program_chairperson', 'faculty', '2026-01-01 11:40:21'),
(207, 84, 'faculty', 'adviser', '2026-01-01 11:40:28'),
(208, 84, 'adviser', 'committee_chair', '2026-01-01 11:40:53'),
(209, 84, 'committee_chair', 'program_chairperson', '2026-01-01 11:41:16'),
(210, 86, '', 'panel', '2026-01-01 11:53:46'),
(211, 86, 'panel', 'faculty', '2026-01-01 11:53:57'),
(212, 86, 'faculty', 'panel', '2026-01-01 11:54:10'),
(213, 86, '', 'panel', '2026-01-01 11:55:04'),
(214, 86, 'panel', 'adviser', '2026-01-01 11:55:13'),
(215, 86, 'adviser', 'committee_chair', '2026-01-01 11:56:14'),
(216, 86, 'committee_chair', 'adviser', '2026-01-01 11:56:20'),
(217, 86, 'adviser', 'panel', '2026-01-01 11:56:24'),
(218, 81, '', 'student', '2026-01-01 12:01:47'),
(219, 86, '', 'panel', '2026-01-01 12:07:19'),
(220, 86, 'panel', 'faculty', '2026-01-01 12:07:34'),
(221, 87, '', 'committee_chair', '2026-01-01 12:09:03'),
(222, 87, 'committee_chair', 'faculty', '2026-01-01 12:09:14'),
(223, 87, 'faculty', 'committee_chair', '2026-01-01 12:09:35'),
(224, 87, 'committee_chair', 'adviser', '2026-01-01 12:09:42'),
(225, 87, 'adviser', 'panel', '2026-01-01 12:09:49'),
(226, 87, 'panel', 'faculty', '2026-01-01 12:10:15'),
(227, 84, 'program_chairperson', 'panel', '2026-01-01 12:26:20'),
(228, 84, 'panel', 'adviser', '2026-01-01 12:26:27'),
(229, 84, 'adviser', 'program_chairperson', '2026-01-01 12:26:34'),
(230, 84, 'program_chairperson', 'panel', '2026-01-01 12:28:12'),
(231, 84, 'panel', 'faculty', '2026-01-01 12:28:22'),
(232, 84, 'faculty', 'committee_chair', '2026-01-01 12:28:28'),
(233, 84, 'committee_chair', 'adviser', '2026-01-01 12:28:37'),
(234, 84, 'adviser', 'panel', '2026-01-01 12:28:46'),
(235, 84, 'panel', 'program_chairperson', '2026-01-01 12:32:01'),
(236, 84, 'program_chairperson', 'faculty', '2026-01-01 12:32:17'),
(237, 84, 'faculty', 'committee_chair', '2026-01-01 12:32:22'),
(238, 87, 'faculty', 'committee_chair', '2026-01-01 12:35:14'),
(239, 87, 'committee_chair', 'adviser', '2026-01-01 12:35:20'),
(240, 87, 'adviser', 'committee_chair', '2026-01-01 12:35:32'),
(241, 87, 'committee_chair', 'adviser', '2026-01-01 12:35:50'),
(242, 87, 'adviser', 'panel', '2026-01-01 12:35:55'),
(243, 87, 'panel', 'faculty', '2026-01-01 12:38:55'),
(244, 87, 'faculty', 'panel', '2026-01-01 12:39:01'),
(245, 87, 'panel', 'faculty', '2026-01-01 12:41:40'),
(246, 87, 'faculty', 'panel', '2026-01-01 12:41:45');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `advisory_messages`
--
ALTER TABLE `advisory_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_adviser_student` (`adviser_id`,`student_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `fk_advisory_student` (`student_id`);

--
-- Indexes for table `committee_invitations`
--
ALTER TABLE `committee_invitations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_committee_invite_defense` (`defense_id`),
  ADD KEY `fk_committee_invite_chair` (`committee_chair_id`);

--
-- Indexes for table `concept_papers`
--
ALTER TABLE `concept_papers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `concept_reviewer_assignments`
--
ALTER TABLE `concept_reviewer_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_assignment` (`concept_paper_id`,`reviewer_id`,`reviewer_role`),
  ADD KEY `idx_reviewer` (`reviewer_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_role_status` (`reviewer_role`,`status`),
  ADD KEY `idx_due` (`due_at`);

--
-- Indexes for table `concept_reviews`
--
ALTER TABLE `concept_reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_review` (`assignment_id`,`concept_paper_id`),
  ADD KEY `idx_review_reviewer` (`reviewer_id`),
  ADD KEY `idx_review_concept` (`concept_paper_id`);

--
-- Indexes for table `concept_review_messages`
--
ALTER TABLE `concept_review_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assignment` (`assignment_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `fk_crm_sender` (`sender_id`);

--
-- Indexes for table `defense_panels`
--
ALTER TABLE `defense_panels`
  ADD PRIMARY KEY (`id`),
  ADD KEY `defense_id` (`defense_id`),
  ADD KEY `fk_panel_member_user` (`panel_member_id`);

--
-- Indexes for table `defense_schedules`
--
ALTER TABLE `defense_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `final_concept_submissions`
--
ALTER TABLE `final_concept_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_final_concept_student` (`student_id`),
  ADD KEY `fk_final_concept_paper` (`concept_paper_id`);

--
-- Indexes for table `final_paper_submissions`
--
ALTER TABLE `final_paper_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_final_paper_student` (`student_id`),
  ADD KEY `fk_final_paper_decider` (`final_decision_by`);

--
-- Indexes for table `final_paper_reviews`
--
ALTER TABLE `final_paper_reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_submission_reviewer` (`submission_id`,`reviewer_id`),
  ADD KEY `idx_final_paper_reviewer` (`reviewer_id`);

--
-- Indexes for table `final_endorsement_submissions`
--
ALTER TABLE `final_endorsement_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_final_endorsement_student` (`student_id`),
  ADD KEY `fk_final_endorsement_reviewer` (`reviewed_by`);

--
-- Indexes for table `defense_outcomes`
--
ALTER TABLE `defense_outcomes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_defense_outcome` (`defense_id`),
  ADD KEY `fk_defense_outcome_student` (`student_id`),
  ADD KEY `fk_defense_outcome_set_by` (`set_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_is_read` (`is_read`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `payment_proofs`
--
ALTER TABLE `payment_proofs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `research_archive`
--
ALTER TABLE `research_archive`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_submission` (`submission_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_doc_type` (`doc_type`),
  ADD KEY `fk_archive_user` (`archived_by`);

--
-- Indexes for table `reviewer_invite_feedback`
--
ALTER TABLE `reviewer_invite_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assignment` (`assignment_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_reviewer` (`reviewer_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`code`);

--
-- Indexes for table `status_logs`
--
ALTER TABLE `status_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submission_id` (`submission_id`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `status_updates`
--
ALTER TABLE `status_updates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submission_id` (`submission_id`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `submissions`
--
ALTER TABLE `submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `submission_feedback`
--
ALTER TABLE `submission_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_submission_feedback_submission` (`submission_id`),
  ADD KEY `idx_submission_feedback_student` (`student_id`),
  ADD KEY `submission_feedback_fk_chair` (`chair_id`);

--
-- Indexes for table `submission_reviews`
--
ALTER TABLE `submission_reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `submission_reviewer_unique` (`submission_id`,`reviewer_id`),
  ADD KEY `idx_submission_id` (`submission_id`),
  ADD KEY `idx_reviewer_id` (`reviewer_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_events`
--
ALTER TABLE `user_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_role_events` (`user_id`,`role`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_code`),
  ADD KEY `idx_user_roles_primary` (`user_id`,`is_primary`),
  ADD KEY `fk_user_roles_role` (`role_code`);

--
-- Indexes for table `user_role_switch_logs`
--
ALTER TABLE `user_role_switch_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_role_switch_user` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `advisory_messages`
--
ALTER TABLE `advisory_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `committee_invitations`
--
ALTER TABLE `committee_invitations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `concept_papers`
--
ALTER TABLE `concept_papers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- AUTO_INCREMENT for table `concept_reviewer_assignments`
--
ALTER TABLE `concept_reviewer_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=358;

--
-- AUTO_INCREMENT for table `concept_reviews`
--
ALTER TABLE `concept_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

--
-- AUTO_INCREMENT for table `concept_review_messages`
--
ALTER TABLE `concept_review_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `defense_panels`
--
ALTER TABLE `defense_panels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=172;

--
-- AUTO_INCREMENT for table `defense_schedules`
--
ALTER TABLE `defense_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `final_concept_submissions`
--
ALTER TABLE `final_concept_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `final_paper_submissions`
--
ALTER TABLE `final_paper_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `final_paper_reviews`
--
ALTER TABLE `final_paper_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `final_endorsement_submissions`
--
ALTER TABLE `final_endorsement_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `defense_outcomes`
--
ALTER TABLE `defense_outcomes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=856;

--
-- AUTO_INCREMENT for table `payment_proofs`
--
ALTER TABLE `payment_proofs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `research_archive`
--
ALTER TABLE `research_archive`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `reviewer_invite_feedback`
--
ALTER TABLE `reviewer_invite_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `status_logs`
--
ALTER TABLE `status_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `status_updates`
--
ALTER TABLE `status_updates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `submissions`
--
ALTER TABLE `submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `submission_feedback`
--
ALTER TABLE `submission_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `submission_reviews`
--
ALTER TABLE `submission_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

--
-- AUTO_INCREMENT for table `user_events`
--
ALTER TABLE `user_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `user_role_switch_logs`
--
ALTER TABLE `user_role_switch_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=247;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `advisory_messages`
--
ALTER TABLE `advisory_messages`
  ADD CONSTRAINT `fk_advisory_adviser` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_advisory_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `committee_invitations`
--
ALTER TABLE `committee_invitations`
  ADD CONSTRAINT `fk_committee_invite_chair` FOREIGN KEY (`committee_chair_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_committee_invite_defense` FOREIGN KEY (`defense_id`) REFERENCES `defense_schedules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `concept_reviewer_assignments`
--
ALTER TABLE `concept_reviewer_assignments`
  ADD CONSTRAINT `fk_assignment_concept` FOREIGN KEY (`concept_paper_id`) REFERENCES `concept_papers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_assignment_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_assignment_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `concept_reviews`
--
ALTER TABLE `concept_reviews`
  ADD CONSTRAINT `fk_review_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `concept_reviewer_assignments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `concept_review_messages`
--
ALTER TABLE `concept_review_messages`
  ADD CONSTRAINT `fk_crm_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `concept_reviewer_assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_crm_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `defense_panels`
--
ALTER TABLE `defense_panels`
  ADD CONSTRAINT `defense_panels_ibfk_1` FOREIGN KEY (`defense_id`) REFERENCES `defense_schedules` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_panel_member_user` FOREIGN KEY (`panel_member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `defense_schedules`
--
ALTER TABLE `defense_schedules`
  ADD CONSTRAINT `defense_schedules_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `final_concept_submissions`
--
ALTER TABLE `final_concept_submissions`
  ADD CONSTRAINT `fk_final_concept_paper` FOREIGN KEY (`concept_paper_id`) REFERENCES `concept_papers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_final_concept_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `final_paper_submissions`
--
ALTER TABLE `final_paper_submissions`
  ADD CONSTRAINT `fk_final_paper_decider` FOREIGN KEY (`final_decision_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_final_paper_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `final_paper_reviews`
--
ALTER TABLE `final_paper_reviews`
  ADD CONSTRAINT `fk_final_paper_review_submission` FOREIGN KEY (`submission_id`) REFERENCES `final_paper_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_final_paper_review_user` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `final_endorsement_submissions`
--
ALTER TABLE `final_endorsement_submissions`
  ADD CONSTRAINT `fk_final_endorsement_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_final_endorsement_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `defense_outcomes`
--
ALTER TABLE `defense_outcomes`
  ADD CONSTRAINT `fk_defense_outcome_defense` FOREIGN KEY (`defense_id`) REFERENCES `defense_schedules` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_defense_outcome_set_by` FOREIGN KEY (`set_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_defense_outcome_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `research_archive`
--
ALTER TABLE `research_archive`
  ADD CONSTRAINT `fk_archive_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_archive_submission` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_archive_user` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `reviewer_invite_feedback`
--
ALTER TABLE `reviewer_invite_feedback`
  ADD CONSTRAINT `fk_feedback_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `concept_reviewer_assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_feedback_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_feedback_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `status_logs`
--
ALTER TABLE `status_logs`
  ADD CONSTRAINT `status_logs_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `status_logs_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `status_updates`
--
ALTER TABLE `status_updates`
  ADD CONSTRAINT `status_updates_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `status_updates_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `submissions`
--
ALTER TABLE `submissions`
  ADD CONSTRAINT `submissions_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `submission_feedback`
--
ALTER TABLE `submission_feedback`
  ADD CONSTRAINT `submission_feedback_fk_chair` FOREIGN KEY (`chair_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `submission_feedback_fk_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `submission_feedback_fk_submission` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_events`
--
ALTER TABLE `user_events`
  ADD CONSTRAINT `user_events_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_code`) REFERENCES `roles` (`code`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_role_switch_logs`
--
ALTER TABLE `user_role_switch_logs`
  ADD CONSTRAINT `fk_role_switch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

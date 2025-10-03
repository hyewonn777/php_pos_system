-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 03, 2025 at 02:37 PM
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
-- Database: `pos`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`id`, `user_id`, `username`, `password_hash`, `created_at`) VALUES
(1, 1, 'admin', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', '2025-09-09 23:49:10'),
(9, 2, 'admin1', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', '2025-09-18 02:29:31'),
(10, NULL, 'admin2', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', '2025-09-20 10:57:53');

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `customer` varchar(255) NOT NULL,
  `service` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Cancelled') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `customer`, `service`, `date`, `start_time`, `end_time`, `location`, `status`, `created_at`) VALUES
(8, 'Kent Medina', 'Photography', '2025-09-13', '20:00:00', '21:00:00', 'Street 123', 'Cancelled', '2025-09-13 11:53:17'),
(9, 'Christian Robaro Narvaez', 'Photography', '2025-09-13', '21:00:00', '22:30:00', 'Banadero P-1', 'Cancelled', '2025-09-13 11:54:10'),
(10, 'Chan-chan', 'Photography', '2025-09-15', '13:40:00', '13:50:00', 'Banadero P-1', 'Approved', '2025-09-15 05:37:37'),
(11, 'Rodson Amisola', 'Photography', '2025-09-15', '13:40:00', '13:50:00', 'Banadero P-1', 'Approved', '2025-09-15 05:38:36'),
(12, 'Kent Medina', 'Photography', '2025-09-18', '16:00:00', '16:10:00', 'Street 123', 'Approved', '2025-09-18 05:11:23'),
(13, 'Jarren Chesterson Paccaro', 'Photography', '2025-09-20', '22:50:00', '22:52:00', 'Street 123', 'Approved', '2025-09-19 02:23:02'),
(14, 'Neil Vincent', 'Photography', '2025-09-19', '14:30:00', '15:00:00', 'Street 123', 'Approved', '2025-09-19 02:35:15'),
(15, 'Neil Kevin', 'Photography', '2025-09-19', '11:16:00', '11:17:00', 'Street 123', 'Approved', '2025-09-19 03:15:41'),
(16, 'Kris Louise Luna', 'Photography', '2025-09-30', '10:40:00', '16:40:00', 'Street 123', 'Approved', '2025-09-29 02:40:26'),
(19, 'Lei Francine Biot', 'Photography', '2025-10-04', '10:59:00', '11:59:00', 'Street 123', 'Approved', '2025-10-02 02:59:19'),
(20, 'Kris Louise Luna', 'Photography', '2025-10-04', '16:43:00', '17:43:00', 'Street 123', 'Approved', '2025-10-02 07:43:31'),
(21, 'Kris Louise Luna', 'Photography', '2025-10-04', '20:07:00', '21:07:00', 'Street 123', 'Approved', '2025-10-03 11:07:39');

-- --------------------------------------------------------

--
-- Table structure for table `bills`
--

CREATE TABLE `bills` (
  `id` int(11) NOT NULL,
  `created_by` varchar(50) NOT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bill_items`
--

CREATE TABLE `bill_items` (
  `id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `qty` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `design_type` varchar(50) NOT NULL,
  `design_data` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `category`
--

CREATE TABLE `category` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(50) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `quantity` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `role`, `customer_id`, `customer_name`, `status`, `created_at`, `quantity`) VALUES
(12, 0, '', NULL, 'admin', 'Confirmed', '2025-09-22 07:17:38', 1),
(13, 0, '', NULL, 'admin', 'Confirmed', '2025-09-22 09:57:34', 1),
(14, 0, '', NULL, 'admin', 'Confirmed', '2025-09-22 11:10:13', 1),
(15, 0, '', NULL, 'admin', 'Confirmed', '2025-09-24 13:36:09', 1),
(16, 0, '', NULL, 'admin', 'Confirmed', '2025-09-24 13:37:47', 1),
(17, 0, '', NULL, 'admin', 'Confirmed', '2025-09-26 16:23:10', 1),
(18, 0, '', NULL, 'admin', 'Received', '2025-09-27 14:39:26', 1),
(31, 1, 'admin', NULL, 'admin', 'Received', '2025-10-03 14:34:43', 20),
(32, 1, 'admin', NULL, 'admin', 'Received', '2025-10-03 14:43:48', 2),
(33, 1, 'admin', NULL, 'admin', 'Received', '2025-10-03 15:11:22', 20),
(34, 1, 'admin', NULL, 'admin', 'Received', '2025-10-03 16:06:14', 1);

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `quantity`) VALUES
(9, 12, 702, 10),
(10, 13, 753, 20),
(11, 14, 705, 1),
(12, 15, 702, 10),
(13, 16, 703, 50),
(14, 17, 706, 50),
(15, 18, 704, 1),
(28, 31, 770, 20),
(29, 32, 770, 2),
(30, 33, 770, 20),
(31, 34, 770, 1);

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `sale_date` datetime NOT NULL DEFAULT current_timestamp(),
  `product` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `status` enum('pending','paid','cancelled') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `product_id`, `sale_date`, `product`, `quantity`, `total`, `status`) VALUES
(33, NULL, '2025-09-21 15:19:48', 'Lorenxon Nic', 17, 1700.00, 'pending'),
(34, NULL, '2025-09-22 07:18:02', 'Regular', 10, 1500.00, 'pending'),
(35, NULL, '2025-09-22 09:57:41', 'Glass', 20, 6000.00, 'pending'),
(36, NULL, '2025-09-22 11:10:21', 'Transparent', 1, 200.00, 'pending'),
(37, NULL, '2025-09-24 13:36:44', 'Regular', 10, 1500.00, 'pending'),
(38, NULL, '2025-09-24 13:37:52', 'Magic', 50, 15000.00, 'pending'),
(39, NULL, '2025-09-26 16:23:18', 'Beer', 50, 15000.00, 'pending'),
(40, NULL, '2025-09-27 14:39:37', 'Frosted', 1, 200.00, 'pending'),
(41, NULL, '2025-10-03 14:41:47', 'Invitation Card', 20, 800.00, 'pending'),
(42, NULL, '2025-10-03 14:44:02', 'Invitation Card', 2, 80.00, 'pending'),
(43, NULL, '2025-10-03 16:06:22', 'Invitation Card', 1, 40.00, 'pending'),
(44, NULL, '2025-10-03 16:06:24', 'Invitation Card', 20, 800.00, 'pending');

-- --------------------------------------------------------

--
-- Table structure for table `stock`
--

CREATE TABLE `stock` (
  `id` int(11) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `product` varchar(100) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `stock`
--

INSERT INTO `stock` (`id`, `category`, `product`, `name`, `price`, `qty`, `image_path`, `created_at`, `status`) VALUES
(621, '', NULL, '', 1.00, 28, '', '2025-09-21 01:51:32', 'inactive'),
(702, 'Drinkware', NULL, 'Regular', 150.00, 80, '', '2025-09-21 14:45:13', 'inactive'),
(703, 'Drinkware', NULL, 'Magic Mug', 300.00, 19, '', '2025-09-21 14:45:13', 'active'),
(704, 'Drinkware', NULL, 'Frosted Mug', 200.00, 99, '', '2025-09-21 14:45:13', 'active'),
(705, 'Drinkware', NULL, 'Transparent Mug', 200.00, 99, '', '2025-09-21 14:45:13', 'active'),
(706, 'Drinkware', NULL, 'Beer Mug', 300.00, 50, '', '2025-09-21 14:45:13', 'active'),
(707, 'Drinkware', NULL, 'Tumbler', 500.00, 100, '', '2025-09-21 14:45:13', 'active'),
(708, 'Accessories & Small Items', NULL, 'Foldable Fan', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(709, 'Accessories & Small Items', NULL, 'Ref Magnet', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(710, 'Accessories & Small Items', NULL, 'Keychain', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(711, 'Accessories & Small Items', NULL, 'Bottle Operner', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(712, 'Accessories & Small Items', NULL, 'Magic Mirror', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(713, 'Accessories & Small Items', NULL, 'Cellphone Case', 200.00, 100, '', '2025-09-21 14:45:13', 'active'),
(714, 'Accessories & Small Items', NULL, 'Wallet', 300.00, 100, '', '2025-09-21 14:45:13', 'active'),
(715, 'Accessories & Small Items', NULL, 'Notebook', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(716, 'Accessories & Small Items', NULL, 'Pen', 40.00, 100, '', '2025-09-21 14:45:13', 'active'),
(717, 'Accessories & Small Items', NULL, 'Pencil', 30.00, 100, '', '2025-09-21 14:45:13', 'active'),
(718, 'Accessories & Small Items', NULL, 'Planner', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(719, 'Accessories & Small Items', NULL, 'Cap', 200.00, 100, '', '2025-09-21 14:45:13', 'active'),
(720, 'Accessories & Small Items', NULL, 'Handkerchief', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(721, 'Accessories & Small Items', NULL, 'Umbrella', 150.00, 100, '', '2025-09-21 14:45:13', 'active'),
(722, 'Accessories & Small Items', NULL, 'Gaming Mousepad', 200.00, 100, '', '2025-09-21 14:45:13', 'active'),
(723, 'Accessories & Small Items', NULL, 'Mousepad', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(724, 'Accessories & Small Items', NULL, 'Tote Bag', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(725, 'Accessories & Small Items', NULL, 'Eco Bag', 30.00, 100, '', '2025-09-21 14:45:13', 'active'),
(726, 'Apparel', NULL, 'Tshirt', 200.00, 100, '', '2025-09-21 14:45:13', 'active'),
(727, 'Apparel', NULL, 'Jacket', 300.00, 100, '', '2025-09-21 14:45:13', 'active'),
(728, 'Apparel', NULL, 'Vest', 150.00, 100, '', '2025-09-21 14:45:13', 'active'),
(729, 'Apparel', NULL, 'Jersey', 200.00, 100, '', '2025-09-21 14:45:13', 'active'),
(730, 'Apparel', NULL, 'Polo Shirt', 250.00, 100, '', '2025-09-21 14:45:13', 'active'),
(731, 'Apparel', NULL, 'Sweatshirt', 300.00, 100, '', '2025-09-21 14:45:13', 'active'),
(732, 'Apparel', NULL, 'PE Uniform', 250.00, 100, '', '2025-09-21 14:45:13', 'active'),
(733, 'Apparel', NULL, 'Apron', 150.00, 100, '', '2025-09-21 14:45:13', 'active'),
(734, 'Apparel', NULL, 'Sablay', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(735, 'Awards & Corporate Items', NULL, 'Table Nametag', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(736, 'Awards & Corporate Items', NULL, 'Name Plate', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(737, 'Awards & Corporate Items', NULL, 'Badge Pin', 25.00, 100, '', '2025-09-21 14:45:13', 'active'),
(738, 'Awards & Corporate Items', NULL, 'Button Pin', 25.00, 100, '', '2025-09-21 14:45:13', 'active'),
(739, 'Awards & Corporate Items', NULL, 'Lanyard', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(740, 'Awards & Corporate Items', NULL, 'PVC ID', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(741, 'Awards & Corporate Items', NULL, 'Lamination', 20.00, 100, '', '2025-09-21 14:45:13', 'active'),
(742, 'Awards & Corporate Items', NULL, 'Trophy', 200.00, 100, '', '2025-09-21 14:45:13', 'active'),
(743, 'Awards & Corporate Items', NULL, 'Plaque', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(744, 'Awards & Corporate Items', NULL, 'Medal', 250.00, 100, '', '2025-09-21 14:45:13', 'active'),
(745, 'Awards & Corporate Items', NULL, 'Sash', 150.00, 100, '', '2025-09-21 14:45:13', 'active'),
(746, 'Awards & Corporate Items', NULL, 'Lei', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(747, 'Home & Office Decor', NULL, 'Plate', 300.00, 100, '', '2025-09-21 14:45:13', 'active'),
(748, 'Home & Office Decor', NULL, 'Rock Photo', 200.00, 100, '', '2025-09-21 14:45:13', 'active'),
(749, 'Home & Office Decor', NULL, 'Glass Clock', 250.00, 100, '', '2025-09-21 14:45:13', 'active'),
(750, 'Home & Office Decor', NULL, 'Pillow', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(751, 'Home & Office Decor', NULL, 'Frame', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(752, 'Home & Office Decor', NULL, 'Sintra Board', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(753, 'Home & Office Decor', NULL, 'Glass', 300.00, 80, '', '2025-09-21 14:45:13', 'active'),
(754, 'Home & Office Decor', NULL, 'Arcrylic', 200.00, 100, '', '2025-09-21 14:45:13', 'active'),
(755, 'Home & Office Decor', NULL, 'Directory', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(756, 'Home & Office Decor', NULL, 'Wall Frame', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(757, 'Home & Office Decor', NULL, 'Stand Frame', 75.00, 100, '', '2025-09-21 14:45:13', 'active'),
(758, 'Home & Office Decor', NULL, '3D Frame', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(759, 'Printing & Stationery', NULL, 'Document Printing', 20.00, 100, '', '2025-09-21 14:45:13', 'active'),
(760, 'Printing & Stationery', NULL, 'Magazine', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(761, 'Printing & Stationery', NULL, 'Brochure', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(762, 'Printing & Stationery', NULL, 'Bookbinding', 300.00, 100, '', '2025-09-21 14:45:13', 'active'),
(763, 'Printing & Stationery', NULL, 'Yearbook', 300.00, 100, '', '2025-09-21 14:45:13', 'active'),
(764, 'Printing & Stationery', NULL, 'Programme', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(765, 'Printing & Stationery', NULL, 'Booklet', 50.00, 100, '', '2025-09-21 14:45:13', 'active'),
(766, 'Printing & Stationery', NULL, 'Photo Printing', 100.00, 100, '', '2025-09-21 14:45:13', 'active'),
(767, 'Printing & Stationery', NULL, 'Banner', 75.00, 100, '', '2025-09-21 14:45:13', 'active'),
(768, 'Printing & Stationery', NULL, 'Sticker', 20.00, 100, '', '2025-09-21 14:45:13', 'active'),
(769, 'Printing & Stationery', NULL, 'Calling Card', 30.00, 100, '', '2025-09-21 14:45:13', 'active'),
(770, 'Printing & Stationery', NULL, 'Invitation Card', 40.00, 14, '', '2025-09-21 14:45:13', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `summary`
--

CREATE TABLE `summary` (
  `id` int(11) NOT NULL,
  `total_sales` double DEFAULT 0,
  `total_revenue` double DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `summary`
--

INSERT INTO `summary` (`id`, `total_sales`, `total_revenue`) VALUES
(1, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','photographer','customer') NOT NULL DEFAULT 'customer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','photographer','customer') NOT NULL DEFAULT 'customer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `username`, `email`, `password_hash`, `role`, `created_at`) VALUES
(1, 'admin', 'admin', 'admin@example.com', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', 'admin', '2025-09-20 10:07:29'),
(2, 'admin1', 'admin1', 'admin1@example.com', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', 'admin', '2025-09-20 10:07:29'),
(4, 'John Francis Dula Jr', 'francis@photographer', 'francis@photographer@photographer.local', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'photographer', '2025-09-20 10:11:30'),
(5, 'Lorenxon Nic Batoctoy', 'nic@photographer', 'nic@photographer@photographer.local', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'photographer', '2025-09-20 10:11:30'),
(9, 'Kris Louis Gabriel Luna', 'krisloi', 'krisloi@gmail.com', '$2y$10$orCJTTtlJXKVgswSRXSPhOYG4RUsp7TMMmRhoLfRl45ZIZSv2XKyy', 'customer', '2025-10-02 20:46:15');

-- --------------------------------------------------------

--
-- Table structure for table `website_orders`
--

CREATE TABLE `website_orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `status` enum('Pending','Confirmed','Cancelled') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `website_orders`
--

INSERT INTO `website_orders` (`id`, `user_id`, `product_id`, `quantity`, `status`, `created_at`) VALUES
(1, 1, 770, 1, 'Pending', '2025-09-30 18:57:42'),
(2, 1, 770, 1, 'Pending', '2025-09-30 19:02:04'),
(3, 1, 770, 1, 'Pending', '2025-09-30 19:09:08'),
(4, 1, 768, 17, 'Pending', '2025-09-30 19:18:28'),
(5, 1, 769, 1, 'Pending', '2025-09-30 19:19:00'),
(6, 1, 769, 1, 'Pending', '2025-10-02 05:03:56'),
(7, 1, 770, 1, 'Pending', '2025-10-02 05:03:56'),
(8, 1, 770, 1, 'Pending', '2025-10-02 05:05:04'),
(9, 1, 770, 1, 'Pending', '2025-10-02 05:15:31'),
(10, 1, 763, 1, 'Pending', '2025-10-02 07:42:58'),
(11, 9, 770, 1, 'Pending', '2025-10-03 01:36:37'),
(12, 9, 770, 1, 'Pending', '2025-10-03 05:24:38');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_admin_user` (`user_id`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bills`
--
ALTER TABLE `bills`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bill_items`
--
ALTER TABLE `bill_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bill_id` (`bill_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `category`
--
ALTER TABLE `category`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sales_product` (`product_id`);

--
-- Indexes for table `stock`
--
ALTER TABLE `stock`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `summary`
--
ALTER TABLE `summary`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username_2` (`username`);

--
-- Indexes for table `website_orders`
--
ALTER TABLE `website_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `bills`
--
ALTER TABLE `bills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bill_items`
--
ALTER TABLE `bill_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `category`
--
ALTER TABLE `category`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `stock`
--
ALTER TABLE `stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=771;

--
-- AUTO_INCREMENT for table `summary`
--
ALTER TABLE `summary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `website_orders`
--
ALTER TABLE `website_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin`
--
ALTER TABLE `admin`
  ADD CONSTRAINT `fk_admin_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bill_items`
--
ALTER TABLE `bill_items`
  ADD CONSTRAINT `bill_items_ibfk_1` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bill_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `stock` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `stock` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `stock` (`id`);

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sales_product` FOREIGN KEY (`product_id`) REFERENCES `stock` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `website_orders`
--
ALTER TABLE `website_orders`
  ADD CONSTRAINT `website_orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `website_orders_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `stock` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

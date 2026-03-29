-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Mar 29, 2026 at 11:31 AM
-- Server version: 8.2.0
-- PHP Version: 8.3.0

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bookshop_managementsaas`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int NOT NULL,
  `tenant_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `user_type` enum('superadmin','tenant_admin','staff','customer','public') NOT NULL,
  `action` varchar(255) NOT NULL,
  `description` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `tenant_id`, `user_id`, `user_type`, `action`, `description`, `ip_address`, `timestamp`) VALUES
(1, 1, 1, 'tenant_admin', 'Login', 'Admin logged in', NULL, '2026-03-28 23:35:10'),
(2, 1, 1, 'tenant_admin', 'Add Book', 'Added PHP for Beginners', NULL, '2026-03-28 23:35:10'),
(3, NULL, 1, 'superadmin', 'Login', 'Superadmin logged in', NULL, '2026-03-28 23:35:10'),
(4, 1, 1, 'customer', 'Login', 'Customer logged in', NULL, '2026-03-28 23:35:10'),
(5, NULL, NULL, 'superadmin', 'Superadmin Login Failed', 'Failed login attempt for username: superadmin', '::1', '2026-03-28 23:46:07'),
(6, NULL, 1, 'superadmin', 'Superadmin Login', 'Superadmin logged in successfully.', '::1', '2026-03-28 23:46:28'),
(7, NULL, 1, 'superadmin', 'Superadmin Settings Update', 'Updated global system settings.', '::1', '2026-03-28 23:48:13'),
(8, NULL, 1, 'tenant_admin', 'Tenant User Login', 'User logged in successfully.', '::1', '2026-03-29 00:21:46'),
(9, NULL, 1, 'superadmin', 'Plan Save', 'Saved subscription plan: Enterprise Plan (ID: 4)', '::1', '2026-03-29 00:25:50'),
(10, NULL, 1, 'tenant_admin', 'Subscription Payment Submit', 'Submitted payment for PKR 14,995.00 for plan .', '::1', '2026-03-29 00:36:42'),
(11, NULL, 1, 'superadmin', 'Tenant Status Update', 'Tenant ID 1 status set to active.', '::1', '2026-03-29 00:49:52'),
(12, NULL, 1, '', 'Sale Complete', 'Completed sale (ID: 5) for total: 59.98', '::1', '2026-03-29 00:56:49'),
(13, NULL, 1, '', 'Product Update', 'Updated product: Advanced MySQL (ID: 2)', '::1', '2026-03-29 01:08:13'),
(14, NULL, 1, '', 'Product Update', 'Updated product: Blue Pen (ID: 4)', '::1', '2026-03-29 01:08:27'),
(15, NULL, 1, '', 'Product Update', 'Updated product: Notebook A4 (ID: 3)', '::1', '2026-03-29 01:12:04'),
(16, NULL, 1, '', 'Product Update', 'Updated product: PHP for Beginners (ID: 1)', '::1', '2026-03-29 01:12:21'),
(17, NULL, 1, '', 'Logout (Idle)', 'User logged out due to idle session timeout (40 min).', '::1', '2026-03-29 01:56:36'),
(18, NULL, 1, 'tenant_admin', 'Tenant User Login', 'User logged in successfully.', '::1', '2026-03-29 01:56:50'),
(19, NULL, 1, 'superadmin', 'Logout (Idle)', 'User logged out due to idle session timeout (40 min).', '::1', '2026-03-29 02:01:09'),
(20, NULL, 1, 'superadmin', 'Superadmin Login', 'Superadmin logged in successfully.', '::1', '2026-03-29 02:01:16'),
(21, NULL, NULL, 'superadmin', 'Superadmin Login Failed', 'Failed login attempt for username: admin@example.com', '::1', '2026-03-29 10:21:49'),
(22, NULL, 1, 'superadmin', 'Superadmin Login', 'Superadmin logged in successfully.', '::1', '2026-03-29 10:21:56'),
(23, NULL, NULL, 'tenant_admin', 'Tenant User Login Failed', 'Failed login attempt for username: admin', '::1', '2026-03-29 10:22:24'),
(24, NULL, NULL, 'tenant_admin', 'Tenant User Login Failed', 'Failed login attempt for username: admin', '::1', '2026-03-29 10:22:34'),
(25, NULL, 1, 'tenant_admin', 'Tenant User Login', 'User logged in successfully.', '::1', '2026-03-29 10:24:09');

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `product_type` enum('book','general') NOT NULL DEFAULT 'book',
  `author` varchar(255) DEFAULT NULL,
  `category` varchar(190) NOT NULL,
  `isbn` varchar(120) DEFAULT NULL,
  `barcode` varchar(120) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `year` int DEFAULT NULL,
  `price` decimal(12,2) NOT NULL,
  `purchase_price` decimal(12,2) DEFAULT NULL,
  `retail_price` decimal(12,2) DEFAULT NULL,
  `wholesale_price` decimal(12,2) DEFAULT NULL,
  `stock` int NOT NULL DEFAULT '0',
  `description` text,
  `cover_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`id`, `tenant_id`, `name`, `product_type`, `author`, `category`, `isbn`, `barcode`, `publisher`, `year`, `price`, `purchase_price`, `retail_price`, `wholesale_price`, `stock`, `description`, `cover_image`, `created_at`, `updated_at`) VALUES
(1, 1, 'PHP for Beginners', 'book', 'John Doe', 'Programming', 'Bn290', '10001', NULL, NULL, 29.99, 0.00, 29.99, 29.99, 48, NULL, 'C:\\phpserver\\www\\BookShopSaaS/uploads/shop1/covers/cover_69c87c75a6f8d.webp', '2026-03-28 23:35:10', '2026-03-29 01:12:21'),
(2, 1, 'Advanced MySQL', 'book', 'Jane Doe', 'Database', '290', '10002', '', 0, 39.99, 0.00, 39.99, 39.99, 30, '', 'C:\\phpserver\\www\\BookShopSaaS/uploads/shop1/covers/cover_69c87b7d86b3a.jpeg', '2026-03-28 23:35:10', '2026-03-29 01:08:13'),
(3, 1, 'Notebook A4', 'general', NULL, 'Stationery', NULL, '10003', NULL, NULL, 4.99, 0.00, 4.99, 4.99, 100, NULL, 'C:\\phpserver\\www\\BookShopSaaS/uploads/shop1/covers/cover_69c87c643d598.jpeg', '2026-03-28 23:35:10', '2026-03-29 01:12:04'),
(4, 1, 'Blue Pen', 'general', '', 'Stationery', NULL, '10004', '', 0, 1.99, 0.00, 1.99, 1.99, 200, '', 'C:\\phpserver\\www\\BookShopSaaS/uploads/shop1/covers/cover_69c87b8b0af83.webp', '2026-03-28 23:35:10', '2026-03-29 01:11:19');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `address` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `tenant_id`, `name`, `phone`, `email`, `password_hash`, `address`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Alice Smith', '111111111', 'alice@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(2, 1, 'Bob Jones', '222222222', 'bob@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(3, 1, 'Charlie Brown', '333333333', 'charlie@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(4, 1, 'Diana Prince', '444444444', 'delta@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(190) NOT NULL,
  `description` text,
  `amount` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `tenant_id`, `user_id`, `expense_date`, `category`, `description`, `amount`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-03-01', 'Rent', 'Shop Rent', 1000.00, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(2, 1, 1, '2026-03-05', 'Utilities', 'Electricity', 150.00, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(3, 1, 1, '2026-03-10', 'Marketing', 'Facebook Ads', 200.00, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(4, 1, 1, '2026-03-15', 'Supplies', 'Cleaning stuff', 50.00, '2026-03-28 23:35:10', '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `online_orders`
--

CREATE TABLE `online_orders` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `order_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `subtotal` decimal(12,2) NOT NULL,
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL,
  `promotion_code` varchar(190) DEFAULT NULL,
  `status` enum('pending','approved','rejected','delivered') NOT NULL DEFAULT 'pending',
  `sale_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `online_orders`
--

INSERT INTO `online_orders` (`id`, `tenant_id`, `customer_id`, `order_date`, `subtotal`, `discount`, `total`, `promotion_code`, `status`, `sale_id`) VALUES
(1, 1, 1, '2026-03-28 23:35:10', 29.99, 0.00, 29.99, NULL, 'pending', NULL),
(2, 1, 2, '2026-03-28 23:35:10', 39.99, 0.00, 39.99, NULL, 'approved', NULL),
(3, 1, 3, '2026-03-28 23:35:10', 9.98, 0.00, 9.98, NULL, 'rejected', NULL),
(4, 1, 4, '2026-03-28 23:35:10', 1.99, 0.00, 1.99, NULL, 'delivered', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `online_order_items`
--

CREATE TABLE `online_order_items` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `book_id` int NOT NULL,
  `quantity` int NOT NULL,
  `price_per_unit` decimal(12,2) NOT NULL,
  `discount_per_unit` decimal(12,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `online_order_items`
--

INSERT INTO `online_order_items` (`id`, `order_id`, `book_id`, `quantity`, `price_per_unit`, `discount_per_unit`) VALUES
(1, 1, 1, 1, 29.99, 0.00),
(2, 2, 2, 1, 39.99, 0.00),
(3, 3, 3, 2, 4.99, 0.00),
(4, 4, 4, 1, 1.99, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `plan_permissions`
--

CREATE TABLE `plan_permissions` (
  `id` int NOT NULL,
  `plan_id` int NOT NULL,
  `page_key` varchar(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `plan_permissions`
--

INSERT INTO `plan_permissions` (`id`, `plan_id`, `page_key`) VALUES
(2, 1, 'books'),
(3, 1, 'customers'),
(1, 1, 'dashboard'),
(4, 1, 'sales-history'),
(6, 2, 'books'),
(7, 2, 'customers'),
(5, 2, 'dashboard'),
(8, 2, 'sales-history'),
(10, 3, 'books'),
(11, 3, 'customers'),
(9, 3, 'dashboard'),
(12, 3, 'sales-history'),
(34, 4, 'backup-restore'),
(18, 4, 'books'),
(23, 4, 'cart'),
(20, 4, 'customers'),
(17, 4, 'dashboard'),
(27, 4, 'expenses'),
(29, 4, 'live-sales'),
(30, 4, 'news'),
(25, 4, 'online-orders'),
(33, 4, 'print-barcodes'),
(26, 4, 'promotions'),
(32, 4, 'public-sale-links'),
(22, 4, 'purchase-orders'),
(28, 4, 'reports'),
(24, 4, 'sales-history'),
(31, 4, 'settings'),
(21, 4, 'suppliers'),
(19, 4, 'users');

-- --------------------------------------------------------

--
-- Table structure for table `po_items`
--

CREATE TABLE `po_items` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `book_id` int NOT NULL,
  `quantity` int NOT NULL,
  `cost_per_unit` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `po_items`
--

INSERT INTO `po_items` (`id`, `po_id`, `book_id`, `quantity`, `cost_per_unit`) VALUES
(1, 1, 1, 10, 15.00),
(2, 2, 3, 20, 2.25),
(3, 3, 2, 15, 20.00),
(4, 4, 4, 50, 0.50);

-- --------------------------------------------------------

--
-- Table structure for table `promotions`
--

CREATE TABLE `promotions` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `code` varchar(190) NOT NULL,
  `type` enum('percentage','fixed') NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `applies_to` enum('all','specific-book','specific-category') NOT NULL,
  `applies_to_value` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `promotions`
--

INSERT INTO `promotions` (`id`, `tenant_id`, `code`, `type`, `value`, `applies_to`, `applies_to_value`, `start_date`, `end_date`, `created_at`, `updated_at`) VALUES
(1, 1, 'SUMMER10', 'percentage', 10.00, 'all', NULL, '2026-01-01', NULL, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(2, 1, 'WINTER5', 'fixed', 5.00, 'all', NULL, '2026-01-01', NULL, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(3, 1, 'BOOK20', 'percentage', 20.00, 'specific-category', NULL, '2026-01-01', NULL, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(4, 1, 'PENFREE', 'fixed', 1.99, 'specific-book', NULL, '2026-01-01', NULL, '2026-03-28 23:35:10', '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `public_news`
--

CREATE TABLE `public_news` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `public_news`
--

INSERT INTO `public_news` (`id`, `tenant_id`, `title`, `content`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Store Open', 'We are open for business!', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(2, 1, 'Sale', 'Big sale this weekend!', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(3, 1, 'New Arrivals', 'New books just arrived.', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(4, 1, 'Holiday', 'We will be closed on Friday.', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `public_sale_links`
--

CREATE TABLE `public_sale_links` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `token` varchar(120) NOT NULL,
  `link_name` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `price_mode` enum('retail','wholesale') NOT NULL DEFAULT 'retail',
  `created_by` int DEFAULT NULL,
  `notes` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `public_sale_links`
--

INSERT INTO `public_sale_links` (`id`, `tenant_id`, `token`, `link_name`, `password_hash`, `price_mode`, `created_by`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'token123', 'VIP Sale', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'retail', NULL, NULL, 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(2, 1, 'token456', 'Wholesale Partner', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'retail', NULL, NULL, 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(3, 1, 'token789', 'Student Discount', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'retail', NULL, NULL, 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(4, 1, 'tokenabc', 'Clearance Event', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'retail', NULL, NULL, 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `supplier_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `status` enum('pending','ordered','received','cancelled') NOT NULL DEFAULT 'pending',
  `total_cost` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`id`, `tenant_id`, `supplier_id`, `user_id`, `order_date`, `expected_date`, `status`, `total_cost`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '2026-03-01', NULL, 'received', 150.00, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(2, 1, 2, 1, '2026-03-05', NULL, 'pending', 45.00, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(3, 1, 3, 1, '2026-03-10', NULL, 'ordered', 300.00, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(4, 1, 4, 1, '2026-03-15', NULL, 'cancelled', 0.00, '2026-03-28 23:35:10', '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `pwa_settings`
--

CREATE TABLE `pwa_settings` (
  `tenant_id` int NOT NULL,
  `app_name` varchar(255) NOT NULL,
  `short_name` varchar(50) NOT NULL,
  `theme_color` varchar(7) NOT NULL DEFAULT '#2a9d8f',
  `background_color` varchar(7) NOT NULL DEFAULT '#ffffff',
  `icon_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `pwa_settings`
--

INSERT INTO `pwa_settings` (`tenant_id`, `app_name`, `short_name`, `theme_color`, `background_color`, `icon_path`) VALUES
(1, 'Alpha App', 'Alpha', '#2a9d8f', '#ffffff', NULL),
(2, 'Beta App', 'Beta', '#2a9d8f', '#ffffff', NULL),
(3, 'Gamma App', 'Gamma', '#2a9d8f', '#ffffff', NULL),
(4, 'Delta App', 'Delta', '#2a9d8f', '#ffffff', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `name` varchar(190) NOT NULL,
  `is_tenant_admin` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `tenant_id`, `name`, `is_tenant_admin`, `created_at`) VALUES
(1, 1, 'Admin', 1, '2026-03-28 23:35:10'),
(2, 1, 'Staff', 0, '2026-03-28 23:35:10'),
(3, 1, 'Manager', 0, '2026-03-28 23:35:10'),
(4, 1, 'Cashier', 0, '2026-03-28 23:35:10'),
(5, 2, 'Admin', 1, '2026-03-28 23:35:10'),
(6, 2, 'Staff', 0, '2026-03-28 23:35:10'),
(7, 2, 'Manager', 0, '2026-03-28 23:35:10'),
(8, 2, 'Cashier', 0, '2026-03-28 23:35:10'),
(9, 3, 'Admin', 1, '2026-03-28 23:35:10'),
(10, 3, 'Staff', 0, '2026-03-28 23:35:10'),
(11, 3, 'Manager', 0, '2026-03-28 23:35:10'),
(12, 3, 'Cashier', 0, '2026-03-28 23:35:10'),
(13, 4, 'Admin', 1, '2026-03-28 23:35:10'),
(14, 4, 'Staff', 0, '2026-03-28 23:35:10'),
(15, 4, 'Manager', 0, '2026-03-28 23:35:10'),
(16, 4, 'Cashier', 0, '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `role_page_permissions`
--

CREATE TABLE `role_page_permissions` (
  `id` int NOT NULL,
  `role_id` int NOT NULL,
  `page_key` varchar(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `role_page_permissions`
--

INSERT INTO `role_page_permissions` (`id`, `role_id`, `page_key`) VALUES
(2, 2, 'books'),
(3, 2, 'cart'),
(1, 2, 'dashboard'),
(4, 2, 'sales-history');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `sale_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `subtotal` decimal(12,2) NOT NULL,
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL,
  `promotion_code` varchar(190) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `tenant_id`, `customer_id`, `user_id`, `sale_date`, `subtotal`, `discount`, `total`, `promotion_code`) VALUES
(1, 1, 1, 1, '2026-03-28 23:35:10', 29.99, 0.00, 29.99, NULL),
(2, 1, 2, 2, '2026-03-28 23:35:10', 39.99, 0.00, 39.99, NULL),
(3, 1, 3, 1, '2026-03-28 23:35:10', 9.98, 0.00, 9.98, NULL),
(4, 1, 4, 2, '2026-03-28 23:35:10', 1.99, 0.00, 1.99, NULL),
(5, 1, 1, 1, '2026-03-29 00:56:49', 59.98, 0.00, 59.98, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int NOT NULL,
  `sale_id` int NOT NULL,
  `book_id` int NOT NULL,
  `quantity` int NOT NULL,
  `price_per_unit` decimal(12,2) NOT NULL,
  `discount_per_unit` decimal(12,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `book_id`, `quantity`, `price_per_unit`, `discount_per_unit`) VALUES
(1, 1, 1, 1, 29.99, 0.00),
(2, 2, 2, 1, 39.99, 0.00),
(3, 3, 3, 2, 4.99, 0.00),
(4, 4, 4, 1, 1.99, 0.00),
(5, 5, 1, 2, 29.99, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `subscription_payments`
--

CREATE TABLE `subscription_payments` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `plan_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `months_subscribed` int NOT NULL,
  `payment_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `payment_proof_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text,
  `processed_by_superadmin` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `subscription_payments`
--

INSERT INTO `subscription_payments` (`id`, `tenant_id`, `plan_id`, `amount`, `months_subscribed`, `payment_date`, `payment_proof_path`, `status`, `rejection_reason`, `processed_by_superadmin`) VALUES
(1, 1, 1, 499.00, 1, '2026-03-28 23:35:10', NULL, 'approved', NULL, NULL),
(2, 2, 2, 1998.00, 2, '2026-03-28 23:35:10', NULL, 'approved', NULL, NULL),
(3, 3, 3, 1499.00, 1, '2026-03-28 23:35:10', NULL, 'pending', NULL, NULL),
(4, 4, 4, 8997.00, 3, '2026-03-28 23:35:10', NULL, 'rejected', NULL, NULL),
(5, 1, 4, 14995.00, 5, '2026-03-29 00:36:42', 'C:\\phpserver\\www\\BookShopSaaS/uploads/shop1/payment_proofs/payment_69c8741aa3b8a.png', 'approved', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `subscription_plans`
--

CREATE TABLE `subscription_plans` (
  `id` int NOT NULL,
  `name` varchar(190) NOT NULL,
  `price_per_month` decimal(10,2) NOT NULL DEFAULT '499.00',
  `enable_file_uploads` tinyint(1) NOT NULL DEFAULT '0',
  `description` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `subscription_plans`
--

INSERT INTO `subscription_plans` (`id`, `name`, `price_per_month`, `enable_file_uploads`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Basic Plan', 499.00, 0, 'Basic plan for starters', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(2, 'Standard Plan', 999.00, 1, 'Standard plan with uploads', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(3, 'Premium Plan', 1499.00, 1, 'Premium plan with full access', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(4, 'Enterprise Plan', 2999.00, 1, 'Enterprise plan for big shops', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `superadmin_news`
--

CREATE TABLE `superadmin_news` (
  `id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `media_path` varchar(255) DEFAULT NULL,
  `media_type` enum('image','video_upload','youtube_embed','facebook_embed') DEFAULT NULL,
  `visibility` enum('all_users','tenant_admins_only') NOT NULL DEFAULT 'all_users',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `superadmin_news`
--

INSERT INTO `superadmin_news` (`id`, `title`, `content`, `media_path`, `media_type`, `visibility`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Welcome to SaaS', 'This is the new SaaS platform.', NULL, NULL, 'all_users', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(2, 'Update 1.1', 'We added new features.', NULL, NULL, 'all_users', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(3, 'Maintenance', 'Scheduled maintenance on Sunday.', NULL, NULL, 'tenant_admins_only', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(4, 'New Pricing', 'Check our new pricing plans.', NULL, NULL, 'all_users', 1, '2026-03-28 23:35:10', '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `superadmin_settings`
--

CREATE TABLE `superadmin_settings` (
  `setting_key` varchar(190) NOT NULL,
  `setting_value` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `superadmin_settings`
--

INSERT INTO `superadmin_settings` (`setting_key`, `setting_value`) VALUES
('contact_email', 'support@saas.com'),
('contact_phone', '1234567890'),
('default_currency_symbol', 'Rs'),
('default_subscription_price_per_month', '499'),
('facebook_url', ''),
('linkedin_url', ''),
('slogan', ''),
('system_name', 'Master SaaS'),
('twitter_url', '');

-- --------------------------------------------------------

--
-- Table structure for table `superadmin_users`
--

CREATE TABLE `superadmin_users` (
  `id` int NOT NULL,
  `username` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `superadmin_users`
--

INSERT INTO `superadmin_users` (`id`, `username`, `password_hash`, `created_at`) VALUES
(1, 'superadmin', '$2y$10$pUng68aGoKxwWgP4b0dQ.O2gkjhhkDadSBHLsR5s8wmFXbgYMk1.i', '2026-03-28 23:35:10'),
(2, 'superadmin2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-03-28 23:35:10'),
(3, 'superadmin3', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-03-28 23:35:10'),
(4, 'superadmin4', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `tenant_id`, `name`, `contact_person`, `phone`, `email`, `address`, `created_at`, `updated_at`) VALUES
(1, 1, 'Tech Books Dist', 'Tom', NULL, 'tech@dist.com', NULL, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(2, 1, 'Office Supplies Inc', 'Oliver', NULL, 'office@inc.com', NULL, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(3, 1, 'Global Publishers', 'Greg', NULL, 'global@pub.com', NULL, '2026-03-28 23:35:10', '2026-03-28 23:35:10'),
(4, 1, 'Local Pens Ltd', 'Larry', NULL, 'local@pens.com', NULL, '2026-03-28 23:35:10', '2026-03-28 23:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `id` int NOT NULL,
  `slug` varchar(190) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `address` text,
  `logo_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('pending','active','suspended','banned') NOT NULL DEFAULT 'pending',
  `registration_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `subscription_end_date` date DEFAULT NULL,
  `plan_id` int DEFAULT NULL,
  `allow_uploads` tinyint(1) NOT NULL DEFAULT '0',
  `invitation_code` varchar(190) DEFAULT NULL,
  `created_by_superadmin` int DEFAULT NULL,
  `referred_by` varchar(190) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `contact_phone`, `address`, `logo_path`, `is_active`, `status`, `registration_date`, `subscription_end_date`, `plan_id`, `allow_uploads`, `invitation_code`, `created_by_superadmin`, `referred_by`) VALUES
(1, 'shop1', 'Alpha Bookshop', 'admin@shop1.com', NULL, NULL, NULL, 1, 'active', '2026-03-28 23:35:10', '2027-05-31', 4, 1, 'DE73264B', NULL, NULL),
(2, 'shop2', 'Beta Store', 'admin@shop2.com', NULL, NULL, NULL, 1, 'active', '2026-03-28 23:35:10', '2026-12-31', 2, 0, NULL, NULL, NULL),
(3, 'shop3', 'Gamma Retail', 'admin@shop3.com', NULL, NULL, NULL, 1, 'active', '2026-03-28 23:35:10', '2026-12-31', 3, 0, NULL, NULL, NULL),
(4, 'shop4', 'Delta Mart', 'admin@shop4.com', NULL, NULL, NULL, 0, 'pending', '2026-03-28 23:35:10', '2026-12-31', 4, 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tenant_settings`
--

CREATE TABLE `tenant_settings` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `setting_key` varchar(190) NOT NULL,
  `setting_value` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tenant_settings`
--

INSERT INTO `tenant_settings` (`id`, `tenant_id`, `setting_key`, `setting_value`) VALUES
(1, 1, 'system_name', 'Alpha Bookshop');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `username` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` int NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `tenant_id`, `username`, `password_hash`, `role_id`, `is_active`, `created_at`) VALUES
(1, 1, 'admin1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 1, '2026-03-28 23:35:10'),
(2, 1, 'staff1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 1, '2026-03-28 23:35:10'),
(3, 1, 'manager1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 3, 1, '2026-03-28 23:35:10'),
(4, 1, 'cashier1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 4, 1, '2026-03-28 23:35:10');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `isbn` (`isbn`),
  ADD KEY `idx_books_barcode` (`barcode`),
  ADD KEY `idx_books_tenant_name` (`tenant_id`,`name`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_id` (`tenant_id`,`email`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `online_orders`
--
ALTER TABLE `online_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `sale_id` (`sale_id`);

--
-- Indexes for table `online_order_items`
--
ALTER TABLE `online_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `book_id` (`book_id`);

--
-- Indexes for table `plan_permissions`
--
ALTER TABLE `plan_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plan_id` (`plan_id`,`page_key`);

--
-- Indexes for table `po_items`
--
ALTER TABLE `po_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `po_id` (`po_id`),
  ADD KEY `book_id` (`book_id`);

--
-- Indexes for table `promotions`
--
ALTER TABLE `promotions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_id` (`tenant_id`,`code`);

--
-- Indexes for table `public_news`
--
ALTER TABLE `public_news`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indexes for table `public_sale_links`
--
ALTER TABLE `public_sale_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD UNIQUE KEY `tenant_id` (`tenant_id`,`token`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `pwa_settings`
--
ALTER TABLE `pwa_settings`
  ADD PRIMARY KEY (`tenant_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_id` (`tenant_id`,`name`);

--
-- Indexes for table `role_page_permissions`
--
ALTER TABLE `role_page_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_id` (`role_id`,`page_key`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `book_id` (`book_id`);

--
-- Indexes for table `subscription_payments`
--
ALTER TABLE `subscription_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `processed_by_superadmin` (`processed_by_superadmin`);

--
-- Indexes for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `superadmin_news`
--
ALTER TABLE `superadmin_news`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `superadmin_settings`
--
ALTER TABLE `superadmin_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `superadmin_users`
--
ALTER TABLE `superadmin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_id` (`tenant_id`,`email`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `invitation_code` (`invitation_code`),
  ADD UNIQUE KEY `invitation_code_2` (`invitation_code`),
  ADD KEY `created_by_superadmin` (`created_by_superadmin`);

--
-- Indexes for table `tenant_settings`
--
ALTER TABLE `tenant_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_id` (`tenant_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_id` (`tenant_id`,`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `online_orders`
--
ALTER TABLE `online_orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `online_order_items`
--
ALTER TABLE `online_order_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `plan_permissions`
--
ALTER TABLE `plan_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `po_items`
--
ALTER TABLE `po_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `promotions`
--
ALTER TABLE `promotions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `public_news`
--
ALTER TABLE `public_news`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `public_sale_links`
--
ALTER TABLE `public_sale_links`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `role_page_permissions`
--
ALTER TABLE `role_page_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `subscription_payments`
--
ALTER TABLE `subscription_payments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `superadmin_news`
--
ALTER TABLE `superadmin_news`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `superadmin_users`
--
ALTER TABLE `superadmin_users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tenant_settings`
--
ALTER TABLE `tenant_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `books`
--
ALTER TABLE `books`
  ADD CONSTRAINT `books_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `online_orders`
--
ALTER TABLE `online_orders`
  ADD CONSTRAINT `online_orders_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `online_orders_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `online_orders_ibfk_3` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `online_order_items`
--
ALTER TABLE `online_order_items`
  ADD CONSTRAINT `online_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `online_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `online_order_items_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `plan_permissions`
--
ALTER TABLE `plan_permissions`
  ADD CONSTRAINT `plan_permissions_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `po_items`
--
ALTER TABLE `po_items`
  ADD CONSTRAINT `po_items_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `po_items_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `promotions`
--
ALTER TABLE `promotions`
  ADD CONSTRAINT `promotions_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `public_news`
--
ALTER TABLE `public_news`
  ADD CONSTRAINT `public_news_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `public_sale_links`
--
ALTER TABLE `public_sale_links`
  ADD CONSTRAINT `public_sale_links_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `public_sale_links_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `purchase_orders_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `pwa_settings`
--
ALTER TABLE `pwa_settings`
  ADD CONSTRAINT `pwa_settings_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `roles_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_page_permissions`
--
ALTER TABLE `role_page_permissions`
  ADD CONSTRAINT `role_page_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `subscription_payments`
--
ALTER TABLE `subscription_payments`
  ADD CONSTRAINT `subscription_payments_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscription_payments_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscription_payments_ibfk_3` FOREIGN KEY (`processed_by_superadmin`) REFERENCES `superadmin_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `suppliers_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tenants`
--
ALTER TABLE `tenants`
  ADD CONSTRAINT `tenants_ibfk_1` FOREIGN KEY (`created_by_superadmin`) REFERENCES `superadmin_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `tenant_settings`
--
ALTER TABLE `tenant_settings`
  ADD CONSTRAINT `tenant_settings_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

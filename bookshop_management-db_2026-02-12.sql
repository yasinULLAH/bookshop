-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Feb 12, 2026 at 04:50 PM
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
-- Database: `bookshop_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `id` int NOT NULL,
  `product_type` enum('book','general') NOT NULL DEFAULT 'book',
  `name` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `isbn` varchar(20) NOT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `year` int DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int NOT NULL DEFAULT '0',
  `description` text,
  `cover_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`id`, `product_type`, `name`, `author`, `category`, `isbn`, `publisher`, `year`, `price`, `stock`, `description`, `cover_image`, `created_at`, `updated_at`) VALUES
(1, 'book', 'The Alchemist', 'Paulo Coelho', 'Fiction', '978-0061122415', 'HarperOne', 1988, 850.00, 17, '0', 'uploads/covers/cover_698df6f0e3f22.webp', '2025-08-30 09:37:13', '2026-02-12 15:51:12'),
(2, 'book', 'Sapiens: A Brief History of Humankind', 'Yuval Noah Harari', 'History', '978-0062316097', 'Harper Perennial', 2014, 1200.00, 7, '0', 'uploads/covers/cover_698df6e56248f.jpg', '2025-08-30 09:37:13', '2026-02-12 15:51:01'),
(3, 'book', 'The Art of Thinking Clearly', 'Rolf Dobelli', 'Self-Help', '978-0062218391', 'HarperCollins', 2011, 700.00, 3, '99', 'uploads/covers/cover_698df6fca3392.webp', '2025-08-30 09:37:13', '2026-02-12 15:51:24'),
(4, 'book', '1984', 'George Orwell', 'Dystopian', '978-0451524935', 'Signet Classic', 1949, 600.00, 20, '0', 'uploads/covers/cover_698df6b8e97d8.webp', '2025-08-30 09:37:13', '2026-02-12 15:50:16'),
(5, 'book', 'Rich Dad Poor Dad', 'Robert Kiyosaki', 'Finance', '978-0446677455', 'Plata Publishing', 1997, 950.00, 4, '0', 'uploads/covers/cover_698df6d47725a.webp', '2025-08-30 09:37:13', '2026-02-12 15:50:44'),
(6, 'book', 'To Kill a Mockingbird', 'Harper Lee', 'Classic', '978-0446310789', 'Grand Central Publishing', 1960, 750.00, 24, '0', 'uploads/covers/cover_698df7196dec8.jpg', '2025-08-30 09:37:13', '2026-02-12 15:51:53'),
(7, 'book', 'The Great Gatsby', 'F. Scott Fitzgerald', 'Classic', '9780743273565', '', 1992, 15.99, 100, '0', 'uploads/covers/cover_698df70f3ed57.jpg', '2026-01-29 16:39:07', '2026-02-12 15:51:43');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `address` text,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `name`, `phone`, `email`, `password_hash`, `address`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Ali Khan', '03001234567', 'ali.khan@example.com', NULL, 'Street 5, Sector G-8, Islamabad', 1, '2025-08-30 09:37:13', '2025-08-30 09:37:13'),
(2, 'Sara Ahmed', '03337654321', 'sara.ahmed@example.com', NULL, 'House 12, Gulberg III, Lahore', 1, '2025-08-30 09:37:13', '2025-08-30 09:37:13'),
(3, 'Usman Tariq', '03219876543', 'usman.tariq@example.com', NULL, 'Block A, DHA Phase V, Karachi', 1, '2025-08-30 09:37:13', '2025-08-30 09:37:13'),
(4, 'Fatima Zohra', '03451122334', 'fatima.z@example.com', NULL, 'Apartment 7, F-10 Markaz, Islamabad', 0, '2025-08-30 09:37:13', '2025-08-30 09:37:13');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` text,
  `amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `user_id`, `expense_date`, `category`, `description`, `amount`, `created_at`, `updated_at`) VALUES
(1, 1, '2025-08-27', 'Utilities', 'Electricity bill for the month', 8500.00, '2025-08-30 09:37:13', '2025-08-30 09:37:13'),
(2, 1, '2025-08-15', 'Rent', 'Monthly shop rent', 50000.00, '2025-08-30 09:37:13', '2025-08-30 09:37:13'),
(3, 1, '2025-08-10', 'Supplies', 'Office stationery and packing material', 3200.00, '2025-08-30 09:37:13', '2025-08-30 09:37:13'),
(4, 1, '2025-08-30', 'Marketing', 'Social media ad campaign', 15000.00, '2025-08-30 09:37:13', '2025-08-30 09:37:13');

-- --------------------------------------------------------

--
-- Table structure for table `online_orders`
--

CREATE TABLE `online_orders` (
  `id` int NOT NULL,
  `customer_id` int NOT NULL,
  `order_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `subtotal` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL,
  `promotion_code` varchar(50) DEFAULT NULL,
  `sale_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `online_order_items`
--

CREATE TABLE `online_order_items` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `book_id` int NOT NULL,
  `quantity` int NOT NULL,
  `price_per_unit` decimal(10,2) NOT NULL,
  `discount_per_unit` decimal(10,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `po_items`
--

CREATE TABLE `po_items` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `book_id` int NOT NULL,
  `quantity` int NOT NULL,
  `cost_per_unit` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `po_items`
--

INSERT INTO `po_items` (`id`, `po_id`, `book_id`, `quantity`, `cost_per_unit`) VALUES
(1, 1, 6, 10, 525.00),
(2, 1, 1, 5, 595.00),
(3, 2, 4, 15, 360.00);

-- --------------------------------------------------------

--
-- Table structure for table `promotions`
--

CREATE TABLE `promotions` (
  `id` int NOT NULL,
  `code` varchar(50) NOT NULL,
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

INSERT INTO `promotions` (`id`, `code`, `type`, `value`, `applies_to`, `applies_to_value`, `start_date`, `end_date`, `created_at`, `updated_at`) VALUES
(1, 'SUMMER10', 'percentage', 10.00, 'all', NULL, '2025-08-20', '2025-09-19', '2025-08-30 09:37:13', '2025-08-30 09:37:13'),
(2, 'SAPIENS50', 'fixed', 50.00, 'specific-book', '2', '2025-08-25', NULL, '2025-08-30 09:37:13', '2025-08-30 09:37:13');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int NOT NULL,
  `supplier_id` int NOT NULL,
  `user_id` int NOT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `status` enum('pending','ordered','received','cancelled') NOT NULL DEFAULT 'pending',
  `total_cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`id`, `supplier_id`, `user_id`, `order_date`, `expected_date`, `status`, `total_cost`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2025-08-23', '2025-09-06', 'received', 9200.00, '2025-08-30 09:37:13', '2025-08-30 10:05:29'),
(2, 2, 1, '2025-07-31', '2025-08-10', 'received', 5400.00, '2025-08-30 09:37:13', '2025-08-30 09:37:13');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `user_id` int NOT NULL,
  `sale_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `subtotal` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL,
  `promotion_code` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `customer_id`, `user_id`, `sale_date`, `subtotal`, `discount`, `total`, `promotion_code`) VALUES
(1, 1, 1, '2025-08-28 09:37:13', 2050.00, 0.00, 2050.00, NULL),
(2, 2, 2, '2025-08-20 09:37:13', 1900.00, 0.00, 1900.00, NULL),
(3, NULL, 1, '2025-08-15 09:37:13', 600.00, 0.00, 600.00, NULL),
(9, NULL, 1, '2025-08-30 09:48:58', 750.00, 0.00, 750.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int NOT NULL,
  `sale_id` int NOT NULL,
  `book_id` int NOT NULL,
  `quantity` int NOT NULL,
  `price_per_unit` decimal(10,2) NOT NULL,
  `discount_per_unit` decimal(10,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `book_id`, `quantity`, `price_per_unit`, `discount_per_unit`) VALUES
(1, 1, 1, 1, 850.00, 0.00),
(2, 1, 2, 1, 1200.00, 0.00),
(3, 2, 5, 2, 950.00, 0.00),
(4, 3, 4, 1, 600.00, 0.00),
(5, 9, 6, 1, 750.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int NOT NULL,
  `setting_key` varchar(255) NOT NULL,
  `setting_value` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`) VALUES
(1, 'system_name', 'Book Store'),
(2, 'mission', 'To provide a diverse range of products and books to our community, fostering knowledge and meeting everyday needs with excellence.'),
(3, 'vision', 'To be the leading general store and bookshop, known for quality, variety, and exceptional customer service.'),
(4, 'address', '123 Main Street, Cityville, Pakistan'),
(5, 'phone', '+923001234567'),
(6, 'whatsapp_number', '+923001234567'),
(7, 'email', 'info@generalbookshop.pk'),
(8, 'google_map_embed_url', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3401.769975306665!2d74.34757311508213!3d31.528414481368165!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x39190538a8e31847%3A0xc34a1b02b9e6e408!2sLahore%2C%20Punjab%2C%20Pakistan!5e0!3m2!1sen!2sus!4v1678888888888!5m2!1sen!2sus'),
(9, 'facebook_url', 'https://www.facebook.com/generalbookshop'),
(10, 'instagram_url', 'https://www.instagram.com/generalbookshop'),
(11, 'currency_symbol', 'PKR ');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `contact_person`, `phone`, `email`, `address`, `created_at`, `updated_at`) VALUES
(1, 'ABC Publishers', 'Zain Ali', '021-34567890', 'info@abcpubs.com', 'D-34, Main Boulevard, Karachi', '2025-08-30 09:37:13', '2025-08-30 09:37:13'),
(2, 'Global Books Distributors', 'Maria Khan', '042-12345678', 'sales@globalbooks.pk', 'Model Town, Lahore', '2025-08-30 09:37:13', '2025-08-30 09:37:13'),
(3, 'Book Hub Pvt Ltd', 'Hassan Iqbal', '051-5432109', 'hassan@bookhub.pk', 'Blue Area, Islamabad', '2025-08-30 09:37:13', '2025-08-30 09:37:13');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `created_at`) VALUES
(1, 'admin', '$2y$10$GQGukXNletAY.xjqLRW9nes0sLx8A26Uzy50gUWxiQHO9zL9f1hL.', 'admin', '2025-08-30 09:37:13'),
(2, 'staff', '$2y$10$GQGukXNletAY.xjqLRW9nes0sLx8A26Uzy50gUWxiQHO9zL9f1hL.', 'staff', '2025-08-30 09:37:13');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `isbn` (`isbn`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `online_orders`
--
ALTER TABLE `online_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `promotion_code` (`promotion_code`),
  ADD KEY `sale_id` (`sale_id`);

--
-- Indexes for table `online_order_items`
--
ALTER TABLE `online_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `book_id` (`book_id`);

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
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `promotion_code` (`promotion_code`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `book_id` (`book_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

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
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `online_order_items`
--
ALTER TABLE `online_order_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `po_items`
--
ALTER TABLE `po_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `promotions`
--
ALTER TABLE `promotions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=771;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `online_orders`
--
ALTER TABLE `online_orders`
  ADD CONSTRAINT `online_orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `online_orders_ibfk_2` FOREIGN KEY (`promotion_code`) REFERENCES `promotions` (`code`) ON DELETE SET NULL,
  ADD CONSTRAINT `online_orders_ibfk_3` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `online_order_items`
--
ALTER TABLE `online_order_items`
  ADD CONSTRAINT `online_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `online_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `online_order_items_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `po_items`
--
ALTER TABLE `po_items`
  ADD CONSTRAINT `po_items_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `po_items_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `sales_ibfk_3` FOREIGN KEY (`promotion_code`) REFERENCES `promotions` (`code`) ON DELETE SET NULL;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE RESTRICT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

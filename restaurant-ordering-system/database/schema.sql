-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 06, 2026 at 12:38 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */
;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */
;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */
;
/*!40101 SET NAMES utf8mb4 */
;
--
-- Database: `restaurant_ordering_system`
--
-- --------------------------------------------------------
--
-- Table structure for table `categories`
--
CREATE TABLE `categories` (
    `category_id` int(11) NOT NULL,
    `category_name` varchar(100) NOT NULL,
    `description` text DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- --------------------------------------------------------
--
-- Table structure for table `customers`
--
CREATE TABLE `customers` (
    `customer_id` int(11) NOT NULL,
    `full_name` varchar(100) NOT NULL,
    `phone` varchar(20) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- --------------------------------------------------------
--
-- Table structure for table `delivery_addresses`
--
CREATE TABLE `delivery_addresses` (
    `address_id` int(11) NOT NULL,
    `customer_id` int(11) NOT NULL,
    `city` varchar(100) NOT NULL DEFAULT 'Hawassa',
    `sub_city` varchar(100) DEFAULT NULL,
    `specific_address` varchar(255) NOT NULL,
    `delivery_instructions` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `instructions` text DEFAULT NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- --------------------------------------------------------
--
-- Table structure for table `foods`
--
CREATE TABLE `foods` (
    `food_id` int(11) NOT NULL,
    `category_id` int(11) NOT NULL,
    `food_name` varchar(150) NOT NULL,
    `description` text DEFAULT NULL,
    `price` decimal(10, 2) NOT NULL,
    `image` varchar(255) DEFAULT NULL,
    `is_available` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
);
-- --------------------------------------------------------
--
-- Table structure for table `login_attempts`
--
CREATE TABLE `login_attempts` (
    `attempt_key` char(64) NOT NULL,
    `failed_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `window_started_at` datetime NOT NULL,
    `blocked_until` datetime DEFAULT NULL,
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- --------------------------------------------------------
--
-- Table structure for table `orders`
--
CREATE TABLE `orders` (
    `order_id` int(11) NOT NULL,
    `customer_id` int(11) NOT NULL,
    `delivery_address_id` int(11) DEFAULT NULL,
    `order_number` varchar(30) NOT NULL,
    `order_type` varchar(20) NOT NULL,
    `subtotal` decimal(10, 2) NOT NULL,
    `delivery_fee` decimal(10, 2) NOT NULL DEFAULT 0.00,
    `total_amount` decimal(10, 2) NOT NULL,
    `order_status` varchar(30) NOT NULL DEFAULT 'pending',
    `customer_note` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
);
-- --------------------------------------------------------
--
-- Table structure for table `order_items`
--
CREATE TABLE `order_items` (
    `order_item_id` int(11) NOT NULL,
    `order_id` int(11) NOT NULL,
    `food_id` int(11) NOT NULL,
    `food_name` varchar(150) NOT NULL,
    `quantity` int(11) NOT NULL,
    `unit_price` decimal(10, 2) NOT NULL,
    `line_total` decimal(10, 2) NOT NULL
);
-- --------------------------------------------------------
--
-- Table structure for table `order_status_history`
--
CREATE TABLE `order_status_history` (
    `history_id` int(11) NOT NULL,
    `order_id` int(11) NOT NULL,
    `user_id` int(11) DEFAULT NULL,
    `old_status` varchar(30) DEFAULT NULL,
    `new_status` varchar(30) NOT NULL,
    `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- --------------------------------------------------------
--
-- Table structure for table `payments`
--
CREATE TABLE `payments` (
    `payment_id` int(11) NOT NULL,
    `order_id` int(11) NOT NULL,
    `payment_method_id` int(11) NOT NULL,
    `amount` decimal(10, 2) NOT NULL,
    `transaction_reference` varchar(100) DEFAULT NULL,
    `payment_status` varchar(30) NOT NULL DEFAULT 'pending',
    `verified_by` int(11) DEFAULT NULL,
    `verification_note` text DEFAULT NULL,
    `paid_at` datetime DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
);
-- --------------------------------------------------------
--
-- Table structure for table `payment_methods`
--
CREATE TABLE `payment_methods` (
    `payment_method_id` int(11) NOT NULL,
    `method_name` varchar(50) NOT NULL,
    `account_name` varchar(100) DEFAULT NULL,
    `account_number` varchar(100) DEFAULT NULL,
    `payment_instructions` text DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- --------------------------------------------------------
--
-- Table structure for table `restaurant_settings`
--
CREATE TABLE `restaurant_settings` (
    `setting_id` int(11) NOT NULL,
    `setting_key` varchar(100) NOT NULL,
    `setting_value` text DEFAULT NULL,
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- --------------------------------------------------------
--
-- Table structure for table `tracking_attempts`
--
CREATE TABLE `tracking_attempts` (
    `attempt_key` char(64) NOT NULL,
    `failed_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `window_started_at` datetime NOT NULL,
    `blocked_until` datetime DEFAULT NULL,
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- --------------------------------------------------------
--
-- Table structure for table `users`
--
CREATE TABLE `users` (
    `user_id` int(11) NOT NULL,
    `username` varchar(50) NOT NULL,
    `password` varchar(255) NOT NULL,
    `full_name` varchar(100) NOT NULL,
    `role` varchar(20) NOT NULL DEFAULT 'admin',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);
--
-- Indexes for dumped tables
--
--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
ADD PRIMARY KEY (`category_id`),
    ADD UNIQUE KEY `category_name` (`category_name`);
--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
ADD PRIMARY KEY (`customer_id`),
    ADD KEY `idx_customer_phone` (`phone`);
--
-- Indexes for table `delivery_addresses`
--
ALTER TABLE `delivery_addresses`
ADD PRIMARY KEY (`address_id`),
    ADD KEY `idx_address_customer` (`customer_id`);
--
-- Indexes for table `foods`
--
ALTER TABLE `foods`
ADD PRIMARY KEY (`food_id`),
    ADD KEY `idx_food_category` (`category_id`),
    ADD KEY `idx_food_availability` (`is_available`),
    ADD KEY `idx_food_name` (`food_name`);
--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
ADD PRIMARY KEY (`attempt_key`);
--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
ADD PRIMARY KEY (`order_id`),
    ADD UNIQUE KEY `order_number` (`order_number`),
    ADD KEY `fk_order_address` (`delivery_address_id`),
    ADD KEY `idx_order_customer` (`customer_id`),
    ADD KEY `idx_order_status` (`order_status`),
    ADD KEY `idx_order_date` (`created_at`);
--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
ADD PRIMARY KEY (`order_item_id`),
    ADD KEY `idx_item_order` (`order_id`),
    ADD KEY `idx_item_food` (`food_id`);
--
-- Indexes for table `order_status_history`
--
ALTER TABLE `order_status_history`
ADD PRIMARY KEY (`history_id`),
    ADD KEY `fk_history_user` (`user_id`),
    ADD KEY `idx_history_order` (`order_id`),
    ADD KEY `idx_history_date` (`changed_at`);
--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
ADD PRIMARY KEY (`payment_id`),
    ADD UNIQUE KEY `unique_payment_reference` (`payment_method_id`, `transaction_reference`),
    ADD KEY `fk_payment_admin` (`verified_by`),
    ADD KEY `idx_payment_order` (`order_id`),
    ADD KEY `idx_payment_status` (`payment_status`),
    ADD KEY `idx_payment_reference` (`transaction_reference`);
--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
ADD PRIMARY KEY (`payment_method_id`),
    ADD UNIQUE KEY `method_name` (`method_name`);
--
-- Indexes for table `restaurant_settings`
--
ALTER TABLE `restaurant_settings`
ADD PRIMARY KEY (`setting_id`),
    ADD UNIQUE KEY `setting_key` (`setting_key`);
--
-- Indexes for table `tracking_attempts`
--
ALTER TABLE `tracking_attempts`
ADD PRIMARY KEY (`attempt_key`);
--
-- Indexes for table `users`
--
ALTER TABLE `users`
ADD PRIMARY KEY (`user_id`),
    ADD UNIQUE KEY `username` (`username`);
--
-- AUTO_INCREMENT for dumped tables
--
--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `delivery_addresses`
--
ALTER TABLE `delivery_addresses`
MODIFY `address_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `foods`
--
ALTER TABLE `foods`
MODIFY `food_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
MODIFY `order_item_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `order_status_history`
--
ALTER TABLE `order_status_history`
MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
MODIFY `payment_method_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `restaurant_settings`
--
ALTER TABLE `restaurant_settings`
MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- Constraints for dumped tables
--
--
-- Constraints for table `delivery_addresses`
--
ALTER TABLE `delivery_addresses`
ADD CONSTRAINT `fk_address_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON UPDATE CASCADE;
--
-- Constraints for table `foods`
--
ALTER TABLE `foods`
ADD CONSTRAINT `fk_food_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON UPDATE CASCADE;
--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
ADD CONSTRAINT `fk_order_address` FOREIGN KEY (`delivery_address_id`) REFERENCES `delivery_addresses` (`address_id`) ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON UPDATE CASCADE;
--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
ADD CONSTRAINT `fk_item_food` FOREIGN KEY (`food_id`) REFERENCES `foods` (`food_id`) ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_item_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON UPDATE CASCADE;
--
-- Constraints for table `order_status_history`
--
ALTER TABLE `order_status_history`
ADD CONSTRAINT `fk_history_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE
SET NULL ON UPDATE CASCADE;
--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
ADD CONSTRAINT `fk_payment_admin` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE
SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_payment_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`payment_method_id`) ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_payment_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON UPDATE CASCADE;
COMMIT;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */
;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */
;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */
;

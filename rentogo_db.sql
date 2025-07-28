-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 28, 2025 at 07:29 PM
-- Server version: 8.0.30
-- PHP Version: 8.1.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `rentogo_db`
--

DELIMITER $$
--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `calculate_trip_fare` (`car_type` VARCHAR(20), `distance_km` DECIMAL(8,2), `duration_minutes` INT, `is_peak_hour` BOOLEAN) RETURNS DECIMAL(8,2) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE base_fare_val DECIMAL(6,2) DEFAULT 5.00;
    DECLARE price_per_km_val DECIMAL(6,2) DEFAULT 1.50;
    DECLARE price_per_minute_val DECIMAL(6,2) DEFAULT 0.30;
    DECLARE peak_multiplier_val DECIMAL(3,2) DEFAULT 1.0;
    DECLARE minimum_fare_val DECIMAL(6,2) DEFAULT 8.00;
    DECLARE total_fare DECIMAL(8,2);
    
    -- Get pricing for car type
    SELECT base_fare, price_per_km, price_per_minute, 
           CASE WHEN is_peak_hour THEN peak_multiplier ELSE 1.0 END,
           minimum_fare
    INTO base_fare_val, price_per_km_val, price_per_minute_val, peak_multiplier_val, minimum_fare_val
    FROM pricing_settings 
    WHERE pricing_settings.car_type = car_type;
    
    -- Calculate total fare
    SET total_fare = (base_fare_val + (distance_km * price_per_km_val) + (duration_minutes * price_per_minute_val)) * peak_multiplier_val;
    
    -- Apply minimum fare
    IF total_fare < minimum_fare_val THEN
        SET total_fare = minimum_fare_val;
    END IF;
    
    RETURN ROUND(total_fare, 2);
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `adminid` int NOT NULL,
  `userid` int NOT NULL,
  `admin_level` enum('super_admin','moderator') DEFAULT 'moderator',
  `department` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`adminid`, `userid`, `admin_level`, `department`) VALUES
(1, 1, 'super_admin', 'IT Department');

-- --------------------------------------------------------

--
-- Table structure for table `booking`
--

CREATE TABLE `booking` (
  `bookingid` int NOT NULL,
  `userid` int NOT NULL,
  `driverid` int NOT NULL,
  `pax` int NOT NULL,
  `bookingdate` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `pickupdate` datetime NOT NULL,
  `dropoffdate` datetime NOT NULL,
  `pickuplocation` varchar(255) NOT NULL,
  `dropofflocation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `totalcost` decimal(10,2) NOT NULL,
  `bookingstatus` enum('pending','confirmed','completed','cancelled') DEFAULT 'pending',
  `paymentstatus` enum('unpaid','paid','refunded') DEFAULT 'unpaid',
  `notes` text,
  `duration_hours` int NOT NULL,
  `distance_km` decimal(8,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `booking`
--

INSERT INTO `booking` (`bookingid`, `userid`, `driverid`, `pax`, `bookingdate`, `pickupdate`, `dropoffdate`, `pickuplocation`, `dropofflocation`, `totalcost`, `bookingstatus`, `paymentstatus`, `notes`, `duration_hours`, `distance_km`) VALUES
(35, 4, 1, 2, '2025-07-22 20:34:21', '2025-07-23 02:29:00', '0000-00-00 00:00:00', 'UiTM Puncak Perdana gate', 'Setia City Mall', '16.70', 'cancelled', 'unpaid', 'masuk kolej kalau boleh', 1, '7.80'),
(36, 4, 1, 2, '2025-07-22 20:36:12', '2025-07-23 02:29:00', '0000-00-00 00:00:00', 'UiTM Puncak Perdana gate', 'Setia City Mall', '16.70', 'cancelled', 'unpaid', 'masuk kolej kalau boleh', 1, '7.80'),
(42, 4, 1, 2, '2025-07-22 20:50:22', '2025-07-23 02:29:00', '0000-00-00 00:00:00', 'UiTM Puncak Perdana gate', 'Setia City Mall', '16.70', 'completed', 'unpaid', 'masuk kolej kalau boleh', 1, '7.80'),
(43, 3, 2, 4, '2025-07-23 03:17:02', '2025-07-23 11:16:00', '0000-00-00 00:00:00', 'UiTM Puncak Perdana gate', 'Alam Budiman', '8.53', 'completed', 'unpaid', '', 1, '3.10'),
(44, 4, 3, 3, '2025-07-23 03:28:18', '2025-07-23 11:27:00', '0000-00-00 00:00:00', 'UiTM Puncak Perdana gate', 'Mixue, Setia Taipan', '14.45', 'completed', 'unpaid', '', 1, '6.30'),
(47, 10, 3, 2, '2025-07-23 07:28:56', '2025-07-23 15:30:00', '0000-00-00 00:00:00', 'Kolej Jasmine', 'Aeon Shah Alam', '23.90', 'completed', 'unpaid', 'no smoking driver', 1, '12.60'),
(48, 11, 3, 2, '2025-07-23 09:17:28', '2025-07-23 17:16:00', '0000-00-00 00:00:00', 'UiTM Puncak Perdana gate', 'Setia City Mall', '15.50', 'completed', 'unpaid', '', 1, '7.00');

-- --------------------------------------------------------

--
-- Table structure for table `car_types`
--

CREATE TABLE `car_types` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `current_pricing`
-- (See below for the actual view)
--
CREATE TABLE `current_pricing` (
`car_type` enum('Sedan','Hatchback','SUV','MPV','Pickup')
,`base_fare_display` varchar(45)
,`price_per_km_display` varchar(45)
,`price_per_minute_display` varchar(45)
,`minimum_fare_display` varchar(45)
,`base_fare` decimal(6,2)
,`price_per_km` decimal(6,2)
,`price_per_minute` decimal(6,2)
,`minimum_fare` decimal(6,2)
,`peak_multiplier` decimal(3,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `driver`
--

CREATE TABLE `driver` (
  `driverid` int NOT NULL,
  `userid` int NOT NULL,
  `licensenumber` varchar(20) NOT NULL,
  `carmodel` varchar(100) NOT NULL,
  `plate` varchar(10) NOT NULL,
  `capacity` int NOT NULL,
  `status` enum('available','not available','maintenance') DEFAULT 'available',
  `rating` decimal(2,1) DEFAULT '0.0',
  `datehired` date NOT NULL,
  `price_per_hour` decimal(8,2) NOT NULL,
  `car_type` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `driver`
--

INSERT INTO `driver` (`driverid`, `userid`, `licensenumber`, `carmodel`, `plate`, `capacity`, `status`, `rating`, `datehired`, `price_per_hour`, `car_type`) VALUES
(1, 2, 'D123456789', 'Toyota Vios', 'WXY1234', 4, 'available', '0.0', '2024-01-15', '15.00', 'Sedan'),
(2, 6, 'DA234567', 'Perodua Myvi', 'BMQ1598', 4, 'not available', '0.0', '2025-07-23', '0.00', 'Hatchback'),
(3, 7, 'D1234567', 'Toyota Vios 2020', 'BPA 9876', 4, 'available', '0.0', '2025-07-23', '0.00', 'Sedan'),
(4, 9, 'DA24568', 'Toyota Hilux', 'JJM 69', 4, 'maintenance', '0.0', '2025-07-23', '0.00', 'Pickup'),
(6, 16, 'D09876543', 'Perodua Myvi', 'UITM2024', 4, 'available', '0.0', '2025-07-26', '0.00', 'Hatchback');

-- --------------------------------------------------------

--
-- Table structure for table `pricing_settings`
--

CREATE TABLE `pricing_settings` (
  `id` int NOT NULL,
  `car_type` enum('Sedan','Hatchback','SUV','MPV','Pickup') NOT NULL,
  `base_fare` decimal(6,2) NOT NULL DEFAULT '5.00' COMMENT 'Starting fare for any trip',
  `price_per_km` decimal(6,2) NOT NULL DEFAULT '1.50' COMMENT 'Rate per kilometer',
  `price_per_minute` decimal(6,2) DEFAULT '0.30' COMMENT 'Rate per minute for waiting time',
  `peak_multiplier` decimal(3,2) DEFAULT '1.20' COMMENT 'Peak hours multiplier',
  `minimum_fare` decimal(6,2) DEFAULT '8.00' COMMENT 'Minimum total fare',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `pricing_settings`
--

INSERT INTO `pricing_settings` (`id`, `car_type`, `base_fare`, `price_per_km`, `price_per_minute`, `peak_multiplier`, `minimum_fare`, `created_at`, `updated_at`) VALUES
(166, 'Sedan', '4.00', '1.50', '0.30', '1.20', '8.00', '2025-07-28 19:18:04', '2025-07-28 19:18:04'),
(167, 'Hatchback', '4.50', '1.30', '0.30', '1.20', '7.00', '2025-07-28 19:18:04', '2025-07-28 19:18:04'),
(168, 'SUV', '7.00', '2.00', '0.30', '1.20', '10.00', '2025-07-28 19:18:04', '2025-07-28 19:18:04'),
(169, 'MPV', '6.50', '1.80', '0.30', '1.20', '9.00', '2025-07-28 19:18:04', '2025-07-28 19:18:04'),
(170, 'Pickup', '6.00', '1.70', '0.30', '1.20', '8.50', '2025-07-28 19:18:04', '2025-07-28 19:18:04'),
(171, '', '8.00', '2.20', '0.30', '1.20', '12.00', '2025-07-28 19:18:04', '2025-07-28 19:24:26');

-- --------------------------------------------------------

--
-- Table structure for table `rating`
--

CREATE TABLE `rating` (
  `ratingid` int NOT NULL,
  `bookingid` int NOT NULL,
  `userid` int NOT NULL,
  `driverid` int NOT NULL,
  `rating` int DEFAULT NULL,
  `review` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `report`
--

CREATE TABLE `report` (
  `reportid` int NOT NULL,
  `bookingid` int NOT NULL,
  `issuedate` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `report_type` enum('booking_summary','payment_summary','driver_performance') DEFAULT 'booking_summary',
  `generated_by` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student`
--

CREATE TABLE `student` (
  `studentid` int NOT NULL,
  `userid` int NOT NULL,
  `student_number` varchar(20) NOT NULL,
  `faculty` varchar(100) DEFAULT NULL,
  `year_of_study` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `student`
--

INSERT INTO `student` (`studentid`, `userid`, `student_number`, `faculty`, `year_of_study`) VALUES
(1, 3, '2022123456', 'Faculty of Computer and Mathematical Sciences', 3),
(2, 4, '2024554263', 'Faculty of Computer and Mathematical Sciences', 3),
(4, 10, '2024675786', 'Faculty of Education', 5),
(5, 11, '0172345678', 'Faculty of Applied Sciences', 2),
(6, 13, '2023123456', 'Faculty of Computer and Mathematical Sciences', 2),
(7, 15, '2021207872', 'Faculty of Information Science', 3);

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text NOT NULL,
  `description` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_at`) VALUES
(1, 'distance_calculation_method', 'google_maps', 'Method for calculating distance: google_maps, straight_line, or manual', '2025-07-21 18:00:14'),
(2, 'currency', 'RM', 'Currency symbol', '2025-07-21 18:00:14'),
(3, 'peak_hours_start', '07:00', 'Peak hours start time', '2025-07-21 18:00:14'),
(4, 'peak_hours_end', '09:00', 'Morning peak hours end time', '2025-07-21 18:00:14'),
(5, 'peak_hours_evening_start', '17:00', 'Evening peak hours start time', '2025-07-21 18:00:14'),
(6, 'peak_hours_evening_end', '19:00', 'Evening peak hours end time', '2025-07-21 18:00:14'),
(7, 'booking_cancellation_fee', '2.00', 'Fee for cancelling bookings', '2025-07-21 18:00:14'),
(8, 'driver_commission_percentage', '20', 'Percentage of fare that goes to platform', '2025-07-21 18:00:14');

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `userid` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `notel` varchar(15) NOT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `role` enum('student','driver','admin') NOT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`userid`, `username`, `full_name`, `password`, `email`, `notel`, `gender`, `role`, `created`, `status`) VALUES
(1, 'admin', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@rentogo.com', '0123456789', 'Male', 'admin', '2025-07-20 14:20:22', 'active'),
(2, 'driver1', 'driver1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'driver1@gmail.com', '0123456780', 'Male', 'driver', '2025-07-20 14:20:22', 'active'),
(3, 'student1', 'student1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student1@student.uitm.edu.my', '0123456781', 'Female', 'student', '2025-07-20 14:20:22', 'active'),
(4, 'hazwani', 'hazwani', '$2y$10$Uj/ZZjgn8tBhCHGWbA1r2.tLsHVkCa.6HGFW1Nt7WcKWpF3T0NzJq', 'fhazwani030220@gmail.com', '0137371987', 'Female', 'student', '2025-07-21 16:46:02', 'active'),
(6, 'mira', 'mira', '$2y$10$X68tHGi4s/OGJTP2k/cuUO//CnuOBIpKDtbFXijPg7ul6KKt6wLBu', 'mira123@gmail.com', '01110981765', 'Female', 'driver', '2025-07-23 02:50:41', 'active'),
(7, 'haziq', 'haziq', '$2y$10$PoMre.eNasT7JkMAghsIceKC/TDqhzvbJ4jZTFpEHzkwTyNdn8mku', 'haziq04@gmail.com', '01178965432', 'Male', 'driver', '2025-07-23 03:00:12', 'active'),
(9, 'fatimah', 'fatimah', '$2y$10$b3CIgUNKrG7ieA9/rgGgd.N5PWKCaqbbHO5NKs0e1HZKhxBYDEWpS', 'fatimah@gmail.com.my', '01161987549', 'Female', 'driver', '2025-07-23 03:56:20', 'active'),
(10, 'nazri', 'nazri', '$2y$10$0kNKFydCxy1duXrPqa.bK.VzFza2oyHuvD9MXKkzb2xKjzuuKVK96', 'nazri@gmail.com', '014567876', 'Male', 'student', '2025-07-23 07:24:05', 'active'),
(11, 'dira', 'dira', '$2y$10$wxBaVMnSnOqzKwKODJIpN.SakfyN4Sn5TSewDcdS3rLEXX5Y686.m', 'dira@gmail.com', '0172345678', 'Female', 'student', '2025-07-23 09:15:29', 'active'),
(13, '2023123456', 'Ahmad Bin Abdullah', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student1@graduate.utm.my', '+60123456781', 'Male', 'student', '2025-07-26 18:05:43', 'active'),
(14, 'driver_D1234567', 'Siti Binti Ahmad', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'driver1@example.com', '+60123456782', 'Female', 'driver', '2025-07-26 18:05:43', 'active'),
(15, '2021207872', 'FARIDAH HAZWANI BINTI ABDUL AZIZ', '$2y$10$9Lg/HkRwHNkd3TXx7sDLJOQYsI7SbJa0JvaUBMxWcLqzD07ym61v6', '2021207872@student.uitm.edu.my', '0137371987', 'Female', 'student', '2025-07-26 19:19:26', 'active'),
(16, 'driver_D09876543', 'wani', '$2y$10$eYJqpQXscFF6FToIwqnS6ubtyz836DNJqiMbNv61iQdkqmR4qPikC', 'wani123@gmail.com', '0137371987', 'Female', 'driver', '2025-07-26 20:32:37', 'active');

-- --------------------------------------------------------

--
-- Structure for view `current_pricing`
--
DROP TABLE IF EXISTS `current_pricing`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `current_pricing`  AS SELECT `pricing_settings`.`car_type` AS `car_type`, concat('RM ',convert(format(`pricing_settings`.`base_fare`,2) using utf8mb4)) AS `base_fare_display`, concat('RM ',convert(format(`pricing_settings`.`price_per_km`,2) using utf8mb4)) AS `price_per_km_display`, concat('RM ',convert(format(`pricing_settings`.`price_per_minute`,2) using utf8mb4)) AS `price_per_minute_display`, concat('RM ',convert(format(`pricing_settings`.`minimum_fare`,2) using utf8mb4)) AS `minimum_fare_display`, `pricing_settings`.`base_fare` AS `base_fare`, `pricing_settings`.`price_per_km` AS `price_per_km`, `pricing_settings`.`price_per_minute` AS `price_per_minute`, `pricing_settings`.`minimum_fare` AS `minimum_fare`, `pricing_settings`.`peak_multiplier` AS `peak_multiplier` FROM `pricing_settings` ORDER BY `pricing_settings`.`car_type` ASC  ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`adminid`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `booking`
--
ALTER TABLE `booking`
  ADD PRIMARY KEY (`bookingid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `driverid` (`driverid`);

--
-- Indexes for table `car_types`
--
ALTER TABLE `car_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_name` (`name`);

--
-- Indexes for table `driver`
--
ALTER TABLE `driver`
  ADD PRIMARY KEY (`driverid`),
  ADD UNIQUE KEY `licensenumber` (`licensenumber`),
  ADD UNIQUE KEY `plate` (`plate`),
  ADD KEY `userid` (`userid`),
  ADD KEY `idx_driver_license` (`licensenumber`),
  ADD KEY `idx_driver_plate` (`plate`);

--
-- Indexes for table `pricing_settings`
--
ALTER TABLE `pricing_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `car_type` (`car_type`);

--
-- Indexes for table `rating`
--
ALTER TABLE `rating`
  ADD PRIMARY KEY (`ratingid`),
  ADD KEY `bookingid` (`bookingid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `driverid` (`driverid`);

--
-- Indexes for table `report`
--
ALTER TABLE `report`
  ADD PRIMARY KEY (`reportid`),
  ADD KEY `bookingid` (`bookingid`),
  ADD KEY `generated_by` (`generated_by`);

--
-- Indexes for table `student`
--
ALTER TABLE `student`
  ADD PRIMARY KEY (`studentid`),
  ADD UNIQUE KEY `student_number` (`student_number`),
  ADD KEY `userid` (`userid`),
  ADD KEY `idx_student_number` (`student_number`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`userid`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_user_email` (`email`),
  ADD KEY `idx_user_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `adminid` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `booking`
--
ALTER TABLE `booking`
  MODIFY `bookingid` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `car_types`
--
ALTER TABLE `car_types`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `driver`
--
ALTER TABLE `driver`
  MODIFY `driverid` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `pricing_settings`
--
ALTER TABLE `pricing_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=190;

--
-- AUTO_INCREMENT for table `rating`
--
ALTER TABLE `rating`
  MODIFY `ratingid` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `report`
--
ALTER TABLE `report`
  MODIFY `reportid` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student`
--
ALTER TABLE `student`
  MODIFY `studentid` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `userid` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin`
--
ALTER TABLE `admin`
  ADD CONSTRAINT `admin_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `user` (`userid`) ON DELETE CASCADE;

--
-- Constraints for table `booking`
--
ALTER TABLE `booking`
  ADD CONSTRAINT `booking_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `user` (`userid`) ON DELETE CASCADE,
  ADD CONSTRAINT `booking_ibfk_2` FOREIGN KEY (`driverid`) REFERENCES `driver` (`driverid`) ON DELETE CASCADE;

--
-- Constraints for table `driver`
--
ALTER TABLE `driver`
  ADD CONSTRAINT `driver_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `user` (`userid`) ON DELETE CASCADE;

--
-- Constraints for table `rating`
--
ALTER TABLE `rating`
  ADD CONSTRAINT `rating_ibfk_1` FOREIGN KEY (`bookingid`) REFERENCES `booking` (`bookingid`) ON DELETE CASCADE,
  ADD CONSTRAINT `rating_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `user` (`userid`) ON DELETE CASCADE,
  ADD CONSTRAINT `rating_ibfk_3` FOREIGN KEY (`driverid`) REFERENCES `driver` (`driverid`) ON DELETE CASCADE;

--
-- Constraints for table `report`
--
ALTER TABLE `report`
  ADD CONSTRAINT `report_ibfk_1` FOREIGN KEY (`bookingid`) REFERENCES `booking` (`bookingid`) ON DELETE CASCADE,
  ADD CONSTRAINT `report_ibfk_2` FOREIGN KEY (`generated_by`) REFERENCES `user` (`userid`);

--
-- Constraints for table `student`
--
ALTER TABLE `student`
  ADD CONSTRAINT `student_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `user` (`userid`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

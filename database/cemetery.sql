-- Create database
CREATE DATABASE IF NOT EXISTS cemetery_db;
USE cemetery_db;

-- Sections table
CREATE TABLE IF NOT EXISTS sections (
    section_id INT PRIMARY KEY AUTO_INCREMENT,
    section_code VARCHAR(10) NOT NULL UNIQUE,
    section_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Plots table
CREATE TABLE IF NOT EXISTS plots (
    plot_id INT PRIMARY KEY AUTO_INCREMENT,
    section_id INT NOT NULL,
    row_number INT NOT NULL,
    plot_number INT NOT NULL,
    status ENUM('available', 'reserved', 'occupied') DEFAULT 'available',
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(section_id),
    UNIQUE KEY unique_plot (section_id, row_number, plot_number)
);

-- Deceased records table
CREATE TABLE IF NOT EXISTS deceased (
    deceased_id INT PRIMARY KEY AUTO_INCREMENT,
    plot_id INT,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    date_of_birth DATE,
    date_of_death DATE,
    date_of_burial DATE,
    FOREIGN KEY (plot_id) REFERENCES plots(plot_id)
);

-- Reservations table
CREATE TABLE IF NOT EXISTS reservations (
    reservation_id INT PRIMARY KEY AUTO_INCREMENT,
    plot_id INT,
    reserved_by VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20),
    email VARCHAR(100),
    reservation_date DATE,
    status ENUM('active', 'cancelled', 'completed') DEFAULT 'active',
    FOREIGN KEY (plot_id) REFERENCES plots(plot_id)
);

-- Users table for admin access
CREATE TABLE IF NOT EXISTS users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'staff') DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
); 
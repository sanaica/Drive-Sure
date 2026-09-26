-- DriveSure Database Schema
-- Run this once in phpMyAdmin (or MySQL) to create the database

CREATE DATABASE IF NOT EXISTS drivesure_db;
USE drivesure_db;

-- Customers (normal users)
CREATE TABLE IF NOT EXISTS CUSTOMER (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Admins (for approving claims)
CREATE TABLE IF NOT EXISTS ADMINS (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Vehicles belonging to a customer
CREATE TABLE IF NOT EXISTS VEHICLE (
    car_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    vehicle_type VARCHAR(50),
    make VARCHAR(50),
    model VARCHAR(50),
    year INT,
    plate_no VARCHAR(20),
    FOREIGN KEY (customer_id) REFERENCES CUSTOMER(customer_id) ON DELETE CASCADE
);

-- Insurance policies linked to a vehicle
CREATE TABLE IF NOT EXISTS POLICY (
    policy_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    car_id INT NOT NULL,
    plan_name VARCHAR(100),
    coverage_type VARCHAR(50),
    premium_amount DECIMAL(10,2),
    billing_cycle VARCHAR(30) DEFAULT 'Yearly',
    status VARCHAR(30) DEFAULT 'Active',
    start_date DATE DEFAULT (CURRENT_DATE),
    FOREIGN KEY (customer_id) REFERENCES CUSTOMER(customer_id) ON DELETE CASCADE,
    FOREIGN KEY (car_id) REFERENCES VEHICLE(car_id) ON DELETE CASCADE
);

-- Payments against a policy
CREATE TABLE IF NOT EXISTS PAYMENTS (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50),
    status VARCHAR(30) DEFAULT 'Paid',
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (policy_id) REFERENCES POLICY(policy_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES CUSTOMER(customer_id) ON DELETE CASCADE
);

-- Incident records (one per claim)
CREATE TABLE IF NOT EXISTS INCIDENT_RECORD (
    record_no VARCHAR(50) PRIMARY KEY,
    car_id INT,
    casualty VARCHAR(255),
    date_of_in DATE,
    type VARCHAR(50),
    location VARCHAR(255),
    FOREIGN KEY (car_id) REFERENCES VEHICLE(car_id) ON DELETE SET NULL
);

-- Claims filed by customers
CREATE TABLE IF NOT EXISTS CLAIMS (
    claim_id INT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT,
    record_no VARCHAR(50),
    customer_id INT,
    description TEXT,
    date_filed DATE,
    status VARCHAR(30) DEFAULT 'Pending Review',
    admin_note VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (policy_id) REFERENCES POLICY(policy_id) ON DELETE CASCADE,
    FOREIGN KEY (record_no) REFERENCES INCIDENT_RECORD(record_no) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES CUSTOMER(customer_id) ON DELETE CASCADE
);

-- Uploaded evidence (photos / invoices) for a claim
CREATE TABLE IF NOT EXISTS UPLOADED_EVIDENCE (
    image_id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT,
    file_path VARCHAR(255),
    file_category VARCHAR(50),
    FOREIGN KEY (claim_id) REFERENCES CLAIMS(claim_id) ON DELETE CASCADE
);

-- Default admin account
-- Email: admin@drivesure.com
-- Password: admin123
INSERT INTO ADMINS (name, email, password)
VALUES (
    'DriveSure Admin',
    'admin@drivesure.com',
    '$2y$10$3ORUs9pU9RBSCSfJ.vmQtuxeTsS0nx1EQGqrBCZmdUiLXU2U0k5HC'
)
ON DUPLICATE KEY UPDATE email = email;

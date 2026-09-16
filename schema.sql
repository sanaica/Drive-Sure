CREATE DATABASE IF NOT EXISTS drivesure_db;
USE drivesure_db;

CREATE TABLE IF NOT EXISTS CUSTOMER (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    address VARCHAR(255),
    dob DATE,
    phone VARCHAR(20),
    age INT
);

CREATE TABLE IF NOT EXISTS VEHICLE (
    car_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    vehicle_type VARCHAR(50),
    make VARCHAR(50),
    model VARCHAR(50),
    year INT,
    plate_no VARCHAR(20),
    color VARCHAR(30),
    price DECIMAL(12,2),
    brand VARCHAR(50),
    fuel_type VARCHAR(30),
    FOREIGN KEY (customer_id) REFERENCES CUSTOMER(customer_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS POLICY (
    policy_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    car_id INT NOT NULL,
    plan_name VARCHAR(100),
    coverage_type VARCHAR(50),
    premium_amount DECIMAL(10,2),
    billing_cycle VARCHAR(30),
    status VARCHAR(30) DEFAULT 'Active',
    start_date DATE,
    duration INT,
    FOREIGN KEY (customer_id) REFERENCES CUSTOMER(customer_id) ON DELETE CASCADE,
    FOREIGN KEY (car_id) REFERENCES VEHICLE(car_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS PAYMENTS (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50),
    status VARCHAR(30) DEFAULT 'Paid',
    payment_date DATETIME,
    FOREIGN KEY (policy_id) REFERENCES POLICY(policy_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES CUSTOMER(customer_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS INCIDENT_RECORD (
    record_no VARCHAR(50) PRIMARY KEY,
    car_id INT,
    casualty VARCHAR(255),
    date_of_in DATE,
    type VARCHAR(50),
    location VARCHAR(255),
    FOREIGN KEY (car_id) REFERENCES VEHICLE(car_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS ADMINS (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    email VARCHAR(100) UNIQUE,
    password_hash VARCHAR(255),
    role VARCHAR(50)
);

CREATE TABLE IF NOT EXISTS CLAIMS (
    claim_id INT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT,
    record_no VARCHAR(50),
    customer_id INT,
    description TEXT,
    date_filed DATE,
    status VARCHAR(30),
    FOREIGN KEY (policy_id) REFERENCES POLICY(policy_id) ON DELETE CASCADE,
    FOREIGN KEY (record_no) REFERENCES INCIDENT_RECORD(record_no) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES CUSTOMER(customer_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS UPLOADED_EVIDENCE (
    image_id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT,
    file_path VARCHAR(255),
    file_category VARCHAR(50),
    is_blurry BOOLEAN,
    perceptual_hash VARCHAR(128),
    exif_timestamp TIMESTAMP,
    exif_latitude DECIMAL(10,7),
    exif_longitude DECIMAL(10,7),
    gps_distance_m DECIMAL(10,2),
    FOREIGN KEY (claim_id) REFERENCES CLAIMS(claim_id) ON DELETE CASCADE
);

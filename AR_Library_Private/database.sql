CREATE DATABASE IF NOT EXISTS ar_library CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ar_library;

CREATE TABLE IF NOT EXISTS staff (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 staff_id VARCHAR(50) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 photo VARCHAR(255) NULL,
 password VARCHAR(255) NOT NULL,
 role VARCHAR(30) NOT NULL DEFAULT 'staff',
 status VARCHAR(20) NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS members (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 member_id VARCHAR(50) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 phone VARCHAR(30) DEFAULT NULL,
 email VARCHAR(150) DEFAULT NULL,
 membership_plan VARCHAR(50) DEFAULT '1 Month',
 shift VARCHAR(30) DEFAULT 'Full Day',
 joining_date DATE DEFAULT NULL,
 date_of_birth DATE DEFAULT NULL,
 validity_date DATE DEFAULT NULL,
 address TEXT DEFAULT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'Active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_member_status(status), INDEX idx_member_phone(phone), INDEX idx_validity(validity_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS member_fees (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 member_id INT UNSIGNED NOT NULL,
 receipt_no VARCHAR(50) DEFAULT NULL,
 amount DECIMAL(10,2) NOT NULL DEFAULT 0,
 payment_date DATE NOT NULL,
 due_date DATE DEFAULT NULL,
 payment_method VARCHAR(30) DEFAULT 'Cash',
 status VARCHAR(20) NOT NULL DEFAULT 'Paid',
 remarks VARCHAR(255) DEFAULT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_member_fee(member_id), INDEX idx_fee_status(status), INDEX idx_fee_date(payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 receipt_no VARCHAR(50) NOT NULL UNIQUE,
 member_id INT UNSIGNED NOT NULL,
 member_code VARCHAR(50) NOT NULL,
 amount DECIMAL(10,2) NOT NULL DEFAULT 0,
 fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
 additional_charges DECIMAL(10,2) NOT NULL DEFAULT 0,
 payment_type VARCHAR(20) NOT NULL DEFAULT 'Full',
 payment_date DATE NOT NULL,
 payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
 plan VARCHAR(100) DEFAULT NULL,
 notes TEXT DEFAULT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_payment_member(member_id), INDEX idx_payment_date(payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fee_settings (
 setting_key VARCHAR(50) PRIMARY KEY,
 setting_value DECIMAL(10,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS member_seats (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 member_id INT UNSIGNED NOT NULL,
 seat_no VARCHAR(50) DEFAULT NULL,
 shift VARCHAR(30) DEFAULT 'Full Day',
 start_date DATE DEFAULT NULL,
 end_date DATE DEFAULT NULL,
 status VARCHAR(20) DEFAULT 'Assigned',
 remarks VARCHAR(255) DEFAULT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_member_seat(member_id), INDEX idx_seat_shift(seat_no,shift), INDEX idx_seat_dates(start_date,end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lockers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 locker_no VARCHAR(20) NOT NULL,
 member_id INT UNSIGNED NOT NULL,
 start_date DATE DEFAULT NULL,
 end_date DATE DEFAULT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'Assigned',
 remarks VARCHAR(255) DEFAULT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_locker_no(locker_no), INDEX idx_locker_member(member_id), INDEX idx_locker_dates(start_date,end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 member_id INT UNSIGNED NOT NULL,
 attendance_date DATE NOT NULL,
 check_in DATETIME DEFAULT NULL,
 check_out DATETIME DEFAULT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'Open',
 remarks VARCHAR(255) DEFAULT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_member_day(member_id,attendance_date), INDEX idx_att_date(attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enquiries (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 phone VARCHAR(30),
 requirement VARCHAR(255),
 follow_up DATE NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'Open',
 notes TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_enq_status(status), INDEX idx_enq_follow(follow_up)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 expense_date DATE NOT NULL,
 category VARCHAR(100) NOT NULL,
 description VARCHAR(255),
 amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 payment_method VARCHAR(30) DEFAULT 'Cash',
 vendor VARCHAR(150), notes TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_exp_date(expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS library_settings (
 setting_key VARCHAR(80) PRIMARY KEY,
 setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 staff_id VARCHAR(50), action VARCHAR(100) NOT NULL, details VARCHAR(255),
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_activity(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO staff(staff_id,name,password,role,status) VALUES
('STF-001','Administrator','$2y$12$3R2/WsLLy8JphNj6BD4id.lw.2.RI9HHlUi83RbQU2FrbUAZWj8H2','admin','active')
ON DUPLICATE KEY UPDATE staff_id=VALUES(staff_id);

INSERT INTO library_settings(setting_key,setting_value) VALUES
('library_name','AR LIBRARY'),('phone','8349852152'),('address','Ward No. 15, Sarkari Hospital ke Samane, Baikunthpur, Rewa, Madhya Pradesh - 486441'),
('opening_time','08:00'),('closing_time','20:00'),('morning_end','14:00'),('evening_start','14:00'),
('total_seats','79'),('total_lockers','79'),('monthly_fee','1100'),('currency','INR'),
('receipt_footer','Thank you for choosing AR Library.'),('attendance_latitude','24.735323'),('attendance_longitude','81.409182'),('attendance_radius_meters','100')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

INSERT INTO fee_settings(setting_key,setting_value) VALUES
('half_day_one',600),('half_day_three',1710),('half_day_six',3240),
('half_reserved_one',800),('half_reserved_three',2280),('half_reserved_six',4320),
('full_day_one',1100),('full_day_three',3135),('full_day_six',5940),
('full_reserved_one',1300),('full_reserved_three',3705),('full_reserved_six',7020)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

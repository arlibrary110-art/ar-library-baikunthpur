-- AR Library - ONE-TIME Fresh Start Reset
-- Removes all old member/fee/pending/attendance/seat/locker/enquiry/activity data.
-- Preserves staff/admin accounts, library settings, fee settings and expenses.
-- BACK UP THE DATABASE FIRST. Run this on the existing ar_library database.

USE ar_library;
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE attendance;
TRUNCATE TABLE member_seats;
TRUNCATE TABLE lockers;
TRUNCATE TABLE payments;
TRUNCATE TABLE member_fees;
TRUNCATE TABLE members;
TRUNCATE TABLE enquiries;
TRUNCATE TABLE activity_logs;
SET FOREIGN_KEY_CHECKS = 1;

SELECT
  (SELECT COUNT(*) FROM members) AS members_remaining,
  (SELECT COUNT(*) FROM member_fees) AS fee_records_remaining,
  (SELECT COUNT(*) FROM payments) AS payment_records_remaining,
  (SELECT COUNT(*) FROM attendance) AS attendance_records_remaining,
  (SELECT COUNT(*) FROM member_seats) AS seat_assignments_remaining,
  (SELECT COUNT(*) FROM lockers) AS locker_assignments_remaining,
  (SELECT COUNT(*) FROM enquiries) AS enquiries_remaining,
  (SELECT COUNT(*) FROM activity_logs) AS activity_logs_remaining;

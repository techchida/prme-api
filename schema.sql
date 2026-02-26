CREATE TABLE IF NOT EXISTS registrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(50) NOT NULL DEFAULT '',
  minister_name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(80) NOT NULL DEFAULT '',
  category VARCHAR(255) NOT NULL DEFAULT '',
  venue_details TEXT NOT NULL,
  city VARCHAR(255) NOT NULL DEFAULT '',
  state VARCHAR(255) NOT NULL DEFAULT '',
  country VARCHAR(255) NOT NULL DEFAULT '',
  conference_mode VARCHAR(20) NOT NULL DEFAULT 'Onsite',
  attendance_target INT NOT NULL DEFAULT 0,
  mobilization_strategy TEXT NOT NULL,
  conference_type VARCHAR(255) NOT NULL DEFAULT '',
  has_organized_before ENUM('Yes','No') NOT NULL DEFAULT 'No',
  organized_before_report TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_registrations_created_at (created_at),
  UNIQUE KEY uniq_registrations_email (email)
);

CREATE TABLE IF NOT EXISTS resources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  type ENUM('video','pdf','audio') NOT NULL,
  url TEXT NOT NULL,
  thumbnail TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS gallery (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  image_url TEXT NOT NULL,
  status ENUM('ongoing','past') NOT NULL DEFAULT 'past',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stripe_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  stripe_session_id VARCHAR(255) NOT NULL,
  stripe_payment_intent_id VARCHAR(255) NULL,
  donor_name VARCHAR(255) NOT NULL DEFAULT '',
  donor_email VARCHAR(255) NOT NULL DEFAULT '',
  amount_cents INT NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'usd',
  checkout_status VARCHAR(50) NOT NULL DEFAULT 'open',
  payment_status VARCHAR(50) NOT NULL DEFAULT 'unpaid',
  checkout_url TEXT NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_stripe_session_id (stripe_session_id),
  INDEX idx_stripe_payments_created_at (created_at),
  INDEX idx_stripe_payments_donor_email (donor_email)
);

CREATE TABLE IF NOT EXISTS bank_transfer_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  donor_name VARCHAR(255) NOT NULL DEFAULT '',
  donor_email VARCHAR(255) NOT NULL DEFAULT '',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency VARCHAR(10) NOT NULL DEFAULT 'usd',
  proof_original_name VARCHAR(255) NOT NULL,
  proof_stored_name VARCHAR(255) NOT NULL,
  proof_path VARCHAR(512) NOT NULL,
  mime_type VARCHAR(100) NOT NULL DEFAULT '',
  file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(50) NOT NULL DEFAULT 'pending_review',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bank_transfer_created_at (created_at),
  INDEX idx_bank_transfer_email (donor_email)
);

CREATE TABLE IF NOT EXISTS espees_payment_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  donor_name VARCHAR(255) NOT NULL DEFAULT '',
  donor_email VARCHAR(255) NOT NULL DEFAULT '',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency VARCHAR(10) NOT NULL DEFAULT 'usd',
  espees_code VARCHAR(50) NOT NULL DEFAULT '',
  proof_original_name VARCHAR(255) NOT NULL,
  proof_stored_name VARCHAR(255) NOT NULL,
  proof_path VARCHAR(512) NOT NULL,
  mime_type VARCHAR(100) NOT NULL DEFAULT '',
  file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(50) NOT NULL DEFAULT 'pending_review',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_espees_payments_created_at (created_at),
  INDEX idx_espees_payments_email (donor_email)
);

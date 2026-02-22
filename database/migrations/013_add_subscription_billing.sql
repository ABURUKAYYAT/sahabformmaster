-- Manual subscription billing for schools
-- Principal selects plan, uploads transfer proof, super admin verifies and approves.

CREATE TABLE IF NOT EXISTS subscription_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    plan_code VARCHAR(50) NOT NULL UNIQUE,
    billing_cycle ENUM('monthly', 'termly', 'lifetime') NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    duration_days INT NULL,
    grace_days INT NOT NULL DEFAULT 7,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS school_subscription_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    plan_id INT NOT NULL,
    requested_by INT NOT NULL,
    expected_amount DECIMAL(12,2) NOT NULL,
    status ENUM('pending_payment', 'under_review', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending_payment',
    request_note TEXT NULL,
    review_note TEXT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_subreq_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_subreq_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE RESTRICT,
    CONSTRAINT fk_subreq_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_subreq_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_subreq_school_status (school_id, status),
    INDEX idx_subreq_status (status),
    INDEX idx_subreq_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS school_subscription_payment_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    transfer_date DATE NOT NULL,
    amount_paid DECIMAL(12,2) NOT NULL,
    transfer_reference VARCHAR(120) NULL,
    bank_name VARCHAR(120) NULL,
    account_name VARCHAR(120) NULL,
    note TEXT NULL,
    proof_file_path VARCHAR(255) NOT NULL,
    status ENUM('under_review', 'approved', 'rejected') NOT NULL DEFAULT 'under_review',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_subproof_request FOREIGN KEY (request_id) REFERENCES school_subscription_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_subproof_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_subproof_request (request_id),
    INDEX idx_subproof_status (status)
);

CREATE TABLE IF NOT EXISTS school_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    plan_id INT NOT NULL,
    source_request_id INT NULL,
    status ENUM('active', 'grace_period', 'expired', 'suspended', 'lifetime_active') NOT NULL DEFAULT 'active',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    grace_end_date DATE NULL,
    approved_by INT NOT NULL,
    approved_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sub_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sub_request FOREIGN KEY (source_request_id) REFERENCES school_subscription_requests(id) ON DELETE SET NULL,
    CONSTRAINT fk_sub_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_sub_school_status (school_id, status),
    INDEX idx_sub_dates (end_date, grace_end_date)
);

CREATE TABLE IF NOT EXISTS school_subscription_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    request_id INT NULL,
    action VARCHAR(80) NOT NULL,
    actor_id INT NOT NULL,
    actor_role VARCHAR(40) NOT NULL,
    message TEXT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_subaudit_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_subaudit_request FOREIGN KEY (request_id) REFERENCES school_subscription_requests(id) ON DELETE SET NULL,
    CONSTRAINT fk_subaudit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_subaudit_school (school_id),
    INDEX idx_subaudit_action (action),
    INDEX idx_subaudit_created_at (created_at)
);

-- Seed default plans
INSERT INTO subscription_plans (name, plan_code, billing_cycle, amount, duration_days, grace_days, description, is_active)
SELECT 'Monthly Plan', 'monthly_default', 'monthly', 20000.00, 30, 7, 'Monthly subscription for one school.', 1
WHERE NOT EXISTS (SELECT 1 FROM subscription_plans WHERE plan_code = 'monthly_default');

INSERT INTO subscription_plans (name, plan_code, billing_cycle, amount, duration_days, grace_days, description, is_active)
SELECT 'Termly Plan', 'termly_default', 'termly', 50000.00, 120, 7, 'Term-based subscription for one school.', 1
WHERE NOT EXISTS (SELECT 1 FROM subscription_plans WHERE plan_code = 'termly_default');

INSERT INTO subscription_plans (name, plan_code, billing_cycle, amount, duration_days, grace_days, description, is_active)
SELECT 'Lifetime License', 'lifetime_default', 'lifetime', 500000.00, NULL, 0, 'One-time lifetime license for one school.', 1
WHERE NOT EXISTS (SELECT 1 FROM subscription_plans WHERE plan_code = 'lifetime_default');

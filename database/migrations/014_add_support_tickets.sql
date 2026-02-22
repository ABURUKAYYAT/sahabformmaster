-- Internal support system: principals communicate with super admin.

CREATE TABLE IF NOT EXISTS support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_code VARCHAR(40) UNIQUE NULL,
    school_id INT NOT NULL,
    created_by INT NOT NULL,
    assigned_to INT NULL,
    category ENUM('query', 'observation', 'suggestion') NOT NULL DEFAULT 'query',
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    subject VARCHAR(255) NOT NULL,
    status ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    last_message_at DATETIME NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_ticket_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_ticket_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_support_ticket_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_support_ticket_school_status (school_id, status),
    INDEX idx_support_ticket_status (status),
    INDEX idx_support_ticket_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS support_ticket_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    sender_id INT NOT NULL,
    sender_role ENUM('principal', 'super_admin') NOT NULL,
    message TEXT NOT NULL,
    attachment_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_msg_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_support_msg_ticket (ticket_id),
    INDEX idx_support_msg_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS support_ticket_reads (
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    last_read_at DATETIME NOT NULL,
    PRIMARY KEY (ticket_id, user_id),
    CONSTRAINT fk_support_read_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

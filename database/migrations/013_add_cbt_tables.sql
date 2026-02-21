-- CBT tables (tests, questions, attempts, answers)

CREATE TABLE IF NOT EXISTS cbt_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    teacher_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 30,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cbt_tests_school (school_id),
    INDEX idx_cbt_tests_teacher (teacher_id),
    INDEX idx_cbt_tests_class (class_id),
    INDEX idx_cbt_tests_subject (subject_id),
    CONSTRAINT fk_cbt_tests_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_cbt_tests_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cbt_tests_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_cbt_tests_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cbt_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    question_text TEXT NOT NULL,
    option_a TEXT NOT NULL,
    option_b TEXT NOT NULL,
    option_c TEXT NOT NULL,
    option_d TEXT NOT NULL,
    correct_option ENUM('A','B','C','D') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cbt_questions_test (test_id),
    CONSTRAINT fk_cbt_questions_test FOREIGN KEY (test_id) REFERENCES cbt_tests(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cbt_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    student_id INT NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME NULL,
    score INT NOT NULL DEFAULT 0,
    total_questions INT NOT NULL DEFAULT 0,
    status ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
    INDEX idx_cbt_attempts_test (test_id),
    INDEX idx_cbt_attempts_student (student_id),
    UNIQUE KEY uq_cbt_attempts_test_student (test_id, student_id),
    CONSTRAINT fk_cbt_attempts_test FOREIGN KEY (test_id) REFERENCES cbt_tests(id) ON DELETE CASCADE,
    CONSTRAINT fk_cbt_attempts_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cbt_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    selected_option ENUM('A','B','C','D') NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_cbt_answers_attempt (attempt_id),
    INDEX idx_cbt_answers_question (question_id),
    UNIQUE KEY uq_cbt_answers_attempt_question (attempt_id, question_id),
    CONSTRAINT fk_cbt_answers_attempt FOREIGN KEY (attempt_id) REFERENCES cbt_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cbt_answers_question FOREIGN KEY (question_id) REFERENCES cbt_questions(id) ON DELETE CASCADE
);

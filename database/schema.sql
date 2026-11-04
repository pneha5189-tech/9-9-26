CREATE DATABASE IF NOT EXISTS placement_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE placement_system;

CREATE TABLE IF NOT EXISTS companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  website VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'student', 'recruiter') NOT NULL,
  company_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  roll_number VARCHAR(40) NOT NULL UNIQUE,
  department VARCHAR(100) NOT NULL,
  graduation_year SMALLINT UNSIGNED NOT NULL,
  cgpa DECIMAL(4,2) NOT NULL,
  phone VARCHAR(30) NULL,
  skills VARCHAR(500) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  posted_by INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  location VARCHAR(120) NOT NULL,
  min_cgpa DECIMAL(4,2) NOT NULL DEFAULT 0,
  eligible_departments VARCHAR(255) NOT NULL DEFAULT '*',
  graduation_year SMALLINT UNSIGNED NULL,
  status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_jobs_status_created (status, created_at),
  CONSTRAINT fk_jobs_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_jobs_poster FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  status ENUM('applied', 'shortlisted', 'interviewed', 'offered', 'rejected') NOT NULL DEFAULT 'applied',
  recruiter_notes VARCHAR(1000) NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_application_job_student (job_id, student_id),
  INDEX idx_applications_status (status),
  CONSTRAINT fk_applications_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_applications_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS application_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  old_status ENUM('applied', 'shortlisted', 'interviewed', 'offered', 'rejected') NULL,
  new_status ENUM('applied', 'shortlisted', 'interviewed', 'offered', 'rejected') NOT NULL,
  note VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_application_events (application_id, created_at),
  CONSTRAINT fk_events_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_user FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO companies (id, name, website) VALUES
  (1, 'Northstar Software', 'https://example.com/northstar'),
  (2, 'Greenfield Analytics', 'https://example.com/greenfield')
ON DUPLICATE KEY UPDATE name = VALUES(name), website = VALUES(website);

-- Demo account password for every account below: password
-- The value is a bcrypt hash; change these demo credentials before real use.
INSERT INTO users (id, name, email, password_hash, role, company_id) VALUES
  (1, 'Placement Officer', 'officer@example.com', '$2y$10$aZi9Y18MkvZtSfXFDCemlufGv8nbeeoKmoUTK9nnsjLTE7RSScJP2', 'admin', NULL),
  (2, 'Aarav Sharma', 'aarav@example.com', '$2y$10$aZi9Y18MkvZtSfXFDCemlufGv8nbeeoKmoUTK9nnsjLTE7RSScJP2', 'student', NULL),
  (3, 'Northstar Recruiter', 'recruiter@example.com', '$2y$10$aZi9Y18MkvZtSfXFDCemlufGv8nbeeoKmoUTK9nnsjLTE7RSScJP2', 'recruiter', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role), company_id = VALUES(company_id);

INSERT INTO students (user_id, roll_number, department, graduation_year, cgpa, phone, skills) VALUES
  (2, 'CSE2026-014', 'Computer Science', 2026, 8.40, '555-0104', 'PHP, MySQL, Java, problem solving')
ON DUPLICATE KEY UPDATE department = VALUES(department), graduation_year = VALUES(graduation_year),
  cgpa = VALUES(cgpa), phone = VALUES(phone), skills = VALUES(skills);

INSERT INTO jobs (id, company_id, posted_by, title, description, location, min_cgpa, eligible_departments, graduation_year, status) VALUES
  (1, 1, 3, 'Graduate Software Engineer', 'Build and test web services with an experienced engineering team. Applicants should be comfortable with programming fundamentals and collaborative development.', 'Bengaluru / Hybrid', 7.00, 'Computer Science,Information Technology', 2026, 'open')
ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), status = VALUES(status);

INSERT INTO applications (job_id, student_id)
SELECT 1, id FROM students WHERE user_id = 2
ON DUPLICATE KEY UPDATE job_id = VALUES(job_id);

INSERT INTO application_events (application_id, changed_by, old_status, new_status, note)
SELECT a.id, u.id, NULL, 'applied', 'Sample application ready for the recruiter review walkthrough.'
FROM applications a
JOIN students s ON s.id = a.student_id
JOIN users u ON u.id = s.user_id
WHERE a.job_id = 1 AND u.email = 'aarav@example.com'
  AND NOT EXISTS (
    SELECT 1 FROM application_events e
    WHERE e.application_id = a.id AND e.new_status = 'applied'
  );

-- ==============================================================
-- FinLife — Migration: Compromissos e Contas Fixas
-- Execute no phpMyAdmin: selecione o banco (spssolar_finlife) → aba SQL → cole e Execute.
-- Idempotente (CREATE TABLE IF NOT EXISTS) — seguro rodar mais de uma vez.
-- BACKUP: antes de rodar, exporte o banco completo em phpMyAdmin → Export → Quick.
-- ==============================================================

CREATE TABLE IF NOT EXISTS commitments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  payee VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  kind ENUM('debt','recurring') NOT NULL DEFAULT 'recurring',
  category_key ENUM('divida_pessoal','telefone','internet','servico','mensalidade','emprestimo_informal','assinatura','outro') NOT NULL DEFAULT 'outro',
  total_amount DECIMAL(12,2) NULL,
  installment_amount DECIMAL(12,2) NULL,
  periodicity ENUM('weekly','biweekly','monthly','quarterly','semiannual','yearly','custom') NOT NULL DEFAULT 'monthly',
  custom_days INT UNSIGNED NULL,
  next_due DATE NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  total_installments INT UNSIGNED NULL,
  account_id INT UNSIGNED NULL,
  payment_method VARCHAR(40) NULL,
  category_id INT UNSIGNED NULL,
  status ENUM('active','paused','settled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_commit_user (user_id, status),
  CONSTRAINT fk_commit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_commit_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_commit_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commitment_occurrences (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  commitment_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('pending','paid','partial','skipped') NOT NULL DEFAULT 'pending',
  paid_at DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_commit_due (commitment_id, due_date),
  KEY idx_occ_user_due (user_id, due_date),
  CONSTRAINT fk_occ_commit FOREIGN KEY (commitment_id) REFERENCES commitments(id) ON DELETE CASCADE,
  CONSTRAINT fk_occ_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commitment_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  commitment_id INT UNSIGNED NOT NULL,
  occurrence_id BIGINT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_date DATE NOT NULL,
  account_id INT UNSIGNED NULL,
  description VARCHAR(300) NULL,
  transaction_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pay_commit (commitment_id),
  KEY idx_pay_user_date (user_id, paid_date),
  CONSTRAINT fk_pay_commit FOREIGN KEY (commitment_id) REFERENCES commitments(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
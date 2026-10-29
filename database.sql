-- ==============================================================
-- FinLife — Schema MySQL / MariaDB
-- Executado automaticamente por:  php scripts/install.php
-- Idempotente: pode ser reexecutado sem perder dados.
-- ==============================================================

CREATE DATABASE IF NOT EXISTS finlife CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE finlife;

-- --------------------------------------------------------------
-- Usuários
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BRL',
  monthly_budget DECIMAL(12,2) NULL,
  ai_chat_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- Contas (corrente, poupança, dinheiro, carteiras digitais...)
-- Saldo atual = saldo inicial + receitas - despesas da conta
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  type ENUM('checking','savings','cash','digital_wallet','investment','other') NOT NULL DEFAULT 'checking',
  institution VARCHAR(80) NULL,
  initial_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  color VARCHAR(7) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_accounts_user (user_id),
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- Categorias (por usuário, com valores padrão no cadastro)
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  type ENUM('income','expense') NOT NULL,
  icon VARCHAR(16) NULL,
  color VARCHAR(7) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_user_name (user_id, name, type),
  KEY idx_categories_user_type (user_id, type),
  CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- Transações (receitas e despesas)
-- Campos de recorrência/parcelamento preparados para a fase 2
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  type ENUM('income','expense') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description VARCHAR(255) NOT NULL,
  date DATE NOT NULL,
  is_recurring TINYINT(1) NOT NULL DEFAULT 0,
  recurrence ENUM('none','weekly','monthly','yearly') NOT NULL DEFAULT 'none',
  installments_total INT UNSIGNED NULL,
  installment_number INT UNSIGNED NULL,
  created_by ENUM('web','ai') NOT NULL DEFAULT 'web',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tx_user_date (user_id, date),
  KEY idx_tx_user_type_date (user_id, type, date),
  KEY idx_tx_user_cat (user_id, category_id),
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tx_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_tx_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- Metas financeiras + aportes
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS goals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  target_amount DECIMAL(12,2) NOT NULL,
  deadline DATE NULL,
  status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  KEY idx_goals_user (user_id),
  CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goal_deposits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  goal_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  date DATE NOT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_deposits_user (user_id),
  CONSTRAINT fk_deposits_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
  CONSTRAINT fk_deposits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- Notificações (fase 2 — tabela já preparada)
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  title VARCHAR(120) NOT NULL,
  message VARCHAR(500) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notifications_user (user_id, is_read),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- Conversas com o FinLife AI
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL DEFAULT 'Conversa',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_conv_user (user_id),
  CONSTRAINT fk_conv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content TEXT NOT NULL,
  meta TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_msg_conv (conversation_id, id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- Limitação de requisições (login, IA, cadastro...)
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_rate_identifier (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================
-- Módulo de Cartões
-- ==============================================================
-- REGRA DE CONTABILIDADE (evita dupla contabilização):
--   • Compra no CRÉDITO  → card_purchases + card_installments (compromisso
--     com faturas futuras). NÃO gera saída de conta. O dinheiro sai da conta
--     somente no PAGAMENTO DA FATURA (que cria 1 transaction).
--   • Compra no DÉBITO   → card_purchases + 1 transaction imediata na conta
--     associada (saída real de dinheiro).
--   • Gasto de BENEFÍCIO → card_purchases (method=benefit) debita o saldo
--     do benefício (card_balances). NUNCA toca em contas/transações.
--   • Limite de crédito usado = soma das parcelas ainda não pagas.
--     Pagar a fatura quita parcelas → libera limite automaticamente.
-- --------------------------------------------------------------

-- Cartões: crédito, débito, benefício e múltiplo + adicionais (parent_card_id)
-- Segurança: apenas últimos 4 dígitos (last4). NUNCA número completo/CVV/senha.
CREATE TABLE IF NOT EXISTS cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  nickname VARCHAR(40) NULL,
  institution VARCHAR(80) NULL,
  brand VARCHAR(30) NULL,
  type ENUM('credit','debit','benefit','multiple') NOT NULL DEFAULT 'credit',
  last4 CHAR(4) NULL,
  color VARCHAR(7) NULL,
  limit_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  closing_day TINYINT UNSIGNED NULL COMMENT 'Dia de fechamento (1-28)',
  due_day TINYINT UNSIGNED NULL COMMENT 'Dia de vencimento (1-28)',
  annual_fee DECIMAL(12,2) NULL,
  account_id INT UNSIGNED NULL COMMENT 'Conta associada (débito / pagamento de fatura)',
  parent_card_id INT UNSIGNED NULL COMMENT 'Cartão adicional → principal',
  status ENUM('active','blocked','archived') NOT NULL DEFAULT 'active',
  limit_alert_pct TINYINT UNSIGNED NOT NULL DEFAULT 80,
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cards_user (user_id),
  KEY idx_cards_parent (parent_card_id),
  CONSTRAINT fk_cards_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cards_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_cards_parent FOREIGN KEY (parent_card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saldos independentes de benefícios (cartões multi-benefício: VA + VR + ...)
CREATE TABLE IF NOT EXISTS card_balances (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id INT UNSIGNED NOT NULL,
  benefit_kind ENUM('meal','food','fuel','mobility','home_office','transport','award','other') NOT NULL,
  monthly_credit DECIMAL(12,2) NULL COMMENT 'Crédito mensal esperado',
  credit_day TINYINT UNSIGNED NULL COMMENT 'Dia do crédito mensal',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_balance_card_kind (card_id, benefit_kind),
  CONSTRAINT fk_balance_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de créditos recebidos em benefícios
CREATE TABLE IF NOT EXISTS card_benefit_credits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  balance_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  date DATE NOT NULL,
  periodicity ENUM('monthly','weekly','one_time') NOT NULL DEFAULT 'monthly',
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_credit_balance (balance_id, date),
  CONSTRAINT fk_credit_balance FOREIGN KEY (balance_id) REFERENCES card_balances(id) ON DELETE CASCADE,
  CONSTRAINT fk_credit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compras (crédito, débito e benefício). A compra original agrupa suas parcelas.
CREATE TABLE IF NOT EXISTS card_purchases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  card_id INT UNSIGNED NOT NULL,
  balance_id INT UNSIGNED NULL COMMENT 'Válido para gasto de benefício',
  category_id INT UNSIGNED NULL,
  transaction_id BIGINT UNSIGNED NULL COMMENT 'Vínculo com a saída real (compra no débito)',
  description VARCHAR(255) NOT NULL,
  store VARCHAR(120) NULL,
  amount DECIMAL(12,2) NOT NULL COMMENT 'Valor total da compra',
  method ENUM('credit','debit','benefit') NOT NULL DEFAULT 'credit',
  installments_total INT UNSIGNED NOT NULL DEFAULT 1,
  interest DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Juros embutidos (informativo)',
  fees DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Taxas (informativo)',
  notes VARCHAR(500) NULL,
  date DATE NOT NULL,
  created_by ENUM('web','ai') NOT NULL DEFAULT 'web',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_purchases_user_date (user_id, date),
  KEY idx_purchases_card (card_id),
  KEY idx_purchases_balance (balance_id),
  CONSTRAINT fk_purchases_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchases_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchases_balance FOREIGN KEY (balance_id) REFERENCES card_balances(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchases_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchases_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Faturas: um ciclo por cartão de crédito (identificado pela data de fechamento)
CREATE TABLE IF NOT EXISTS card_invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  card_id INT UNSIGNED NOT NULL,
  cycle_key DATE NOT NULL COMMENT 'Data de fechamento (identidade do ciclo)',
  period_start DATE NOT NULL,
  period_end DATE NOT NULL COMMENT 'Data do fechamento',
  due_date DATE NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invoice_card_cycle (card_id, cycle_key),
  KEY idx_invoices_user (user_id),
  CONSTRAINT fk_invoices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoices_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parcelas: uma linha por parcela, sempre vinculada à fatura do seu ciclo
CREATE TABLE IF NOT EXISTS card_installments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  card_id INT UNSIGNED NOT NULL COMMENT 'Cartão raiz (principal) da compra',
  number INT UNSIGNED NOT NULL,
  total INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  due_date DATE NOT NULL COMMENT 'Vencimento previsto da parcela',
  invoice_id INT UNSIGNED NULL,
  status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_installment (purchase_id, number),
  KEY idx_installments_user (user_id, status),
  KEY idx_installments_invoice (invoice_id),
  CONSTRAINT fk_installments_purchase FOREIGN KEY (purchase_id) REFERENCES card_purchases(id) ON DELETE CASCADE,
  CONSTRAINT fk_installments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_installments_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_installments_invoice FOREIGN KEY (invoice_id) REFERENCES card_invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pagamentos de fatura (total ou parcial) — cada um gera 1 saída real na conta
CREATE TABLE IF NOT EXISTS card_invoice_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  date DATE NOT NULL,
  account_id INT UNSIGNED NULL,
  transaction_id BIGINT UNSIGNED NULL,
  kind ENUM('full','partial') NOT NULL DEFAULT 'full',
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payinv_invoice (invoice_id),
  KEY idx_payinv_user (user_id),
  CONSTRAINT fk_payinv_invoice FOREIGN KEY (invoice_id) REFERENCES card_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_payinv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_payinv_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_payinv_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assinaturas/recorrências cobradas no cartão (Netflix, academia, internet...)
CREATE TABLE IF NOT EXISTS card_recurring (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  card_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  periodicity ENUM('monthly','weekly','yearly') NOT NULL DEFAULT 'monthly',
  charge_day TINYINT UNSIGNED NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_recurring_user (user_id, is_active),
  CONSTRAINT fk_recurring_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_recurring_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_recurring_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Investimentos do usuário (posições da carteira multiativo)
-- asset_class ampliado no módulo de mercados; qty/preço médio são snapshot
-- recalculado a partir de portfolio_transactions (fonte da verdade).
CREATE TABLE IF NOT EXISTS investments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  asset_class ENUM('renda_fixa','acoes','fii','fiagro','etf','bdr','cripto','outro') NOT NULL DEFAULT 'outro',
  symbol VARCHAR(30) NULL,
  quantity DECIMAL(18,8) NOT NULL DEFAULT 0,
  avg_price DECIMAL(14,4) NOT NULL DEFAULT 0,
  current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_inv_user (user_id),
  CONSTRAINT fk_inv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================
-- Módulo de Mercados e Investimentos
-- ==============================================================
-- REGRA: nenhuma cotação é inventada. Toda cotação carrega provedor,
-- timestamp do dado e delay declarado. Cache read-through com TTL por
-- classe de ativo (cripto curto; fundamentalistas longos).

-- Cache de cotações (read-through; fonte única do backend)
CREATE TABLE IF NOT EXISTS market_quotes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(30) NOT NULL,
  class VARCHAR(20) NOT NULL,
  provider VARCHAR(30) NOT NULL,
  price DECIMAL(18,8) NOT NULL,
  change_pct DECIMAL(10,4) NOT NULL DEFAULT 0,
  day_change DECIMAL(18,8) NOT NULL DEFAULT 0,
  day_high DECIMAL(18,8) NULL,
  day_low DECIMAL(18,8) NULL,
  day_open DECIMAL(18,8) NULL,
  prev_close DECIMAL(18,8) NULL,
  volume DECIMAL(24,4) NULL,
  volume_money DECIMAL(24,4) NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'BRL',
  market VARCHAR(20) NULL,
  delay_seconds INT NOT NULL DEFAULT 0,
  quote_time BIGINT NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_stale TINYINT(1) NOT NULL DEFAULT 0,
  extra LONGTEXT NULL,
  UNIQUE KEY uq_quote_symbol (symbol),
  KEY idx_quote_class (class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache de payloads (histórico/candles, notícias, fundamentalistas)
CREATE TABLE IF NOT EXISTS market_history_cache (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cache_key VARCHAR(150) NOT NULL,
  payload LONGTEXT NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cache_key (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Watchlists do usuário (múltiplas, ordenáveis)
CREATE TABLE IF NOT EXISTS market_watchlists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_wl_user (user_id),
  CONSTRAINT fk_wl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_watchlist_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  watchlist_id INT UNSIGNED NOT NULL,
  symbol VARCHAR(30) NOT NULL,
  class VARCHAR(20) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wl_item (watchlist_id, symbol),
  CONSTRAINT fk_wl_item FOREIGN KEY (watchlist_id) REFERENCES market_watchlists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alertas de preço (acima/abaixo/variação %)
CREATE TABLE IF NOT EXISTS market_alerts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  symbol VARCHAR(30) NOT NULL,
  class VARCHAR(20) NOT NULL,
  type ENUM('above','below','pct_up','pct_down') NOT NULL,
  target DECIMAL(18,8) NOT NULL,
  status ENUM('active','triggered','cancelled') NOT NULL DEFAULT 'active',
  note VARCHAR(255) NULL,
  trigger_price DECIMAL(18,8) NULL,
  last_notified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  triggered_at DATETIME NULL,
  KEY idx_alert_user (user_id, status),
  KEY idx_alert_symbol (symbol),
  CONSTRAINT fk_alert_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transações da carteira (compra/venda com taxas) — fonte da verdade do preço médio
CREATE TABLE IF NOT EXISTS portfolio_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  symbol VARCHAR(30) NOT NULL,
  class VARCHAR(20) NOT NULL DEFAULT 'acoes',
  type ENUM('buy','sell') NOT NULL,
  quantity DECIMAL(18,8) NOT NULL,
  price DECIMAL(18,8) NOT NULL,
  fees DECIMAL(18,8) NOT NULL DEFAULT 0,
  date DATE NOT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ptx_user (user_id, symbol, date),
  CONSTRAINT fk_ptx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dividendos e proventos recebidos (histórico)
CREATE TABLE IF NOT EXISTS portfolio_dividends (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  symbol VARCHAR(30) NOT NULL,
  type ENUM('dividend','jcp','rendimento_fii','juros','outro') NOT NULL DEFAULT 'dividend',
  amount DECIMAL(14,2) NOT NULL,
  per_unit DECIMAL(14,6) NULL,
  ex_date DATE NULL,
  pay_date DATE NOT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pdiv_user (user_id, pay_date),
  CONSTRAINT fk_pdiv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Patrimônio: ativos e passivos (fase 2)
CREATE TABLE IF NOT EXISTS assets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  category ENUM('cash','investment','vehicle','property','other') NOT NULL DEFAULT 'other',
  value DECIMAL(14,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assets_user (user_id),
  CONSTRAINT fk_assets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS liabilities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind ENUM('debt','financing','loan','card','other') NOT NULL DEFAULT 'other',
  value DECIMAL(14,2) NOT NULL,
  monthly_payment DECIMAL(12,2) NULL,
  due_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_liabilities_user (user_id),
  CONSTRAINT fk_liabilities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- Compromissos e Contas Fixas (dívidas pessoais + contas recorrentes)
-- kind='debt': tem total_amount (saldo devedor). kind='recurring': sem total.
-- O status de atraso/em dia é DERIVADO das ocorrências (não persistido).
-- --------------------------------------------------------------
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

-- Ocorrências de pagamento: 1 por vencimento (UNIQUE evita duplicatas)
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

-- Histórico de pagamentos (fonte da verdade; link opcional com transactions)
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

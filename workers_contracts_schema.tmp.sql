-- Esquema base para fichas de trabajadores y contratos.
-- Patrón de archivo esperado:
-- APELLIDO_NOMBRE_TIPO_FECHAINICIO_FECHAFIN_DEPARTAMENTO_CATEGORIA_CODIGOINSS.pdf
-- Ejemplo:
-- GARCIA_JUAN_402_01-01-2025_31-12-2025_COCINA_AYTE_123456789.pdf

CREATE TABLE IF NOT EXISTS workers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(120) NOT NULL,
  last_name VARCHAR(120) NOT NULL,
  full_name VARCHAR(255) NOT NULL,
  inss_code VARCHAR(32) DEFAULT NULL,
  sensitive_notes TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_workers_full_name_inss (full_name, inss_code),
  KEY idx_workers_full_name (full_name),
  KEY idx_workers_inss (inss_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contracts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  worker_id INT UNSIGNED NOT NULL,

  -- Campos de compatibilidad (dashboard actual)
  contract_code VARCHAR(64) DEFAULT NULL,
  worker_name VARCHAR(255) DEFAULT NULL,

  -- Campos funcionales por patrón de archivo
  contract_type VARCHAR(40) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE DEFAULT NULL,
  department VARCHAR(120) DEFAULT NULL,
  category VARCHAR(120) DEFAULT NULL,
  inss_code VARCHAR(32) DEFAULT NULL,
  status ENUM('active', 'ended', 'inactive') NOT NULL DEFAULT 'active',

  -- Referencias al archivo real
  source_base VARCHAR(50) DEFAULT NULL,
  pdf_relpath VARCHAR(500) DEFAULT NULL,
  source_filename VARCHAR(255) DEFAULT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_contracts_worker (worker_id),
  KEY idx_contracts_type (contract_type),
  KEY idx_contracts_status (status),
  KEY idx_contracts_end_date (end_date),
  KEY idx_contracts_dept_cat (department, category),
  KEY idx_contracts_inss (inss_code),
  CONSTRAINT fk_contracts_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contract_movements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contract_id INT UNSIGNED DEFAULT NULL,
  contract_code VARCHAR(64) DEFAULT NULL,
  worker_name VARCHAR(255) DEFAULT NULL,
  movement_type VARCHAR(50) NOT NULL,
  movement_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_movements_date (movement_date),
  KEY idx_movements_type (movement_type),
  KEY idx_movements_contract (contract_id),
  CONSTRAINT fk_movements_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Opcional para bajas reales por mes de usuarios del portal.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS deactivated_at DATETIME NULL AFTER active;

-- Queries usadas por workers.php
-- 1) Listado filtrado por trabajador
-- SELECT w.id, w.full_name, w.inss_code, COUNT(c.id) AS contracts_count
-- FROM workers w
-- LEFT JOIN contracts c ON c.worker_id = w.id
-- WHERE w.full_name LIKE '%juan%'
-- GROUP BY w.id, w.full_name, w.inss_code
-- ORDER BY w.full_name ASC;
--
-- 2) Ficha trabajador
-- SELECT id, first_name, last_name, full_name, inss_code, sensitive_notes, created_at
-- FROM workers
-- WHERE id = ?;
--
-- 3) Contratos de trabajador
-- SELECT contract_type, start_date, end_date, department, category, status, source_filename, source_base, pdf_relpath
-- FROM contracts
-- WHERE worker_id = ?
-- ORDER BY start_date DESC, id DESC;

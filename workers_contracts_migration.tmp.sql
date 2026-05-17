-- Ejecuta este script si ya tenías una tabla contracts previa
-- (por ejemplo creada por dashboard_schema.sql) y faltan columnas.

ALTER TABLE contracts
  ADD COLUMN IF NOT EXISTS worker_id INT UNSIGNED NULL AFTER id,
  ADD COLUMN IF NOT EXISTS worker_name VARCHAR(255) NULL AFTER contract_code,
  ADD COLUMN IF NOT EXISTS contract_type VARCHAR(40) NULL AFTER worker_name,
  ADD COLUMN IF NOT EXISTS start_date DATE NULL AFTER contract_type,
  ADD COLUMN IF NOT EXISTS end_date DATE NULL AFTER start_date,
  ADD COLUMN IF NOT EXISTS department VARCHAR(120) NULL AFTER end_date,
  ADD COLUMN IF NOT EXISTS category VARCHAR(120) NULL AFTER department,
  ADD COLUMN IF NOT EXISTS inss_code VARCHAR(32) NULL AFTER category,
  ADD COLUMN IF NOT EXISTS status ENUM('active', 'ended', 'inactive') NOT NULL DEFAULT 'active' AFTER inss_code,
  ADD COLUMN IF NOT EXISTS source_base VARCHAR(50) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS pdf_relpath VARCHAR(500) NULL AFTER source_base,
  ADD COLUMN IF NOT EXISTS source_filename VARCHAR(255) NULL AFTER pdf_relpath;

ALTER TABLE contracts
  ADD KEY IF NOT EXISTS idx_contracts_worker (worker_id),
  ADD KEY IF NOT EXISTS idx_contracts_type (contract_type),
  ADD KEY IF NOT EXISTS idx_contracts_status (status),
  ADD KEY IF NOT EXISTS idx_contracts_end_date (end_date),
  ADD KEY IF NOT EXISTS idx_contracts_dept_cat (department, category),
  ADD KEY IF NOT EXISTS idx_contracts_inss (inss_code);

-- FK opcional (ejecútala solo si no existe ya):
-- ALTER TABLE contracts
--   ADD CONSTRAINT fk_contracts_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE;

ALTER TABLE contracts
  MODIFY COLUMN contract_type VARCHAR(40) NULL;

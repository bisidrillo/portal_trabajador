-- Tablas mínimas recomendadas para dashboard de contratos.
-- Ejecutar en la base de datos portal-trabajadores.

CREATE TABLE IF NOT EXISTS contracts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contract_code VARCHAR(64) NOT NULL UNIQUE,
  worker_name VARCHAR(190) NOT NULL,
  contract_type VARCHAR(40) NOT NULL,
  department VARCHAR(120) DEFAULT NULL,
  role_name VARCHAR(190) DEFAULT NULL,
  start_date DATE NOT NULL,
  end_date DATE DEFAULT NULL,
  status ENUM('active', 'inactive', 'ended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_status (status),
  KEY idx_end_date (end_date),
  KEY idx_worker_name (worker_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contract_movements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contract_code VARCHAR(64) DEFAULT NULL,
  worker_name VARCHAR(190) DEFAULT NULL,
  movement_type VARCHAR(50) NOT NULL,
  movement_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_movement_date (movement_date),
  KEY idx_movement_type (movement_type),
  KEY idx_contract_code (contract_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Opcional para bajas reales por mes:
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS deactivated_at DATETIME NULL AFTER active;

-- Queries del dashboard:
-- 1) Altas del mes
-- SELECT COUNT(*) FROM users
-- WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
--   AND created_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH);
--
-- 2) Bajas del mes (si existe users.deactivated_at)
-- SELECT COUNT(*) FROM users
-- WHERE active = 0
--   AND deactivated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
--   AND deactivated_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH);
--
-- 3) Contratos activos
-- SELECT COUNT(*) FROM contracts WHERE status = 'active';
--
-- 4) Vencen en 30 días
-- SELECT COUNT(*) FROM contracts
-- WHERE status = 'active'
--   AND end_date IS NOT NULL
--   AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY);
--
-- 5) Últimos movimientos
-- SELECT movement_type, contract_code, worker_name, movement_date
-- FROM contract_movements
-- ORDER BY movement_date DESC, id DESC
-- LIMIT 20;

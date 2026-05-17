-- Campos derivados del motor centralizado de reglas de contratos.
-- Revisar y ejecutar manualmente en MariaDB cuando se apruebe la migracion.

ALTER TABLE contracts
  ADD COLUMN IF NOT EXISTS es_sustitucion TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN IF NOT EXISTS persona_sustituida VARCHAR(255) NULL AFTER es_sustitucion,
  ADD COLUMN IF NOT EXISTS es_prorroga TINYINT(1) NOT NULL DEFAULT 0 AFTER persona_sustituida,
  ADD COLUMN IF NOT EXISTS numero_prorroga INT UNSIGNED NULL AFTER es_prorroga,
  ADD COLUMN IF NOT EXISTS codigo_inss_base VARCHAR(32) NULL AFTER numero_prorroga,
  ADD COLUMN IF NOT EXISTS es_conversion TINYINT(1) NOT NULL DEFAULT 0 AFTER codigo_inss_base,
  ADD COLUMN IF NOT EXISTS es_indefinido TINYINT(1) NOT NULL DEFAULT 0 AFTER es_conversion,
  ADD COLUMN IF NOT EXISTS modalidad VARCHAR(50) NULL AFTER es_indefinido;

ALTER TABLE contracts
  ADD KEY IF NOT EXISTS idx_contracts_sustitucion (es_sustitucion),
  ADD KEY IF NOT EXISTS idx_contracts_prorroga (es_prorroga),
  ADD KEY IF NOT EXISTS idx_contracts_conversion (es_conversion),
  ADD KEY IF NOT EXISTS idx_contracts_codigo_base (codigo_inss_base);

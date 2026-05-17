-- Verificacion posterior a importacion de reglas laborales.
-- Ejecutar en phpMyAdmin sobre la base `portal-trabajadores`.

-- 1) Sustituciones: quien sustituyo a quien.
SELECT
  worker_name AS sustituto,
  persona_sustituida,
  contract_type,
  start_date,
  end_date,
  source_filename
FROM contracts
WHERE es_sustitucion = 1
ORDER BY start_date DESC, worker_name ASC
LIMIT 50;

-- 2) Sustituciones sospechosas: deben tener persona_sustituida y end_date NULL.
SELECT
  id,
  worker_name,
  persona_sustituida,
  contract_type,
  start_date,
  end_date,
  source_filename
FROM contracts
WHERE contract_type IN ('410', '510')
  AND (
    es_sustitucion <> 1
    OR persona_sustituida IS NULL
    OR TRIM(persona_sustituida) = ''
    OR end_date IS NOT NULL
  )
ORDER BY start_date DESC, worker_name ASC
LIMIT 100;

-- 3) Prorrogas detectadas por sufijo -01 o -1.
SELECT
  worker_name,
  contract_type,
  start_date,
  inss_code,
  codigo_inss_base,
  numero_prorroga,
  source_filename
FROM contracts
WHERE es_prorroga = 1
ORDER BY start_date DESC, worker_name ASC
LIMIT 50;

-- 4) Conversiones a indefinido.
SELECT
  worker_name,
  contract_type,
  start_date,
  es_indefinido,
  modalidad,
  source_filename
FROM contracts
WHERE es_conversion = 1
ORDER BY start_date DESC, worker_name ASC
LIMIT 50;

-- 5) Conversiones sospechosas: tipos 189/289/389 deben estar marcados.
SELECT
  id,
  worker_name,
  contract_type,
  start_date,
  es_conversion,
  es_indefinido,
  modalidad,
  source_filename
FROM contracts
WHERE contract_type IN ('189', '289', '389')
  AND (es_conversion <> 1 OR es_indefinido <> 1 OR (contract_type = '389' AND (modalidad IS NULL OR modalidad <> 'fijo_discontinuo')))
ORDER BY start_date DESC, worker_name ASC
LIMIT 100;

-- 6) Resumen rapido.
SELECT
  COUNT(*) AS total_contracts,
  SUM(es_sustitucion = 1) AS sustituciones,
  SUM(es_prorroga = 1) AS prorrogas,
  SUM(es_conversion = 1) AS conversiones,
  SUM(es_indefinido = 1) AS indefinidos_por_conversion
FROM contracts;

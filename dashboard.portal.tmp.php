<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/includes/layout.php";
require_once __DIR__ . "/includes/contract_utils.php";

function table_exists(PDO $pdo, string $table): bool
{
    $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $sql = "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function format_date_es(?string $date): string
{
    $raw = trim((string)$date);
    if ($raw === "" || $raw === "0000-00-00") {
        return "-";
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }
    return date("d/m/Y", $ts);
}

function contract_is_currently_active(array $contract, ?string $today = null): bool
{
    $status = trim((string)($contract["status"] ?? ""));
    $startDate = trim((string)($contract["start_date"] ?? ""));
    $endDate = trim((string)($contract["end_date"] ?? ""));
    $contractType = trim((string)($contract["contract_type"] ?? ""));
    $today = $today !== null ? trim($today) : date("Y-m-d");

    if ($status !== "active") {
        return false;
    }
    if ($startDate === "" || $startDate === "0000-00-00" || $startDate > $today) {
        return false;
    }
    if ($startDate < date("Y-m-d", strtotime($today . " -24 months"))) {
        return false;
    }
    if ($endDate !== "" && $endDate !== "0000-00-00") {
        return $endDate >= $today;
    }

    if (in_array($contractType, ["410", "510"], true)) {
        return true;
    }

    if (preg_match('/^\d+$/', $contractType)) {
        return (int)$contractType > 402;
    }

    return false;
}

function guess_missing_department_reason(array $contract): string
{
    $filename = (string)($contract["source_filename"] ?? "");
    if ($filename === "") {
        return "Sin source_filename en base de datos.";
    }

    $base = preg_replace('/\.pdf$/i', '', $filename);
    $tokens = array_values(array_filter(array_map("trim", explode("_", (string)$base)), static function (string $v): bool {
        return $v !== "";
    }));
    if (!$tokens) {
        return "Nombre de archivo vacío o sin separadores válidos.";
    }

    $typeIdx = null;
    foreach ($tokens as $i => $t) {
        if (preg_match('/^\d{3,4}$/', $t)) {
            $typeIdx = $i;
            break;
        }
    }
    if ($typeIdx === null) {
        return "No se detecta tipo de contrato (token 3-4 dígitos).";
    }

    $afterType = array_slice($tokens, $typeIdx + 1);
    if (!$afterType) {
        return "No hay tokens después del tipo para extraer departamento.";
    }

    $hasDate = false;
    foreach ($afterType as $token) {
        if (contract_parse_date_iso((string)$token) !== null) {
            $hasDate = true;
            break;
        }
    }
    if (!$hasDate) {
        return "No se detecta fecha en el nombre del archivo.";
    }

    return "Patrón ambiguo: revisar orden de campos en el nombre del PDF.";
}

function canonical_department_options(): array
{
    return [
        "DIRECCION",
        "COCINA",
        "RESTAURANTE",
        "PISOS",
        "ECONOMATO",
        "SPA",
        "SSTT",
        "RECEPCION",
    ];
}

function normalize_department_label(string $raw, bool $allowSinDepartamento = false): ?string
{
    $value = strtoupper(trim($raw));
    if ($value === "") {
        return null;
    }

    $aliases = [
        "RESTURANTE" => "RESTAURANTE",
        "RESRTAURANTE" => "RESTAURANTE",
        "RESTAURANTES" => "RESTAURANTE",
        "RECEPCION HOTEL" => "RECEPCION",
        "SERVICIOS TECNICOS" => "SSTT",
        "SERVICIO TECNICO" => "SSTT",
        "SERV TECNICOS" => "SSTT",
        "SS TT" => "SSTT",
        "SIN_DEPARTAMENTO" => "SIN DEPARTAMENTO",
    ];

    if (isset($aliases[$value])) {
        $value = $aliases[$value];
    }

    if ($allowSinDepartamento && $value === "SIN DEPARTAMENTO") {
        return $value;
    }

    return in_array($value, canonical_department_options(), true) ? $value : null;
}

function safe_path_segment(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[\/:\\\\]+/', '-', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function filename_date_token(?string $date): string
{
    $raw = trim((string)$date);
    if ($raw === "" || $raw === "0000-00-00") {
        return "";
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return "";
    }
    return date("d-m-Y", $ts);
}

function placeholder_date_token(): string
{
    return "XX-XX-XXXX";
}

function normalize_filename_date_piece(string $token): ?string
{
    $token = trim($token);
    if ($token === "") {
        return null;
    }
    if (preg_match('/^\d{2}[:.]\d{2}[:.]\d{2,4}$/', $token)) {
        $token = str_replace([':', '.'], '-', $token);
    }
    if (preg_match('/^(\d{2}[-:.]\d{2}[-:.]\d{2,4})[-_](\d{2}[-:.]\d{2}[-:.]\d{2,4})$/', $token, $m)) {
        return null;
    }
    $dt = contract_parse_date($token);
    return $dt ? $dt->format("d-m-Y") : null;
}

function extract_filename_date_tokens(string $filename): array
{
    $base = preg_replace('/\.pdf$/i', '', trim($filename));
    if (!is_string($base) || $base === "") {
        return [];
    }

    $parts = array_values(array_filter(array_map("trim", preg_split('/_+/u', $base) ?: []), static function (string $v): bool {
        return $v !== "";
    }));

    $dates = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if (preg_match('/^(\d{2}[-:.]\d{2}[-:.]\d{2,4})[-_](\d{2}[-:.]\d{2}[-:.]\d{2,4})$/', $part, $m)) {
            foreach ([(string)$m[1], (string)$m[2]] as $piece) {
                $normalized = normalize_filename_date_piece($piece);
                if ($normalized !== null) {
                    $dates[] = $normalized;
                }
            }
            continue;
        }

        $normalized = normalize_filename_date_piece($part);
        if ($normalized !== null) {
            $dates[] = $normalized;
        }
    }

    return array_values(array_unique($dates));
}

function looks_like_contract_unique_code(string $token): bool
{
    $token = trim($token);
    if ($token === "") {
        return false;
    }
    return (bool)preg_match('/^(?=.*\d)[A-Za-z0-9-]{5,}$/', $token);
}

function extract_unique_code_from_filename(string $filename, string $contractType = ""): string
{
    $base = preg_replace('/\.pdf$/i', '', trim($filename));
    if (!is_string($base) || $base === "") {
        return "";
    }

    $parts = array_values(array_filter(array_map("trim", preg_split('/_+/u', $base) ?: []), static function (string $v): bool {
        return $v !== "";
    }));

    for ($i = count($parts) - 1; $i >= 0; $i--) {
        $candidate = $parts[$i];
        if ($contractType !== "" && preg_match('/^' . preg_quote($contractType, '/') . '[- ]?([A-Za-z0-9]{5,})$/iu', $candidate, $m)) {
            return trim((string)$m[1]);
        }
        if (looks_like_contract_unique_code($candidate) && contract_parse_date($candidate) === null) {
            return $candidate;
        }
    }

    if (preg_match('/(?:^|[_-])(\d{5,12})(?:$|[_-])/u', $base, $m)) {
        return trim((string)$m[1]);
    }

    return "";
}

function resolve_contract_source_root(string $sourceBase): ?string
{
    $roots = require __DIR__ . "/config.php";
    $root = trim((string)($roots[$sourceBase] ?? ""));
    if ($root === "" || !is_dir($root)) {
        return null;
    }
    return rtrim(str_replace("\\", "/", $root), "/");
}

function build_contract_filename(array $contract, string $departmentLabel): string
{
    $workerName = safe_path_segment((string)($contract["worker_name"] ?? ""));
    $contractType = safe_path_segment((string)($contract["contract_type"] ?? ""));
    $department = safe_path_segment($departmentLabel);
    $category = safe_path_segment((string)($contract["category"] ?? ""));
    $filenameDates = extract_filename_date_tokens((string)($contract["source_filename"] ?? ""));
    $startDate = $filenameDates[0] ?? "";
    $endDate = $filenameDates[1] ?? "";

    if ($startDate === "") {
        $startDate = placeholder_date_token();
    }
    if ($endDate === "" && trim((string)($contract["end_date"] ?? "")) !== "") {
        $endDate = placeholder_date_token();
    }

    if ($workerName === "" || $contractType === "" || $department === "") {
        throw new RuntimeException("Faltan datos para reconstruir el nombre del PDF.");
    }

    $tokens = [$workerName, $contractType, $department];
    if ($category !== "") {
        $tokens[] = $category;
    }
    $tokens[] = $startDate;
    if ($endDate !== "") {
        $tokens[] = $endDate;
    }

    $uniqueCode = trim((string)($contract["inss_code"] ?? ""));
    if ($uniqueCode === "") {
        $uniqueCode = extract_unique_code_from_filename((string)($contract["source_filename"] ?? ""), $contractType);
    }
    if ($uniqueCode !== "") {
        $tokens[] = safe_path_segment($uniqueCode);
    }

    return implode("_", array_values(array_filter($tokens, static function (string $v): bool {
        return trim($v) !== "";
    }))) . ".pdf";
}

function build_unique_target_path(string $directory, string $filename, string $currentPath): string
{
    $target = rtrim($directory, "/") . "/" . $filename;
    if ($target === $currentPath || !file_exists($target)) {
        return $target;
    }

    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $counter = 1;
    do {
        $candidate = rtrim($directory, "/") . "/" . $name . "_" . $counter . ($ext !== "" ? "." . $ext : "");
        $counter++;
    } while (file_exists($candidate) && $candidate !== $currentPath);

    return $candidate;
}

function log_contract_movement(PDO $pdo, array $contract, string $movementType, string $notes): void
{
    if (!table_exists($pdo, "contract_movements")) {
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO contract_movements (contract_code, worker_name, movement_type, notes)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([
        (string)($contract["contract_code"] ?? ""),
        (string)($contract["worker_name"] ?? ""),
        $movementType,
        mb_substr($notes, 0, 255),
    ]);
}

function split_worker_name_for_storage(string $fullName): array
{
    $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName);
    if ($fullName === "") {
        return ["", ""];
    }

    $parts = preg_split('/\s+/', $fullName) ?: [];
    if (count($parts) <= 1) {
        return [$fullName, ""];
    }
    if (count($parts) === 2) {
        return [(string)$parts[0], (string)$parts[1]];
    }

    $lastName = implode(" ", array_slice($parts, -2));
    $firstName = implode(" ", array_slice($parts, 0, -2));
    return [trim($firstName), trim($lastName)];
}

function upsert_dashboard_worker(PDO $pdo, string $fullName, ?string $inssCode = null): int
{
    $fullName = trim($fullName);
    $inssCode = trim((string)$inssCode);

    $stmt = $pdo->prepare("SELECT id FROM workers WHERE full_name = ? LIMIT 1");
    $stmt->execute([$fullName]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }

    [$firstName, $lastName] = split_worker_name_for_storage($fullName);
    $stmt = $pdo->prepare(
        "INSERT INTO workers (first_name, last_name, full_name, inss_code)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$firstName, $lastName, $fullName, $inssCode !== "" ? $inssCode : null]);
    return (int)$pdo->lastInsertId();
}

function relocate_contract_pdf(PDO $pdo, array $contract, string $departmentLabel): array
{
    $sourceBase = trim((string)($contract["source_base"] ?? ""));
    $currentRel = ltrim(str_replace("\\", "/", (string)($contract["pdf_relpath"] ?? "")), "/");
    $sourceFilename = trim((string)($contract["source_filename"] ?? ""));
    $contractType = safe_path_segment((string)($contract["contract_type"] ?? ""));

    if ($sourceBase === "" || $currentRel === "" || $sourceFilename === "" || $contractType === "") {
        throw new RuntimeException("El contrato no tiene ruta o tipo suficiente para mover el PDF.");
    }

    $root = resolve_contract_source_root($sourceBase);
    if ($root === null) {
        throw new RuntimeException("No se pudo resolver la carpeta origen para '{$sourceBase}'.");
    }

    $currentPath = $root . "/" . $currentRel;
    if (!is_file($currentPath)) {
        throw new RuntimeException("No se encuentra el PDF actual en disco.");
    }
    $oldDepartment = trim((string)($contract["department"] ?? ""));
    $oldFilename = basename($currentPath);

    $departmentFolder = safe_path_segment($departmentLabel !== "" ? $departmentLabel : "SIN DEPARTAMENTO");
    if ($departmentFolder === "") {
        $departmentFolder = "SIN DEPARTAMENTO";
    }

    $targetDir = $root . "/" . $contractType . "/" . $departmentFolder;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        throw new RuntimeException("No se pudo crear la carpeta destino.");
    }

    $targetFilename = build_contract_filename($contract, $departmentFolder);
    $targetPath = build_unique_target_path($targetDir, $targetFilename, $currentPath);

    if ($targetPath !== $currentPath && !rename($currentPath, $targetPath)) {
        throw new RuntimeException("No se pudo mover/renombrar el PDF.");
    }

    $newRel = ltrim(substr(str_replace("\\", "/", $targetPath), strlen($root)), "/");
    $storedDepartment = strtoupper($departmentFolder) === "SIN DEPARTAMENTO" ? "SIN DEPARTAMENTO" : $departmentFolder;

    $stmt = $pdo->prepare(
        "UPDATE contracts
         SET department = ?, pdf_relpath = ?, source_filename = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmt->execute([$storedDepartment, $newRel, basename($targetPath), (int)$contract["id"]]);

    $stateExistsStmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'contract_import_state' LIMIT 1");
    $stateExistsStmt->execute();
    if ((bool)$stateExistsStmt->fetchColumn()) {
        $stateStmt = $pdo->prepare(
            "INSERT INTO contract_import_state
             (source_base, pdf_relpath, source_filename, file_size, file_mtime, last_result, last_error, last_seen_at, last_processed_at)
             VALUES (?, ?, ?, ?, ?, 'parsed', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
               source_filename = VALUES(source_filename),
               file_size = VALUES(file_size),
               file_mtime = VALUES(file_mtime),
               last_result = 'parsed',
               last_error = NULL,
               last_seen_at = CURRENT_TIMESTAMP,
               last_processed_at = CURRENT_TIMESTAMP"
        );
        $stateStmt->execute([
            $sourceBase,
            $newRel,
            basename($targetPath),
            (int)filesize($targetPath),
            (int)filemtime($targetPath),
        ]);
    }

    $notes = "Departamento: '" . ($oldDepartment !== "" ? $oldDepartment : "SIN DEPARTAMENTO")
        . "' -> '" . $storedDepartment
        . "'. Archivo: '" . $oldFilename
        . "' -> '" . basename($targetPath) . "'.";
    log_contract_movement($pdo, $contract, "department_update", $notes);

    return [
        "previous_department" => $oldDepartment,
        "department" => $storedDepartment,
        "previous_filename" => $oldFilename,
        "pdf_relpath" => $newRel,
        "source_filename" => basename($targetPath),
    ];
}

function rename_contract_pdf_worker_name(PDO $pdo, array $contract, string $workerNameNew): array
{
    $sourceBase = trim((string)($contract["source_base"] ?? ""));
    $currentRel = ltrim(str_replace("\\", "/", (string)($contract["pdf_relpath"] ?? "")), "/");
    $sourceFilename = trim((string)($contract["source_filename"] ?? ""));
    $contractType = safe_path_segment((string)($contract["contract_type"] ?? ""));
    $departmentStored = trim((string)($contract["department"] ?? ""));

    if ($sourceBase === "" || $currentRel === "" || $sourceFilename === "" || $contractType === "") {
        throw new RuntimeException("El contrato no tiene ruta o tipo suficiente para renombrar el PDF.");
    }

    $root = resolve_contract_source_root($sourceBase);
    if ($root === null) {
        throw new RuntimeException("No se pudo resolver la carpeta origen para '{$sourceBase}'.");
    }

    $currentPath = $root . "/" . $currentRel;
    if (!is_file($currentPath)) {
        throw new RuntimeException("No se encuentra el PDF actual en disco.");
    }

    $departmentFolder = safe_path_segment($departmentStored !== "" ? $departmentStored : "SIN DEPARTAMENTO");
    if ($departmentFolder === "") {
        $departmentFolder = "SIN DEPARTAMENTO";
    }

    $updatedContract = $contract;
    $updatedContract["worker_name"] = $workerNameNew;
    $targetFilename = build_contract_filename($updatedContract, $departmentFolder);
    $targetDir = dirname($currentPath);
    $targetPath = build_unique_target_path($targetDir, $targetFilename, $currentPath);
    $oldFilename = basename($currentPath);

    if ($targetPath !== $currentPath && !rename($currentPath, $targetPath)) {
        throw new RuntimeException("No se pudo renombrar el PDF.");
    }

    $newRel = ltrim(substr(str_replace("\\", "/", $targetPath), strlen($root)), "/");
    $workerId = upsert_dashboard_worker($pdo, $workerNameNew, (string)($contract["inss_code"] ?? ""));

    $stmt = $pdo->prepare(
        "UPDATE contracts
         SET worker_id = ?, worker_name = ?, pdf_relpath = ?, source_filename = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmt->execute([$workerId, $workerNameNew, $newRel, basename($targetPath), (int)$contract["id"]]);

    $stateExistsStmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'contract_import_state' LIMIT 1");
    $stateExistsStmt->execute();
    if ((bool)$stateExistsStmt->fetchColumn()) {
        $stateStmt = $pdo->prepare(
            "INSERT INTO contract_import_state
             (source_base, pdf_relpath, source_filename, file_size, file_mtime, last_result, last_error, last_seen_at, last_processed_at)
             VALUES (?, ?, ?, ?, ?, 'parsed', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
               source_filename = VALUES(source_filename),
               file_size = VALUES(file_size),
               file_mtime = VALUES(file_mtime),
               last_result = 'parsed',
               last_error = NULL,
               last_seen_at = CURRENT_TIMESTAMP,
               last_processed_at = CURRENT_TIMESTAMP"
        );
        $stateStmt->execute([
            $sourceBase,
            $newRel,
            basename($targetPath),
            (int)filesize($targetPath),
            (int)filemtime($targetPath),
        ]);
    }

    $notes = "Nombre: '" . trim((string)($contract["worker_name"] ?? ""))
        . "' -> '" . $workerNameNew
        . "'. Archivo: '" . $oldFilename
        . "' -> '" . basename($targetPath) . "'.";
    log_contract_movement($pdo, $contract, "worker_name_update", $notes);

    return [
        "previous_worker_name" => trim((string)($contract["worker_name"] ?? "")),
        "worker_name" => $workerNameNew,
        "previous_filename" => $oldFilename,
        "pdf_relpath" => $newRel,
        "source_filename" => basename($targetPath),
    ];
}

function is_suspicious_department_label(string $label): bool
{
    $v = trim($label);
    if ($v === "" || strcasecmp($v, "Sin departamento") === 0) {
        return false;
    }
    if (normalize_department_label($v, true) !== null) {
        return false;
    }
    if (preg_match('/\d/', $v)) {
        return true;
    }
    $words = array_values(array_filter(preg_split('/\s+/', $v) ?: [], static function (string $w): bool {
        return $w !== "";
    }));
    if (count($words) >= 3) {
        return true;
    }
    return false;
}

function month_end_iso(int $year, int $month): string
{
    return (new DateTimeImmutable(sprintf("%04d-%02d-01", $year, $month)))
        ->modify("last day of this month")
        ->format("Y-m-d");
}

function normalize_person_key(string $name): string
{
    $name = trim($name);
    if ($name === "") {
        return "";
    }
    if (function_exists("iconv")) {
        $converted = @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $name);
        if ($converted !== false) {
            $name = $converted;
        }
    }
    $name = function_exists("mb_strtolower") ? mb_strtolower($name, "UTF-8") : strtolower($name);
    $name = preg_replace('/[^a-z0-9 ]+/i', ' ', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    return trim($name);
}

function normalize_rotation_department_label(string $department): string
{
    $dept = normalize_department_label($department, true);
    if ($dept === null) {
        return "SIN_DEPARTAMENTO";
    }
    return $dept;
}

function contract_catalog_reliability(array $contract): array
{
    $workerName = trim((string)($contract["worker_name"] ?? ""));
    $department = trim((string)($contract["department"] ?? ""));
    $startDate = trim((string)($contract["start_date"] ?? ""));
    $endDate = trim((string)($contract["end_date"] ?? ""));
    $contractType = trim((string)($contract["contract_type"] ?? ""));

    $nameValid = $workerName !== ""
        && !preg_match('/\d/', $workerName)
        && count(array_values(array_filter(preg_split('/\s+/', $workerName) ?: []))) >= 2;
    $departmentValid = $department !== "" && !is_suspicious_department_label($department);
    $startValid = $startDate !== "" && (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate);
    $dateCoherent = $startValid && ($endDate === "" || $endDate >= $startDate);
    $typeValid = (bool)preg_match('/^\d{3,4}$/', $contractType);

    $score = 0;
    $score += $nameValid ? 30 : 0;
    $score += $departmentValid ? 25 : 0;
    $score += $startValid ? 20 : 0;
    $score += $dateCoherent ? 15 : 0;
    $score += $typeValid ? 10 : 0;

    return [
        "score" => $score,
        "name_valid" => $nameValid ? 1 : 0,
        "department_valid" => $departmentValid ? 1 : 0,
        "start_valid" => $startValid ? 1 : 0,
        "date_coherent" => $dateCoherent ? 1 : 0,
        "type_valid" => $typeValid ? 1 : 0,
    ];
}

function send_json_response(bool $ok, string $message, array $extra = []): void
{
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(array_merge([
        "ok" => $ok,
        "message" => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function safe_return_target(string $value, string $fallback = "dashboard.php"): string
{
    $value = trim($value);
    if ($value === "") {
        return $fallback;
    }
    if (preg_match('/^[a-z]+:/i', $value)) {
        return $fallback;
    }
    if (str_starts_with($value, "//") || str_starts_with($value, "/")) {
        return $fallback;
    }
    return $value;
}

$kpis = [
    "altas_periodo" => 0,
    "bajas_mes" => 0,
    "contratos_activos" => 0,
    "vencen_30" => 0,
];
$movimientos = [];
$departmentChanges = [];
$departmentEditContracts = [];
$usersStatus = [];
$activeContracts = [];
$expiringContracts = [];
$contractsByDepartment = [];
$rawContractsByDepartment = [];
$missingDepartmentContracts = [];
$suspiciousDepartmentGroups = [];
$departmentOptions = [];
$departmentMaxContracts = 0;
$rotationYear = (int)($_GET["rotation_year"] ?? date("Y"));
if ($rotationYear < 2000 || $rotationYear > 2100) {
    $rotationYear = (int)date("Y");
}
$rotationRows = [];
$rotationGlobal = [
    "altas" => 0,
    "bajas" => 0,
    "plantilla_media" => 0.0,
    "rotacion_pct" => 0.0,
];
$catalogReliability = [
    "pct" => 0.0,
    "records" => 0,
    "name_ok" => 0,
    "department_ok" => 0,
    "start_ok" => 0,
    "dates_ok" => 0,
    "type_ok" => 0,
];
$hiresWindow = (int)($_GET["hires_window"] ?? 3);
if (!in_array($hiresWindow, [3, 6, 9, 12], true)) {
    $hiresWindow = 3;
}
$hiresDept = trim((string)($_GET["hires_dept"] ?? ""));
$hiresDepartments = [];
$hiresByDepartment = [];
$hiresMax = 0;
$warnings = [];
$isAdmin = !empty($_SESSION["is_admin"]);
$statusOk = trim((string)($_GET["ok"] ?? ""));
$statusErr = trim((string)($_GET["err"] ?? ""));
$userQ = trim((string)($_GET["user_q"] ?? ""));
$contractQ = trim((string)($_GET["contract_q"] ?? ""));
$fixQ = trim((string)($_GET["fix_q"] ?? ""));

try {
    $pdo = require __DIR__ . "/db.php";
    $hasDeactivatedAt = column_exists($pdo, "users", "deactivated_at");
    $hasContractsTable = table_exists($pdo, "contracts");
    $hasContractsStatus = $hasContractsTable && column_exists($pdo, "contracts", "status");
    $hasContractMovements = table_exists($pdo, "contract_movements");

    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "set_user_active") {
        if (!$isAdmin) {
            header("Location: dashboard.php?err=" . rawurlencode("No tienes permisos para cambiar el estado."));
            exit;
        }

        $targetId = (int)($_POST["user_id"] ?? 0);
        $newActive = (int)($_POST["active"] ?? -1);
        $selfId = (int)($_SESSION["user_id"] ?? 0);

        if ($targetId <= 0 || ($newActive !== 0 && $newActive !== 1)) {
            header("Location: dashboard.php?err=" . rawurlencode("Solicitud inválida para actualizar estado."));
            exit;
        }

        if ($targetId === $selfId && $newActive === 0) {
            header("Location: dashboard.php?err=" . rawurlencode("No puedes desactivar tu propio usuario."));
            exit;
        }

        if ($hasDeactivatedAt) {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET active = ?,
                     deactivated_at = CASE WHEN ? = 0 THEN NOW() ELSE NULL END
                 WHERE id = ?"
            );
            $stmt->execute([$newActive, $newActive, $targetId]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET active = ? WHERE id = ?");
            $stmt->execute([$newActive, $targetId]);
        }

        if ($stmt->rowCount() > 0) {
            $msg = $newActive === 1 ? "Trabajador marcado como activo." : "Trabajador marcado como inactivo.";
            header("Location: dashboard.php?ok=" . rawurlencode($msg));
            exit;
        }

        header("Location: dashboard.php?err=" . rawurlencode("No se pudo actualizar el estado del trabajador."));
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "set_contract_status") {
        if (!$isAdmin) {
            header("Location: dashboard.php?err=" . rawurlencode("No tienes permisos para cambiar el estado del contrato."));
            exit;
        }
        if (!$hasContractsStatus) {
            header("Location: dashboard.php?err=" . rawurlencode("No existe contracts.status para corregir contratos."));
            exit;
        }

        $contractId = (int)($_POST["contract_id"] ?? 0);
        $newStatus = trim((string)($_POST["new_status"] ?? ""));
        $allowedStatuses = ["active", "inactive", "ended"];
        $queryParams = [];
        $postedContractQ = trim((string)($_POST["contract_q"] ?? ""));
        $postedUserQ = trim((string)($_POST["user_q"] ?? ""));
        if ($postedContractQ !== "") {
            $queryParams["contract_q"] = $postedContractQ;
        }
        if ($postedUserQ !== "") {
            $queryParams["user_q"] = $postedUserQ;
        }
        $queryPrefix = http_build_query($queryParams);
        $queryPrefix = $queryPrefix !== "" ? $queryPrefix . "&" : "";

        if ($contractId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            header("Location: dashboard.php?" . $queryPrefix . "err=" . rawurlencode("Solicitud inválida para actualizar contrato."));
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT id, contract_code, worker_name, status
             FROM contracts
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$contractId]);
        $contractRow = $stmt->fetch();
        if (!$contractRow) {
            header("Location: dashboard.php?" . $queryPrefix . "err=" . rawurlencode("Contrato no encontrado."));
            exit;
        }
        if ((string)$contractRow["status"] === $newStatus) {
            header("Location: dashboard.php?" . $queryPrefix . "ok=" . rawurlencode("El contrato ya estaba en ese estado."));
            exit;
        }

        $stmt = $pdo->prepare("UPDATE contracts SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $contractId]);

        if ($hasContractMovements) {
            $stmt = $pdo->prepare(
                "INSERT INTO contract_movements (contract_code, worker_name, movement_type, notes)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                (string)($contractRow["contract_code"] ?? ""),
                (string)($contractRow["worker_name"] ?? ""),
                "status_update",
                "Estado cambiado a " . $newStatus,
            ]);
        }

        header("Location: dashboard.php?" . $queryPrefix . "ok=" . rawurlencode("Contrato actualizado a " . $newStatus . "."));
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "set_contract_department") {
        $isAjax = (string)($_POST["ajax"] ?? "") === "1";
        $returnTo = safe_return_target((string)($_POST["return_to"] ?? ""), "dashboard.php");
        if (!$isAdmin) {
            if ($isAjax) {
                send_json_response(false, "No tienes permisos para corregir departamento.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("No tienes permisos para corregir departamento."));
            exit;
        }
        if (!$hasContractsTable || !column_exists($pdo, "contracts", "department")) {
            if ($isAjax) {
                send_json_response(false, "No existe contracts.department para corregir.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("No existe contracts.department para corregir."));
            exit;
        }
        $contractId = (int)($_POST["contract_id"] ?? 0);
        $departmentNewRaw = trim((string)($_POST["department_new"] ?? ""));
        $departmentNew = normalize_department_label($departmentNewRaw, true);
        if ($contractId <= 0) {
            if ($isAjax) {
                send_json_response(false, "Contrato inválido para corregir departamento.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("Contrato inválido para corregir departamento."));
            exit;
        }
        if ($departmentNew === null) {
            if ($isAjax) {
                send_json_response(false, "Departamento no válido.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("Departamento no válido."));
            exit;
        }
        $stmt = $pdo->prepare(
            "SELECT id, contract_code, worker_name, contract_type, start_date, end_date, category, inss_code, department, source_base, pdf_relpath, source_filename
             FROM contracts
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$contractId]);
        $contractRow = $stmt->fetch();
        if (!$contractRow) {
            if ($isAjax) {
                send_json_response(false, "Contrato no encontrado.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("Contrato no encontrado."));
            exit;
        }
        try {
            $result = relocate_contract_pdf($pdo, $contractRow, $departmentNew);
        } catch (Throwable $eMove) {
            if ($isAjax) {
                send_json_response(false, $eMove->getMessage());
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode($eMove->getMessage()));
            exit;
        }
        if ($isAjax) {
            send_json_response(true, "Departamento actualizado y PDF recolocado.", $result);
        }
        header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "ok=" . rawurlencode("Departamento actualizado y PDF recolocado."));
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "set_contract_department_batch") {
        $isAjax = (string)($_POST["ajax"] ?? "") === "1";
        $returnTo = safe_return_target((string)($_POST["return_to"] ?? ""), "dashboard.php");
        if (!$isAdmin) {
            if ($isAjax) {
                send_json_response(false, "No tienes permisos para corregir departamentos en lote.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("No tienes permisos para corregir departamentos en lote."));
            exit;
        }
        if (!$hasContractsTable || !column_exists($pdo, "contracts", "department") || !column_exists($pdo, "contracts", "worker_name")) {
            if ($isAjax) {
                send_json_response(false, "Faltan columnas para corregir departamentos en lote.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("Faltan columnas para corregir departamentos en lote."));
            exit;
        }

        $workerName = trim((string)($_POST["worker_name"] ?? ""));
        $departmentNewRaw = trim((string)($_POST["department_new"] ?? ""));
        $departmentNew = normalize_department_label($departmentNewRaw, true);
        if ($workerName === "" || $departmentNew === null) {
            if ($isAjax) {
                send_json_response(false, "Datos no válidos para corrección en lote.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("Datos no válidos para corrección en lote."));
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT id, contract_code, worker_name, contract_type, start_date, end_date, category, inss_code, department, source_base, pdf_relpath, source_filename
             FROM contracts
             WHERE TRIM(worker_name) = ?
               AND (department IS NULL OR TRIM(department) = '')
             ORDER BY start_date DESC, id DESC"
        );
        $stmt->execute([$workerName]);
        $contractsToUpdate = $stmt->fetchAll();

        if (!$contractsToUpdate) {
            if ($isAjax) {
                send_json_response(false, "No se encontraron contratos sin departamento para ese trabajador.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("No se encontraron contratos sin departamento para ese trabajador."));
            exit;
        }

        $affected = 0;
        $errorsBatch = [];
        foreach ($contractsToUpdate as $contractRow) {
            try {
                relocate_contract_pdf($pdo, $contractRow, $departmentNew);
                $affected++;
            } catch (Throwable $eMove) {
                if (count($errorsBatch) < 3) {
                    $errorsBatch[] = (string)$eMove->getMessage();
                }
            }
        }

        $message = "Departamento aplicado en lote a {$affected} contrato(s).";
        if ($errorsBatch) {
            $message .= " Errores: " . implode(" | ", $errorsBatch);
        }

        if ($isAjax) {
            send_json_response(true, $message, [
                "affected" => $affected,
                "worker_name" => $workerName,
                "department" => $departmentNew,
            ]);
        }

        header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "ok=" . rawurlencode($message));
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "set_contract_worker_name") {
        $isAjax = (string)($_POST["ajax"] ?? "") === "1";
        $returnTo = safe_return_target((string)($_POST["return_to"] ?? ""), "dashboard.php");
        if (!$isAdmin) {
            if ($isAjax) {
                send_json_response(false, "No tienes permisos para corregir el nombre.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("No tienes permisos para corregir el nombre."));
            exit;
        }
        if (!$hasContractsTable || !column_exists($pdo, "contracts", "worker_name")) {
            if ($isAjax) {
                send_json_response(false, "No existe contracts.worker_name para corregir.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("No existe contracts.worker_name para corregir."));
            exit;
        }

        $contractId = (int)($_POST["contract_id"] ?? 0);
        $workerNameNew = trim((string)($_POST["worker_name_new"] ?? ""));
        $workerNameNew = preg_replace('/\s+/', ' ', $workerNameNew) ?? $workerNameNew;
        if ($contractId <= 0 || $workerNameNew === "") {
            if ($isAjax) {
                send_json_response(false, "Nombre o contrato no válido.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("Nombre o contrato no válido."));
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT id, worker_id, contract_code, worker_name, contract_type, start_date, end_date, category, inss_code, department, source_base, pdf_relpath, source_filename
             FROM contracts
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$contractId]);
        $contractRow = $stmt->fetch();
        if (!$contractRow) {
            if ($isAjax) {
                send_json_response(false, "Contrato no encontrado.");
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode("Contrato no encontrado."));
            exit;
        }

        try {
            $result = rename_contract_pdf_worker_name($pdo, $contractRow, $workerNameNew);
        } catch (Throwable $eMove) {
            if ($isAjax) {
                send_json_response(false, $eMove->getMessage());
            }
            header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "err=" . rawurlencode($eMove->getMessage()));
            exit;
        }

        if ($isAjax) {
            send_json_response(true, "Nombre actualizado y PDF renombrado.", $result);
        }
        header("Location: " . $returnTo . (str_contains($returnTo, "?") ? "&" : "?") . "ok=" . rawurlencode("Nombre actualizado y PDF renombrado."));
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "normalize_department_label") {
        if (!$isAdmin) {
            header("Location: dashboard.php?err=" . rawurlencode("No tienes permisos para normalizar departamentos."));
            exit;
        }
        if (!$hasContractsTable || !column_exists($pdo, "contracts", "department") || !column_exists($pdo, "contracts", "start_date")) {
            header("Location: dashboard.php?err=" . rawurlencode("Faltan columnas para normalizar departamentos."));
            exit;
        }
        $oldDepartment = trim((string)($_POST["old_department"] ?? ""));
        if ($oldDepartment === "") {
            header("Location: dashboard.php?err=" . rawurlencode("Etiqueta de departamento inválida."));
            exit;
        }
        $stmt = $pdo->prepare(
            "SELECT id, contract_code, worker_name, contract_type, start_date, end_date, category, inss_code, department, source_base, pdf_relpath, source_filename
             FROM contracts
             WHERE TRIM(COALESCE(department, '')) = ?
               AND start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)"
        );
        $stmt->execute([$oldDepartment]);
        $contractsToNormalize = $stmt->fetchAll();

        $affected = 0;
        $errorsBatch = [];
        foreach ($contractsToNormalize as $contractRow) {
            try {
                relocate_contract_pdf($pdo, $contractRow, "SIN DEPARTAMENTO");
                $affected++;
            } catch (Throwable $eMove) {
                if (count($errorsBatch) < 3) {
                    $errorsBatch[] = (string)$eMove->getMessage();
                }
            }
        }

        $message = "Normalizados {$affected} contrato(s) de '{$oldDepartment}' a Sin departamento.";
        if ($errorsBatch) {
            $message .= " Errores: " . implode(" | ", $errorsBatch);
        }
        header("Location: dashboard.php?ok=" . rawurlencode($message));
        exit;
    }

    // Bajas del mes: usa deactivated_at si existe; si no, fallback a total de inactivos.
    if ($hasDeactivatedAt) {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM users
             WHERE active = 0
               AND deactivated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND deactivated_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)"
        );
        $kpis["bajas_mes"] = (int)$stmt->fetchColumn();
    } else {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE active = 0");
        $kpis["bajas_mes"] = (int)$stmt->fetchColumn();
        $warnings[] = "Bajas del mes usa total de inactivos (falta users.deactivated_at).";
    }

    if ($hasContractsTable) {
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM contracts
             WHERE status = 'active'
               AND start_date <= CURDATE()
               AND start_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
               AND (
                    (end_date IS NOT NULL AND end_date <> '' AND end_date >= CURDATE())
                    OR (
                        (end_date IS NULL OR end_date = '')
                        AND (
                            contract_type IN ('410', '510')
                            OR (contract_type REGEXP '^[0-9]+$' AND CAST(contract_type AS UNSIGNED) > 402)
                        )
                    )
               )"
        );
        $kpis["contratos_activos"] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query(
            "SELECT id, contract_code, worker_name, contract_type, department, start_date, end_date, status
             FROM contracts
             WHERE status = 'active'
               AND start_date <= CURDATE()
               AND start_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
               AND end_date IS NOT NULL
               AND end_date <> ''
               AND end_date >= CURDATE()
               AND end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY end_date ASC, id ASC
             LIMIT 500"
        );
        $expiringContracts = $stmt->fetchAll();
        $kpis["vencen_30"] = count($expiringContracts);

        $hasDepartment = column_exists($pdo, "contracts", "department");
        $hasStartDate = column_exists($pdo, "contracts", "start_date");
        $hasWorkerName = column_exists($pdo, "contracts", "worker_name");
        $hasEndDate = column_exists($pdo, "contracts", "end_date");
        $hasContractType = column_exists($pdo, "contracts", "contract_type");
        if ($hasDepartment && $hasStartDate) {
            $stmt = $pdo->query(
                "SELECT DISTINCT TRIM(department) AS dep
                 FROM contracts
                 WHERE department IS NOT NULL AND TRIM(department) <> ''
                 ORDER BY dep ASC"
            );
            $hiresDepartments = array_values(array_filter(array_map(static function ($v): string {
                return trim((string)$v);
            }, $stmt->fetchAll(PDO::FETCH_COLUMN)), static function (string $v): bool {
                return $v !== "";
            }));

            $hiresSql = "
                SELECT COUNT(*)
                FROM contracts
                WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                  AND start_date <= CURDATE()
            ";
            $hiresParams = [$hiresWindow];
            if ($hiresDept !== "") {
                $hiresSql .= " AND TRIM(COALESCE(department, '')) = ?";
                $hiresParams[] = $hiresDept;
            }
            $stmt = $pdo->prepare($hiresSql);
            $stmt->execute($hiresParams);
            $kpis["altas_periodo"] = (int)$stmt->fetchColumn();

            $hiresByDeptSql = "
                SELECT COALESCE(NULLIF(TRIM(department), ''), 'Sin departamento') AS department_name,
                       COUNT(*) AS hires_total
                FROM contracts
                WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                  AND start_date <= CURDATE()
            ";
            $hiresByDeptParams = [$hiresWindow];
            if ($hiresDept !== "") {
                $hiresByDeptSql .= " AND TRIM(COALESCE(department, '')) = ?";
                $hiresByDeptParams[] = $hiresDept;
            }
            $hiresByDeptSql .= " GROUP BY department_name ORDER BY hires_total DESC, department_name ASC";
            $stmt = $pdo->prepare($hiresByDeptSql);
            $stmt->execute($hiresByDeptParams);
            $hiresByDepartment = $stmt->fetchAll();
            foreach ($hiresByDepartment as $r) {
                $hiresMax = max($hiresMax, (int)($r["hires_total"] ?? 0));
            }

            $stmt = $pdo->query(
                "SELECT
                    COALESCE(NULLIF(TRIM(department), ''), 'Sin departamento') AS department_name,
                    COUNT(*) AS contracts_total
                 FROM contracts
                 WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY department_name
                 ORDER BY contracts_total DESC, department_name ASC
                 LIMIT 20"
            );
            $rawContractsByDepartment = $stmt->fetchAll();

            $reviewBucket = 0;
            $cleanDepartments = [];
            foreach ($rawContractsByDepartment as $row) {
                $label = trim((string)($row["department_name"] ?? ""));
                $count = (int)($row["contracts_total"] ?? 0);
                if ($label === "") {
                    continue;
                }
                if (strcasecmp($label, "Sin departamento") === 0 || is_suspicious_department_label($label)) {
                    $reviewBucket += $count;
                    if (strcasecmp($label, "Sin departamento") !== 0) {
                        $suspiciousDepartmentGroups[] = [
                            "department_name" => $label,
                            "contracts_total" => $count,
                        ];
                    }
                    continue;
                }
                $cleanDepartments[] = [
                    "department_name" => $label,
                    "contracts_total" => $count,
                ];
            }

            if ($reviewBucket > 0) {
                $contractsByDepartment[] = [
                    "department_name" => "Sin departamento (revisar)",
                    "contracts_total" => $reviewBucket,
                ];
            }
            $contractsByDepartment = array_merge($contractsByDepartment, $cleanDepartments);

            foreach ($contractsByDepartment as $row) {
                $departmentMaxContracts = max($departmentMaxContracts, (int)($row["contracts_total"] ?? 0));
            }

            $departmentOptions = canonical_department_options();

            $stmt = $pdo->query(
                "SELECT id, worker_name, contract_type, start_date, end_date, status, source_filename, source_base, pdf_relpath
                 FROM contracts
                 WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                   AND (department IS NULL OR TRIM(department) = '')
                 ORDER BY start_date DESC, id DESC
                 LIMIT 120"
            );
            $missingDepartmentContracts = $stmt->fetchAll();

            if ($fixQ !== "") {
                $like = "%" . $fixQ . "%";
                $stmt = $pdo->prepare(
                    "SELECT id, contract_code, worker_name, contract_type, start_date, end_date, department, source_filename
                     FROM contracts
                     WHERE worker_name LIKE ?
                        OR source_filename LIKE ?
                        OR contract_code LIKE ?
                     ORDER BY start_date DESC, id DESC
                     LIMIT 80"
                );
                $stmt->execute([$like, $like, $like]);
                $departmentEditContracts = $stmt->fetchAll();
            }
        }

        if ($hasStartDate && $hasWorkerName && $hasEndDate && $hasContractType) {
            $yearStart = sprintf("%04d-01-01", $rotationYear);
            $yearEnd = sprintf("%04d-12-31", $rotationYear);
            $stmt = $pdo->prepare(
                "SELECT id, worker_name, contract_type, start_date, end_date, department, source_filename
                 FROM contracts
                 WHERE start_date <= ?
                   AND (end_date IS NULL OR end_date >= ?)"
            );
            $stmt->execute([$yearEnd, $yearStart]);
            $rotationContractsRaw = $stmt->fetchAll();

            $unique = [];
            foreach ($rotationContractsRaw as $c) {
                $dept = normalize_rotation_department_label((string)($c["department"] ?? ""));
                $key = implode("|", [
                    normalize_person_key((string)($c["worker_name"] ?? "")),
                    trim((string)($c["contract_type"] ?? "")),
                    trim((string)($c["start_date"] ?? "")),
                    trim((string)($c["end_date"] ?? "")),
                    $dept,
                ]);
                if (!isset($unique[$key])) {
                    $c["_dept"] = $dept;
                    $personKey = normalize_person_key((string)($c["worker_name"] ?? ""));
                    $c["_person_key"] = $personKey !== "" ? $personKey : ("contract_" . (int)$c["id"]);
                    $unique[$key] = $c;
                }
            }
            $rotationContracts = array_values($unique);

            $catalogScoreTotal = 0;
            foreach ($rotationContracts as $c) {
                $rel = contract_catalog_reliability($c);
                $catalogScoreTotal += (int)$rel["score"];
                $catalogReliability["records"]++;
                $catalogReliability["name_ok"] += (int)$rel["name_valid"];
                $catalogReliability["department_ok"] += (int)$rel["department_valid"];
                $catalogReliability["start_ok"] += (int)$rel["start_valid"];
                $catalogReliability["dates_ok"] += (int)$rel["date_coherent"];
                $catalogReliability["type_ok"] += (int)$rel["type_valid"];
            }
            if ((int)$catalogReliability["records"] > 0) {
                $catalogReliability["pct"] = ($catalogScoreTotal / ((int)$catalogReliability["records"] * 100)) * 100;
            }

            $departments = [];
            $altas = [];
            $bajas = [];
            $headcountByMonth = [];
            for ($m = 1; $m <= 12; $m++) {
                $headcountByMonth[sprintf("%02d", $m)] = [];
            }

            foreach ($rotationContracts as $c) {
                $dept = (string)$c["_dept"];
                $departments[$dept] = true;
                $start = trim((string)($c["start_date"] ?? ""));
                $end = trim((string)($c["end_date"] ?? ""));

                if ($start !== "" && (int)substr($start, 0, 4) === $rotationYear) {
                    $altas[$dept] = ($altas[$dept] ?? 0) + 1;
                }
                if ($end !== "" && (int)substr($end, 0, 4) === $rotationYear) {
                    $bajas[$dept] = ($bajas[$dept] ?? 0) + 1;
                }

                for ($m = 1; $m <= 12; $m++) {
                    $monthKey = sprintf("%02d", $m);
                    $ref = month_end_iso($rotationYear, $m);
                    if ($start === "" || $start > $ref) {
                        continue;
                    }
                    if ($end !== "" && $end < $ref) {
                        continue;
                    }
                    if (!isset($headcountByMonth[$monthKey][$dept])) {
                        $headcountByMonth[$monthKey][$dept] = [];
                    }
                    $headcountByMonth[$monthKey][$dept][(string)$c["_person_key"]] = true;
                }
            }

            foreach (array_keys($departments) as $dept) {
                $sumHeadcount = 0.0;
                for ($m = 1; $m <= 12; $m++) {
                    $monthKey = sprintf("%02d", $m);
                    $sumHeadcount += isset($headcountByMonth[$monthKey][$dept])
                        ? count($headcountByMonth[$monthKey][$dept])
                        : 0;
                }
                $plantillaMedia = $sumHeadcount / 12.0;
                $a = (int)($altas[$dept] ?? 0);
                $b = (int)($bajas[$dept] ?? 0);
                $rot = $plantillaMedia > 0 ? ((($a + $b) / 2.0) / $plantillaMedia) * 100.0 : 0.0;
                $rotationRows[] = [
                    "departamento" => $dept,
                    "altas" => $a,
                    "bajas" => $b,
                    "plantilla_media" => $plantillaMedia,
                    "rotacion_pct" => $rot,
                ];
            }
            usort($rotationRows, static function (array $a, array $b): int {
                return $b["rotacion_pct"] <=> $a["rotacion_pct"];
            });

            $sumAltas = 0;
            $sumBajas = 0;
            $sumPlantilla = 0.0;
            foreach ($rotationRows as $r) {
                $sumAltas += (int)$r["altas"];
                $sumBajas += (int)$r["bajas"];
                $sumPlantilla += (float)$r["plantilla_media"];
            }
            $rotationGlobal["altas"] = $sumAltas;
            $rotationGlobal["bajas"] = $sumBajas;
            $rotationGlobal["plantilla_media"] = $sumPlantilla;
            $rotationGlobal["rotacion_pct"] = $sumPlantilla > 0 ? ((($sumAltas + $sumBajas) / 2.0) / $sumPlantilla) * 100.0 : 0.0;
        }
    } else {
        $warnings[] = "Falta tabla contracts (métricas de contratos en 0).";
    }

    if ($hasContractMovements) {
        $stmt = $pdo->query(
            "SELECT movement_type AS tipo,
                    contract_code AS referencia,
                    worker_name AS trabajador,
                    movement_date AS fecha,
                    notes
             FROM contract_movements
             ORDER BY movement_date DESC, id DESC
             LIMIT 20"
        );
        $movimientos = $stmt->fetchAll();

        $stmt = $pdo->query(
            "SELECT movement_date AS fecha,
                    contract_code AS referencia,
                    worker_name AS trabajador,
                    notes
             FROM contract_movements
             WHERE movement_type = 'department_update'
             ORDER BY movement_date DESC, id DESC
             LIMIT 30"
        );
        $departmentChanges = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query(
            "(SELECT 'alta_usuario' AS tipo, username AS referencia, full_name AS trabajador, created_at AS fecha FROM users)
             UNION ALL
             (SELECT 'codigo_invitacion' AS tipo, code AS referencia, '' AS trabajador, created_at AS fecha FROM invite_codes)
             ORDER BY fecha DESC
             LIMIT 20"
        );
        $movimientos = $stmt->fetchAll();
        $warnings[] = "Últimos movimientos está en modo parcial (sin contract_movements).";
    }

    if ($hasContractsStatus) {
        $contractsSql = "
            SELECT id, contract_code, worker_name, contract_type, start_date, end_date, department, status
            FROM contracts
            WHERE status = 'active'
              AND start_date <= CURDATE()
              AND start_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
              AND (
                    (end_date IS NOT NULL AND end_date <> '' AND end_date >= CURDATE())
                    OR (
                        (end_date IS NULL OR end_date = '')
                        AND (
                            contract_type IN ('410', '510')
                            OR (contract_type REGEXP '^[0-9]+$' AND CAST(contract_type AS UNSIGNED) > 402)
                        )
                    )
              )
        ";
        $contractsParams = [];
        if ($contractQ !== "") {
            $contractsSql .= " AND (worker_name LIKE ? OR contract_code LIKE ?)";
            $like = "%" . $contractQ . "%";
            $contractsParams = [$like, $like];
        }
        $contractsSql .= " ORDER BY worker_name ASC, start_date DESC, id DESC LIMIT 300";
        $stmt = $pdo->prepare($contractsSql);
        $stmt->execute($contractsParams);
        $activeContracts = $stmt->fetchAll();
    }

    $usersSql = "SELECT id, username, full_name, email, active, created_at, " .
        ($hasDeactivatedAt ? "deactivated_at" : "NULL AS deactivated_at") .
        " FROM users";
    $usersParams = [];
    if ($userQ !== "") {
        $usersSql .= " WHERE full_name LIKE ? OR username LIKE ? OR email LIKE ?";
        $like = "%" . $userQ . "%";
        $usersParams = [$like, $like, $like];
    }
    $usersSql .= " ORDER BY active DESC, full_name ASC LIMIT 200";
    $stmt = $pdo->prepare($usersSql);
    $stmt->execute($usersParams);
    $usersStatus = $stmt->fetchAll();
} catch (Throwable $e) {
    $warnings[] = "No se pudo cargar el dashboard desde la base de datos.";
}

render_layout_start("Dashboard - Portal del trabajador", [
    "mode" => "app",
    "active" => "dashboard",
    "page_title" => "Dashboard",
    "page_subtitle" => "Resumen rápido del portal",
]);
?>
<style>
  .dashboard-shell{display:grid;gap:14px;}
  .kpi-grid { display:grid; gap:14px; grid-template-columns:repeat(1,minmax(0,1fr)); margin-bottom:14px; }
  .kpi-value { font-family:"Space Grotesk",sans-serif; font-size:40px; font-weight:700; line-height:.95; letter-spacing:-.04em; margin:6px 0 8px 0; }
  .kpi-label { color:var(--muted); font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
  .kpi-card-primary{background:linear-gradient(155deg,rgba(255,255,255,.88),rgba(231,246,255,.78));}
  .kpi-card-compact{min-height:190px;display:flex;flex-direction:column;justify-content:space-between;}
  .kpi-hires-wrap{display:grid;grid-template-columns:minmax(150px,1fr) minmax(220px,1.3fr);gap:12px;align-items:center;}
  .kpi-hires-mini{display:grid;gap:4px;}
  .kpi-hires-mini-row{display:grid;grid-template-columns:minmax(95px,1fr) minmax(80px,2fr) auto;gap:4px;align-items:center;}
  .kpi-hires-mini-label{font-size:9px;font-weight:700;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .kpi-hires-mini-track{height:6px;border-radius:999px;background:#dbeafe;overflow:hidden;}
  .kpi-hires-mini-fill{height:100%;background:linear-gradient(90deg,#2563eb,#06b6d4);border-radius:999px;}
  .kpi-hires-mini-value{font-size:9px;font-weight:700;color:#1f2937;white-space:nowrap;}
  .kpi-link-card{width:100%;height:100%;text-align:left;background:transparent;border:0;padding:0;cursor:pointer;color:inherit;font:inherit;display:flex;flex-direction:column;justify-content:space-between;}
  .kpi-link-card:focus-visible{outline:3px solid rgba(37,99,235,.35);outline-offset:3px;border-radius:16px;}
  .dept-chart-row{display:grid;grid-template-columns:minmax(180px,1.2fr) minmax(120px,3fr) auto;gap:6px;align-items:center;margin:4px 0;}
  .dept-chart-label{font-size:11px;font-weight:600;line-height:1.1;}
  .dept-chart-track{width:100%;height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;}
  .dept-chart-fill{height:100%;background:linear-gradient(90deg,#2563eb,#0ea5e9);border-radius:999px;}
  .dept-chart-value{font-size:10px;font-weight:700;color:#334155;white-space:nowrap;}
    .dept-chart-grid{display:grid;grid-template-columns:1fr;gap:0;}
  .hires-row{display:grid;grid-template-columns:minmax(180px,1.2fr) minmax(120px,3fr) auto;gap:6px;align-items:center;margin:4px 0;}
  .hires-track{width:100%;height:7px;background:#e5e7eb;border-radius:999px;overflow:hidden;}
  .hires-fill{height:100%;background:linear-gradient(90deg,#0ea5e9,#2563eb);border-radius:999px;}
  .hires-value{font-size:10px;font-weight:700;color:#334155;white-space:nowrap;}
  .suspicious-badge{display:inline-block;padding:1px 6px;border-radius:999px;font-size:9px;font-weight:700;background:#fff7ed;color:#9a3412;border:1px solid #fdba74;}
  .inline-feedback{padding:10px 12px;border-radius:14px;font-size:12px;margin-bottom:8px;}
  .inline-feedback.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
  .inline-feedback.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
  .rotation-table th,.rotation-table td{white-space:nowrap;}
  .reliability-chip{display:inline-flex;align-items:center;padding:3px 6px;border-radius:999px;font-size:10px;font-weight:700;}
  .reliability-chip.high{background:#dcfce7;color:#166534;border:1px solid #86efac;}
  .reliability-chip.mid{background:#fffbeb;color:#92400e;border:1px solid #fcd34d;}
  .reliability-chip.low{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
  .dashboard-grid{display:grid;grid-template-columns:1fr;gap:14px;}
  .dashboard-span-2{grid-column:span 1;}
  .dashboard-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  @media (max-width: 900px){
    .dept-chart-row{grid-template-columns:1fr;}
    .dept-chart-value{margin-top:2px;}
    .hires-row{grid-template-columns:1fr;}
    .hires-value{margin-top:2px;}
    .kpi-hires-wrap{grid-template-columns:1fr;}
  }
  .contracts-table{width:100%;table-layout:fixed;}
  .contracts-table th,.contracts-table td{word-break:normal;white-space:normal;vertical-align:middle;}
  .contracts-table .col-worker{width:31%;}
  .contracts-table .col-type{width:8%;text-align:center;white-space:nowrap;}
  .contracts-table .col-date{width:11%;white-space:nowrap;}
  .contracts-table .col-dept{width:16%;}
  .contracts-table .col-status{width:10%;white-space:nowrap;}
  .contracts-table .col-actions{width:24%;}
  .status-badge{display:inline-flex;align-items:center;padding:3px 6px;border-radius:999px;font-size:10px;font-weight:700;letter-spacing:.02em;text-transform:uppercase;}
  .status-badge.active{background:#dcfce7;color:#166534;border:1px solid #86efac;}
  .status-badge.inactive{background:#fef3c7;color:#92400e;border:1px solid #fcd34d;}
  .status-badge.ended{background:#e5e7eb;color:#374151;border:1px solid #d1d5db;}
  .action-stack{display:flex;gap:4px;flex-wrap:wrap;}
  .btn-mini{padding:5px 8px;border-radius:8px;font-size:10px;font-weight:700;}
  .btn-danger{background:#fff1f2;color:#be123c;border:1px solid #fecdd3;box-shadow:none;}
  .btn-warn{background:#fffbeb;color:#b45309;border:1px solid #fde68a;box-shadow:none;}
  @media (max-width: 1200px) {
    .contracts-table th,.contracts-table td{padding:5px 6px;font-size:11px;}
    .contracts-table .col-worker{width:30%;}
    .contracts-table .col-type{width:8%;}
    .contracts-table .col-date{width:11%;}
    .contracts-table .col-dept{width:15%;}
    .contracts-table .col-status{width:10%;}
    .contracts-table .col-actions{width:26%;}
    .action-stack{flex-direction:column;}
    .action-stack .btn{width:100%;}
  }
  @media (max-width: 900px) {
    .contracts-table .col-dept{display:none;}
  }
  @media (min-width: 720px) { .kpi-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .dashboard-grid{grid-template-columns:repeat(2,minmax(0,1fr));} .dashboard-span-2{grid-column:span 2;} }
  @media (min-width: 1100px) { .kpi-grid { grid-template-columns:repeat(6,minmax(0,1fr)); } .kpi-grid .kpi-span-2{grid-column:span 2;} .dashboard-grid{grid-template-columns:repeat(6,minmax(0,1fr));} .dashboard-span-2{grid-column:span 2;} .dashboard-span-3{grid-column:span 3;} .dashboard-span-4{grid-column:span 4;} .dashboard-span-6{grid-column:span 6;} }
</style>

<div class="container wide dashboard-shell" x-data="{ showDeptChart: false, showRotation: false, showExpiring: false }">
  <?php if ($statusOk !== ""): ?>
    <div class="card"><div class="success"><?= htmlspecialchars($statusOk) ?></div></div>
  <?php endif; ?>
  <?php if ($statusErr !== ""): ?>
    <div class="card"><div class="error"><?= htmlspecialchars($statusErr) ?></div></div>
  <?php endif; ?>

  <?php foreach ($warnings as $w): ?>
    <div class="card"><div class="error"><?= htmlspecialchars($w) ?></div></div>
  <?php endforeach; ?>

  <div class="kpi-grid">
    <div class="card kpi-card-primary kpi-span-2">
      <div class="kpi-label">Altas (últimos <?= (int)$hiresWindow ?> mes<?= $hiresWindow === 1 ? "" : "es" ?>)</div>
      <div class="kpi-hires-wrap">
        <div>
          <div class="kpi-value"><?= (int)$kpis["altas_periodo"] ?></div>
          <div class="muted" style="font-size:12px;">por `start_date` en el periodo</div>
          <a href="altas_analytics.php?window=<?= (int)$hiresWindow ?>&department=<?= rawurlencode($hiresDept) ?>" style="display:inline-block;margin-top:8px;color:#2563eb;font-weight:700;font-size:13px;">Abrir analítica completa</a>
        </div>
        <div class="kpi-hires-mini">
          <?php if (!$hiresByDepartment): ?>
            <div class="muted" style="font-size:12px;">Sin altas por departamento.</div>
          <?php else: ?>
            <?php foreach (array_slice($hiresByDepartment, 0, 4) as $r): ?>
              <?php
                $hcount = (int)($r["hires_total"] ?? 0);
                $hpct = $hiresMax > 0 ? (int)round(($hcount / $hiresMax) * 100) : 0;
              ?>
              <div class="kpi-hires-mini-row">
                <div class="kpi-hires-mini-label"><?= htmlspecialchars((string)$r["department_name"]) ?></div>
                <div class="kpi-hires-mini-track"><div class="kpi-hires-mini-fill" style="width: <?= max(2, $hpct) ?>%;"></div></div>
                <div class="kpi-hires-mini-value"><?= $hcount ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="card kpi-card-compact">
      <div class="kpi-label">Bajas del mes</div>
      <div class="kpi-value"><?= (int)$kpis["bajas_mes"] ?></div>
    </div>
    <div class="card kpi-card-compact">
      <button type="button" class="kpi-link-card" @click="showDeptChart = !showDeptChart" title="Ver gráfica por departamento del último año">
        <div class="kpi-label">Contratos activos</div>
        <div class="kpi-value"><?= (int)$kpis["contratos_activos"] ?></div>
        <div class="muted" style="font-size:12px;">Pulsa para ver gráfica por departamento</div>
      </button>
    </div>
    <div class="card kpi-card-compact">
      <button type="button" class="kpi-link-card" @click="showExpiring = !showExpiring" title="Ver contratos que vencen en los próximos 30 días">
        <div class="kpi-label">Vencen en 30 días</div>
        <div class="kpi-value"><?= (int)$kpis["vencen_30"] ?></div>
        <div class="muted" style="font-size:12px;">Pulsa para ver el detalle</div>
      </button>
    </div>
    <div class="card kpi-card-compact">
      <button type="button" class="kpi-link-card" @click="showRotation = !showRotation" title="Ver rotación por departamento y fiabilidad del catálogo">
        <div class="kpi-label">Fiabilidad catálogo</div>
        <div class="kpi-value"><?= number_format((float)$catalogReliability["pct"], 1, ",", ".") ?>%</div>
        <div class="muted" style="font-size:12px;">Pulsa para ver rotación + calidad</div>
      </button>
    </div>
  </div>

  <div class="dashboard-grid">
  <div class="card dashboard-span-3" x-show="showExpiring" x-cloak>
    <h2 style="margin:0 0 8px 0;">Contratos que vencen en los próximos 30 días</h2>
    <p class="muted" style="margin:0 0 12px 0;">
      Regla usada: estado <strong>active</strong> + <strong>end_date</strong> entre hoy y los próximos 30 días.
    </p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Vence</th>
            <th>Trabajador</th>
            <th>Tipo</th>
            <th>Departamento</th>
            <th>Código</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$expiringContracts): ?>
            <tr><td colspan="5">No hay contratos con vencimiento en los próximos 30 días.</td></tr>
          <?php else: ?>
            <?php foreach ($expiringContracts as $c): ?>
              <tr>
                <td><?= htmlspecialchars(format_date_es((string)($c["end_date"] ?? ""))) ?></td>
                <td><?= htmlspecialchars((string)($c["worker_name"] ?? "")) ?></td>
                <td><?= htmlspecialchars((string)($c["contract_type"] ?? "")) ?></td>
                <td><?= htmlspecialchars((string)($c["department"] ?? "")) ?></td>
                <td><?= htmlspecialchars((string)($c["contract_code"] ?? "")) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card dashboard-span-3" x-show="showRotation" x-cloak>
    <h2 style="margin:0 0 8px 0;">Rotación por departamento (<?= (int)$rotationYear ?>)</h2>
    <form method="get" class="search" style="margin-bottom:12px;">
      <input type="number" min="2000" max="2100" name="rotation_year" value="<?= (int)$rotationYear ?>" placeholder="Año">
      <?php if ($userQ !== ""): ?>
        <input type="hidden" name="user_q" value="<?= htmlspecialchars($userQ) ?>">
      <?php endif; ?>
      <?php if ($contractQ !== ""): ?>
        <input type="hidden" name="contract_q" value="<?= htmlspecialchars($contractQ) ?>">
      <?php endif; ?>
      <button class="btn" type="submit">Recalcular</button>
    </form>
    <?php
      $reliabilityClass = "low";
      if ((float)$catalogReliability["pct"] >= 85.0) {
          $reliabilityClass = "high";
      } elseif ((float)$catalogReliability["pct"] >= 70.0) {
          $reliabilityClass = "mid";
      }
    ?>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 12px 0;">
      <span class="reliability-chip <?= $reliabilityClass ?>">
        Fiabilidad del proyecto: <?= number_format((float)$catalogReliability["pct"], 1, ",", ".") ?>%
      </span>
      <span class="muted" style="font-size:13px;">
        Base: <?= (int)$catalogReliability["records"] ?> contratos evaluados (nombres, departamentos, fechas y tipo).
      </span>
    </div>

    <div class="card card-flat" style="margin-bottom:12px;">
      <div><strong>Altas:</strong> <?= (int)$rotationGlobal["altas"] ?></div>
      <div><strong>Bajas:</strong> <?= (int)$rotationGlobal["bajas"] ?></div>
      <div><strong>Plantilla media:</strong> <?= number_format((float)$rotationGlobal["plantilla_media"], 2, ",", ".") ?></div>
      <div><strong>Rotación global:</strong> <?= number_format((float)$rotationGlobal["rotacion_pct"], 2, ",", ".") ?>%</div>
    </div>

    <div class="table-wrap">
      <table class="rotation-table">
        <thead>
          <tr>
            <th>Departamento</th>
            <th>Altas</th>
            <th>Bajas</th>
            <th>Plantilla media</th>
            <th>Rotación %</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rotationRows): ?>
            <tr><td colspan="5">Sin datos de rotación para el año seleccionado.</td></tr>
          <?php else: ?>
            <?php foreach ($rotationRows as $r): ?>
              <tr>
                <td><?= htmlspecialchars((string)$r["departamento"]) ?></td>
                <td><?= (int)$r["altas"] ?></td>
                <td><?= (int)$r["bajas"] ?></td>
                <td><?= number_format((float)$r["plantilla_media"], 2, ",", ".") ?></td>
                <td><?= number_format((float)$r["rotacion_pct"], 2, ",", ".") ?>%</td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="muted" style="margin-top:12px;font-size:13px;">
      Cálculo: <strong>altas</strong> (start_date en el año), <strong>bajas</strong> (end_date en el año),
      <strong>plantilla media</strong> (promedio de plantilla activa al cierre de los 12 meses),
      <strong>rotación %</strong> = ((altas + bajas) / 2) / plantilla_media * 100.
    </div>
  </div>

  <div class="card dashboard-span-6" x-show="showDeptChart" x-cloak>
    <h2 style="margin:0 0 6px 0;">Contratos por departamento (últimos 12 meses)</h2>
    <p class="muted" style="margin:0 0 12px 0;">Basado en la fecha de inicio (`start_date`) y agrupado por departamento.</p>
    <?php if (!$contractsByDepartment): ?>
      <div class="muted">No hay datos suficientes para construir la gráfica.</div>
    <?php else: ?>
      <div class="dept-chart-grid">
        <?php foreach ($contractsByDepartment as $row): ?>
          <?php
            $count = (int)($row["contracts_total"] ?? 0);
            $pct = $departmentMaxContracts > 0 ? (int)round(($count / $departmentMaxContracts) * 100) : 0;
          ?>
          <div class="dept-chart-row">
            <div class="dept-chart-label"><?= htmlspecialchars((string)$row["department_name"]) ?></div>
            <div class="dept-chart-track">
              <div class="dept-chart-fill" style="width: <?= max(2, $pct) ?>%;"></div>
            </div>
            <div class="dept-chart-value"><?= $count ?> contrato<?= $count === 1 ? "" : "s" ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div style="margin-top:18px;">
      <h3 style="margin:0 0 8px 0;">Etiquetas sospechosas en departamento</h3>
      <p class="muted" style="margin:0 0 10px 0;">Valores que parecen nombres o códigos. Puedes normalizarlos a “Sin departamento”.</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Etiqueta</th>
              <th>Cantidad</th>
              <?php if ($isAdmin): ?>
                <th>Acción</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$suspiciousDepartmentGroups): ?>
              <tr><td colspan="<?= $isAdmin ? "3" : "2" ?>">No hay etiquetas sospechosas detectadas.</td></tr>
            <?php else: ?>
              <?php foreach ($suspiciousDepartmentGroups as $row): ?>
                <tr>
                  <td>
                    <?= htmlspecialchars((string)$row["department_name"]) ?>
                    <span class="suspicious-badge">Sospechoso</span>
                  </td>
                  <td><?= (int)$row["contracts_total"] ?></td>
                  <?php if ($isAdmin): ?>
                    <td>
                      <form method="post" style="margin:0;">
                        <input type="hidden" name="action" value="normalize_department_label">
                        <input type="hidden" name="old_department" value="<?= htmlspecialchars((string)$row["department_name"]) ?>">
                        <button class="btn btn-ghost" type="submit">Pasar a Sin departamento</button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div style="margin-top:18px;">
      <h3 style="margin:0 0 8px 0;">Contratos sin departamento (para revisión)</h3>
      <p class="muted" style="margin:0 0 10px 0;">Se listan contratos del último año con departamento vacío para detectar por qué no se ha clasificado.</p>
      <div id="dept-inline-feedback" style="display:none;"></div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Trabajador</th>
              <th>Tipo</th>
              <th>Inicio</th>
              <th>Fin</th>
              <th>Estado</th>
              <th>Archivo</th>
              <th>Posible motivo</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$missingDepartmentContracts): ?>
              <tr><td colspan="7">No hay contratos sin departamento en los últimos 12 meses.</td></tr>
            <?php else: ?>
              <?php foreach ($missingDepartmentContracts as $row): ?>
                <tr data-contract-row-id="<?= (int)$row["id"] ?>">
                  <td><?= htmlspecialchars((string)($row["worker_name"] ?? "")) ?></td>
                  <td><?= htmlspecialchars((string)($row["contract_type"] ?? "")) ?></td>
                  <td><?= htmlspecialchars(format_date_es((string)($row["start_date"] ?? ""))) ?></td>
                  <td><?= htmlspecialchars(format_date_es((string)($row["end_date"] ?? ""))) ?></td>
                  <td><?= htmlspecialchars((string)($row["status"] ?? "")) ?></td>
                  <td><?= htmlspecialchars((string)($row["source_filename"] ?? "")) ?></td>
                  <td>
                    <div><?= htmlspecialchars(guess_missing_department_reason($row)) ?></div>
                    <?php if ($isAdmin): ?>
                      <form method="post" class="search js-dept-update-form" style="margin-top:8px;gap:6px;">
                        <input type="hidden" name="action" value="set_contract_department">
                        <input type="hidden" name="contract_id" value="<?= (int)$row["id"] ?>">
                        <select name="department_new" required>
                          <option value="">Selecciona departamento</option>
                          <?php foreach ($departmentOptions as $dep): ?>
                            <option value="<?= htmlspecialchars((string)$dep) ?>"><?= htmlspecialchars((string)$dep) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button class="btn btn-ghost" type="submit">Guardar</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <script>
    document.addEventListener("DOMContentLoaded", function () {
      const forms = document.querySelectorAll(".js-dept-update-form");
      const feedback = document.getElementById("dept-inline-feedback");
      if (!forms.length || !feedback) {
        return;
      }

      const setFeedback = (ok, message) => {
        feedback.className = ok ? "inline-feedback ok" : "inline-feedback err";
        feedback.textContent = message;
        feedback.style.display = "block";
      };

      forms.forEach((form) => {
        form.addEventListener("submit", async (ev) => {
          ev.preventDefault();
          const submitBtn = form.querySelector("button[type='submit']");
          if (submitBtn) {
            submitBtn.disabled = true;
          }
          try {
            const fd = new FormData(form);
            fd.set("ajax", "1");
            const res = await fetch("dashboard.php", {
              method: "POST",
              body: fd,
              headers: { "X-Requested-With": "XMLHttpRequest" },
            });
            const data = await res.json();
            if (!data || !data.ok) {
              setFeedback(false, (data && data.message) ? data.message : "No se pudo actualizar el departamento.");
              return;
            }
            setFeedback(true, data.message || "Departamento actualizado.");
            const row = form.closest("tr");
            if (row) {
              row.remove();
            }
          } catch (e) {
            setFeedback(false, "Error de red al actualizar el departamento.");
          } finally {
            if (submitBtn) {
              submitBtn.disabled = false;
            }
          }
        });
      });
    });
  </script>

  <div class="card dashboard-span-3">
    <h2 style="margin:0 0 12px 0;">Corrección rápida de departamento</h2>
    <form method="get" class="search" style="margin-bottom:12px;">
      <input name="fix_q" value="<?= htmlspecialchars($fixQ) ?>" placeholder="Buscar por trabajador, código o nombre de archivo">
      <?php if ($userQ !== ""): ?>
        <input type="hidden" name="user_q" value="<?= htmlspecialchars($userQ) ?>">
      <?php endif; ?>
      <?php if ($contractQ !== ""): ?>
        <input type="hidden" name="contract_q" value="<?= htmlspecialchars($contractQ) ?>">
      <?php endif; ?>
      <button class="btn" type="submit">Buscar</button>
      <a href="dashboard.php<?= $userQ !== "" || $contractQ !== "" ? ("?" . http_build_query(array_filter(["user_q" => $userQ, "contract_q" => $contractQ], static fn($v): bool => $v !== ""))) : "" ?>" class="btn btn-ghost">Limpiar</a>
    </form>
    <?php if ($fixQ !== ""): ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Trabajador</th>
              <th>Tipo</th>
              <th>Inicio</th>
              <th>Archivo</th>
              <th>Departamento</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$departmentEditContracts): ?>
              <tr><td colspan="6">Sin resultados</td></tr>
            <?php else: ?>
              <?php foreach ($departmentEditContracts as $c): ?>
                <tr>
                  <td><?= htmlspecialchars((string)($c["worker_name"] ?? "")) ?></td>
                  <td><?= htmlspecialchars((string)($c["contract_type"] ?? "")) ?></td>
                  <td><?= htmlspecialchars(format_date_es((string)($c["start_date"] ?? ""))) ?></td>
                  <td><?= htmlspecialchars((string)($c["source_filename"] ?? "")) ?></td>
                  <td>
                    <form method="post" action="dashboard.php" class="search" style="margin:0;gap:4px;align-items:center;">
                      <input type="hidden" name="action" value="set_contract_department">
                      <input type="hidden" name="contract_id" value="<?= (int)$c["id"] ?>">
                      <input type="hidden" name="return_to" value="<?= htmlspecialchars('dashboard.php?fix_q=' . rawurlencode($fixQ)) ?>">
                      <select name="department_new" required style="min-width:160px;">
                        <?php foreach ($departmentOptions as $dep): ?>
                          <option value="<?= htmlspecialchars($dep) ?>" <?= ((string)($c["department"] ?? "") === $dep) ? "selected" : "" ?>><?= htmlspecialchars($dep) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-ghost" type="submit">Guardar</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="muted">Busca un trabajador o archivo para corregir el departamento desde aquí.</div>
    <?php endif; ?>
  </div>

  <div class="card dashboard-span-3">
    <h2 style="margin:0 0 12px 0;">Verificación de contratos activos</h2>
    <form method="get" class="search" style="margin-bottom:12px;">
      <input name="contract_q" value="<?= htmlspecialchars($contractQ) ?>" placeholder="Buscar por trabajador o código de contrato">
      <?php if ($userQ !== ""): ?>
        <input type="hidden" name="user_q" value="<?= htmlspecialchars($userQ) ?>">
      <?php endif; ?>
      <button class="btn" type="submit">Buscar</button>
      <a href="dashboard.php<?= $userQ !== "" ? ("?user_q=" . rawurlencode($userQ)) : "" ?>" class="btn btn-ghost">Limpiar</a>
    </form>
    <div class="table-wrap">
      <table class="contracts-table">
        <thead>
          <tr>
            <th class="col-worker">Trabajador</th>
            <th class="col-type">Tipo</th>
            <th class="col-date">Inicio</th>
            <th class="col-date">Fin</th>
            <th class="col-dept">Departamento</th>
            <th class="col-status">Estado</th>
            <?php if ($isAdmin): ?>
              <th class="col-actions">Correccion</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$activeContracts): ?>
            <tr><td colspan="<?= $isAdmin ? "7" : "6" ?>">Sin contratos activos para revisar</td></tr>
          <?php else: ?>
            <?php foreach ($activeContracts as $c): ?>
              <?php $isCurrentlyActive = contract_is_currently_active($c); ?>
              <tr>
                <td class="col-worker"><?= htmlspecialchars((string)($c["worker_name"] ?? "")) ?></td>
                <td class="col-type"><?= htmlspecialchars((string)($c["contract_type"] ?? "")) ?></td>
                <td class="col-date"><?= htmlspecialchars(format_date_es((string)($c["start_date"] ?? ""))) ?></td>
                <td class="col-date"><?= htmlspecialchars(format_date_es((string)($c["end_date"] ?? ""))) ?></td>
                <td class="col-dept"><?= htmlspecialchars((string)($c["department"] ?? "")) ?></td>
                <td class="col-status">
                  <span class="status-badge <?= $isCurrentlyActive ? "active" : "inactive" ?>">
                    <?= $isCurrentlyActive ? "Activo" : "No activo" ?>
                  </span>
                </td>
                <?php if ($isAdmin): ?>
                  <td class="col-actions">
                    <div class="action-stack">
                      <form method="post" style="margin:0;">
                        <input type="hidden" name="action" value="set_contract_status">
                        <input type="hidden" name="contract_id" value="<?= (int)$c["id"] ?>">
                        <input type="hidden" name="new_status" value="inactive">
                        <input type="hidden" name="contract_q" value="<?= htmlspecialchars($contractQ) ?>">
                        <input type="hidden" name="user_q" value="<?= htmlspecialchars($userQ) ?>">
                        <button class="btn btn-mini btn-warn" type="submit">Marcar inactivo</button>
                      </form>
                      <form method="post" style="margin:0;">
                        <input type="hidden" name="action" value="set_contract_status">
                        <input type="hidden" name="contract_id" value="<?= (int)$c["id"] ?>">
                        <input type="hidden" name="new_status" value="ended">
                        <input type="hidden" name="contract_q" value="<?= htmlspecialchars($contractQ) ?>">
                        <input type="hidden" name="user_q" value="<?= htmlspecialchars($userQ) ?>">
                        <button class="btn btn-mini btn-danger" type="submit">Marcar finalizado</button>
                      </form>
                    </div>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card dashboard-span-3">
    <h2 style="margin:0 0 12px 0;">Estado de trabajadores (activo/inactivo)</h2>
    <form method="get" class="search" style="margin-bottom:12px;">
      <input name="user_q" value="<?= htmlspecialchars($userQ) ?>" placeholder="Buscar por nombre, usuario o email">
      <button class="btn" type="submit">Buscar</button>
      <a href="dashboard.php" class="btn btn-ghost">Limpiar</a>
    </form>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Trabajador</th>
            <th>Usuario</th>
            <th>Email</th>
            <th>Estado</th>
            <th>Fecha alta</th>
            <th>Baja</th>
            <?php if ($isAdmin): ?>
              <th>Acción</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$usersStatus): ?>
            <tr><td colspan="<?= $isAdmin ? "7" : "6" ?>">Sin trabajadores</td></tr>
          <?php else: ?>
            <?php foreach ($usersStatus as $u): ?>
              <?php $isActive = (int)$u["active"] === 1; ?>
              <tr>
                <td><?= htmlspecialchars((string)$u["full_name"]) ?></td>
                <td><?= htmlspecialchars((string)$u["username"]) ?></td>
                <td><?= htmlspecialchars((string)$u["email"]) ?></td>
                <td><?= $isActive ? "Activo" : "Inactivo" ?></td>
                <td><?= htmlspecialchars((string)$u["created_at"]) ?></td>
                <td><?= htmlspecialchars((string)($u["deactivated_at"] ?? "")) ?></td>
                <?php if ($isAdmin): ?>
                  <td>
                    <?php if ((int)$u["id"] === (int)($_SESSION["user_id"] ?? 0)): ?>
                      <span class="muted">Tu usuario</span>
                    <?php else: ?>
                      <form method="post" style="margin:0;">
                        <input type="hidden" name="action" value="set_user_active">
                        <input type="hidden" name="user_id" value="<?= (int)$u["id"] ?>">
                        <input type="hidden" name="active" value="<?= $isActive ? "0" : "1" ?>">
                        <button class="btn btn-ghost" type="submit">
                          <?= $isActive ? "Marcar inactivo" : "Marcar activo" ?>
                        </button>
                      </form>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card dashboard-span-3">
    <h2 style="margin:0 0 12px 0;">Últimos movimientos</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Tipo</th>
            <th>Referencia</th>
            <th>Trabajador</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$movimientos): ?>
            <tr><td colspan="4">Sin movimientos</td></tr>
          <?php else: ?>
            <?php foreach ($movimientos as $m): ?>
              <tr>
                <td><?= htmlspecialchars((string)$m["fecha"]) ?></td>
                <td><?= htmlspecialchars((string)$m["tipo"]) ?></td>
                <td><?= htmlspecialchars((string)$m["referencia"]) ?></td>
                <td><?= htmlspecialchars((string)($m["trabajador"] ?? "")) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card dashboard-span-6">
    <h2 style="margin:0 0 12px 0;">Últimos cambios de departamento</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Referencia</th>
            <th>Trabajador</th>
            <th>Detalle</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$departmentChanges): ?>
            <tr><td colspan="4">Sin cambios registrados</td></tr>
          <?php else: ?>
            <?php foreach ($departmentChanges as $m): ?>
              <tr>
                <td><?= htmlspecialchars((string)$m["fecha"]) ?></td>
                <td><?= htmlspecialchars((string)($m["referencia"] ?? "")) ?></td>
                <td><?= htmlspecialchars((string)($m["trabajador"] ?? "")) ?></td>
                <td><?= htmlspecialchars((string)($m["notes"] ?? "")) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php render_layout_end(["mode" => "app"]); ?>

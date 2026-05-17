<?php
declare(strict_types=1);

if (function_exists("opcache_reset")) {
    @opcache_reset();
}

require_once __DIR__ . "/includes/layout.php";
require_once __DIR__ . "/includes/contract_utils.php";
require_once __DIR__ . "/includes/contract_filename_parser.php";

function clean_token(string $value): string
{
    $value = str_replace(["-", "."], " ", $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function normalize_filename_text(string $value): string
{
    $value = trim($value);
    if ($value === "") {
        return "";
    }
    if (class_exists("Normalizer")) {
        $norm = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if (is_string($norm) && $norm !== "") {
            $value = $norm;
        }
    }
    return $value;
}

function normalize_contract_type_hint(string $value): ?string
{
    $value = normalize_filename_text($value);
    $value = str_replace(["\\", "/", "_"], " ", $value);
    $value = preg_replace('/\s+/', ' ', strtoupper(trim($value))) ?? strtoupper(trim($value));
    if ($value === "") {
        return null;
    }

    if (preg_match('/(?:^|\D)(100|109|189|200|289|300|389|402|410|420|421|502|510|530)(?:\D|$)/', $value, $m)) {
        return (string)$m[1];
    }

    if (str_contains($value, "CAMBIO") || str_contains($value, "MODIFIC")) {
        return "CAMBIO DE CONTRATO";
    }

    if (
        str_contains($value, "SUSP") ||
        str_contains($value, "LLAM") ||
        str_contains($value, "SUSPEC")
    ) {
        return "SUSPENCION-LLAMAMIENTOS";
    }

    return null;
}

function find_contract_type_index(array $parts, string $detectedType): ?int
{
    foreach ($parts as $i => $part) {
        if (normalize_contract_type_hint((string)$part) === $detectedType) {
            return $i;
        }
    }

    return null;
}

function is_substitution_contract_type(string $type): bool
{
    return in_array($type, ["410", "510"], true);
}

function normalize_date_like_token(string $token): string
{
    $token = trim($token);
    if (preg_match('/^\d{2}[:.]\d{2}[:.]\d{2,4}$/', $token)) {
        return str_replace([':', '.'], '-', $token);
    }
    return $token;
}

function parse_compact_date_iso(string $token): ?string
{
    $token = trim($token);
    if ($token === "") {
        return null;
    }

    $formats = [];
    if (preg_match('/^\d{8}$/', $token)) {
        $formats = ["dmY", "Ymd"];
    } elseif (preg_match('/^\d{6}$/', $token)) {
        $formats = ["dmy", "ymd"];
    }

    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $token);
        if ($dt && $dt->format($format) === $token) {
            return $dt->format("Y-m-d");
        }
    }

    return null;
}

function parse_filename_date_iso(string $token): ?string
{
    $token = normalize_date_like_token($token);
    return contract_parse_date_iso($token) ?? parse_compact_date_iso($token);
}

function merge_date_triplet(array $parts, int $offset): ?string
{
    $first = trim((string)($parts[$offset] ?? ""));
    $second = trim((string)($parts[$offset + 1] ?? ""));
    $third = trim((string)($parts[$offset + 2] ?? ""));
    if (!preg_match('/^\d{1,2}$/', $first) || !preg_match('/^\d{1,2}$/', $second) || !preg_match('/^\d{2,4}$/', $third)) {
        return null;
    }

    $candidate = str_pad($first, 2, "0", STR_PAD_LEFT)
        . "-"
        . str_pad($second, 2, "0", STR_PAD_LEFT)
        . "-"
        . (strlen($third) === 2 ? $third : str_pad($third, 4, "0", STR_PAD_LEFT));

    return parse_filename_date_iso($candidate) !== null ? $candidate : null;
}

function normalize_filename_parts(array $parts): array
{
    $normalized = [];
    $count = count($parts);
    for ($i = 0; $i < $count; $i++) {
        $part = $parts[$i];
        $part = trim((string)$part);
        if ($part === "") {
            continue;
        }

        $tripletDate = merge_date_triplet($parts, $i);
        if ($tripletDate !== null) {
            $normalized[] = $tripletDate;
            $i += 2;
            continue;
        }

        if (preg_match('/^(\d{6,8})[-_](\d{6,8})$/', $part, $m)) {
            $left = normalize_date_like_token((string)$m[1]);
            $right = normalize_date_like_token((string)$m[2]);
            if (parse_filename_date_iso($left) !== null && parse_filename_date_iso($right) !== null) {
                $normalized[] = $left;
                $normalized[] = $right;
                continue;
            }
        }

        if (preg_match('/^(\d{2}[-:.]\d{2}[-:.]\d{2,4})[-_](\d{2}[-:.]\d{2}[-:.]\d{2,4})$/', $part, $m)) {
            $normalized[] = normalize_date_like_token((string)$m[1]);
            $normalized[] = normalize_date_like_token((string)$m[2]);
            continue;
        }

        $normalized[] = normalize_date_like_token($part);
    }

    return $normalized;
}

function contract_rel_parts(string $rel): array
{
    return array_values(array_filter(array_map(
        static fn(string $part): string => trim((string)$part),
        explode("/", str_replace("\\", "/", $rel))
    ), static fn(string $part): bool => $part !== ""));
}

function should_skip_import_rel(string $rel): bool
{
    $parts = contract_rel_parts($rel);
    if (!$parts) {
        return false;
    }

    $first = strtoupper((string)$parts[0]);
    return in_array($first, [
        "ENTRADA",
        "PROCESADOS",
        "SUSPENCION-LLAMAMIENTOS",
        "SUSPENSION-LLAMAMIENTOS",
        "CAMBIO DE CONTRATO",
        "SIN TIPO",
    ], true);
}

function department_from_rel(string $rel, ?string $contractType = null): string
{
    $parts = contract_rel_parts($rel);
    if (!$parts) {
        return "";
    }

    $filename = array_pop($parts);
    unset($filename);

    if (!$parts) {
        return "";
    }

    if ($contractType !== null) {
        foreach ($parts as $index => $part) {
            if (normalize_contract_type_hint($part) === $contractType) {
                $next = $parts[$index + 1] ?? "";
                return clean_token((string)$next);
            }
        }
    }

    if (count($parts) >= 2) {
        return clean_token((string)$parts[1]);
    }

    return "";
}

function looks_like_unique_code(string $token): bool
{
    $token = trim($token);
    if ($token === "") {
        return false;
    }
    return (bool)preg_match('/^(?=.*\d)[A-Za-z0-9-]{5,}$/', $token);
}

function normalize_person_name(array $nameTokens): array
{
    $nameCombined = clean_token((string)implode(" ", array_map("strval", $nameTokens)));
    $nameParts = preg_split('/\s+/u', $nameCombined) ?: [];
    $count = count($nameParts);
    if ($count >= 4) {
        // Spanish-style fallback: preserve two given names + two surnames.
        $firstName = clean_token((string)implode(" ", array_slice($nameParts, 0, 2)));
        $lastName = clean_token((string)implode(" ", array_slice($nameParts, 2)));
    } else {
        $lastName = clean_token((string)($nameParts[0] ?? $nameCombined));
        $firstName = clean_token((string)implode(" ", array_slice($nameParts, 1)));
    }
    $fullName = trim($nameCombined);
    return [
        "first_name" => $firstName !== "" ? $firstName : $lastName,
        "last_name" => $lastName,
        "full_name" => $fullName,
    ];
}

function parse_contract_filename(string $filename, string $rel = ""): ?array
{
    $parsed = parseContratoFilename($filename, $rel);
    if ($parsed === null) {
        return null;
    }
    $departmentFromRel = department_from_rel($rel, (string)$parsed["contract_type"]);
    if ($departmentFromRel !== "") {
        $parsed["department"] = $departmentFromRel;
    }
    return $parsed;
}

function parse_contract_filename_legacy(string $filename, string $rel = ""): ?array
{
    $filename = normalize_filename_text($filename);
    $base = preg_replace('/\.pdf$/i', '', $filename);
    $parts = array_values(array_filter(array_map("trim", preg_split('/_+/u', (string)$base) ?: []), static function (string $v): bool {
        return $v !== "";
    }));
    $parts = normalize_filename_parts($parts);
    if (count($parts) <= 1) {
        $parts = array_values(array_filter(array_map("trim", preg_split('/\s+/u', (string)$base) ?: []), static function (string $v): bool {
            return $v !== "";
        }));
        $parts = normalize_filename_parts($parts);
    }
    if (!$parts) {
        return null;
    }

    $type = contract_detect_type($rel, $filename, $parts);
    if ($type === null) {
        return null;
    }

    $typeIdx = null;
    foreach ($parts as $i => $p) {
        if ((string)$p === (string)$type) {
            $typeIdx = $i;
            break;
        }
    }
    if ($typeIdx === null) {
        $typeIdx = 1;
    }

    $nameTokens = array_slice($parts, 0, $typeIdx);
    if (!$nameTokens && isset($parts[0])) {
        $nameTokens = [$parts[0]];
    }
    $person = normalize_person_name($nameTokens);
    if (trim((string)$person["full_name"]) === "") {
        return null;
    }

    $after = array_slice($parts, $typeIdx + 1);
    $department = "";
    $category = "";
    $uniqueCode = "";
    if ($after) {
        $last = (string)end($after);
        if (looks_like_unique_code($last)) {
            $uniqueCode = clean_token($last);
            array_pop($after);
        }
        if ($after) {
            $department = clean_token((string)$after[0]);
            $category = clean_token((string)implode(" ", array_map("strval", array_slice($after, 1))));
        }
    }

    $inssCode = "";
    if ((bool)preg_match('/^\d{6,12}$/', preg_replace('/\D+/', '', $uniqueCode) ?? "")) {
        $inssCode = preg_replace('/\D+/', '', $uniqueCode) ?? "";
    }

    $departmentFromRel = department_from_rel($rel, $type);
    if ($departmentFromRel !== "") {
        $department = $departmentFromRel;
    }

    return [
        "first_name" => (string)$person["first_name"],
        "last_name" => (string)$person["last_name"],
        "full_name" => (string)$person["full_name"],
        "inss_code" => $inssCode,
        "contract_type" => (string)$type,
        "start_date" => date("Y-m-d"),
        "end_date" => null,
        "department" => $department,
        "category" => $category,
        "status" => "active",
        "unique_code" => $uniqueCode,
    ];
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function ensure_import_state_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS contract_import_state (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_base VARCHAR(50) NOT NULL,
            pdf_relpath VARCHAR(500) NOT NULL,
            source_filename VARCHAR(255) DEFAULT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            file_mtime BIGINT NOT NULL DEFAULT 0,
            last_result ENUM('parsed','skipped','error') NOT NULL DEFAULT 'parsed',
            last_error VARCHAR(255) DEFAULT NULL,
            last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_import_state_source_rel (source_base, pdf_relpath),
            KEY idx_import_state_source_file (source_base, source_filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function load_import_state(PDO $pdo, array $requestedSources): array
{
    $sql = "SELECT source_base, pdf_relpath, file_size, file_mtime, last_result FROM contract_import_state";
    $params = [];
    if ($requestedSources) {
        $placeholders = implode(",", array_fill(0, count($requestedSources), "?"));
        $sql .= " WHERE source_base IN ($placeholders)";
        $params = array_values($requestedSources);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row["source_base"] . "|" . (string)$row["pdf_relpath"];
        $map[$key] = [
            "file_size" => (int)($row["file_size"] ?? 0),
            "file_mtime" => (int)($row["file_mtime"] ?? 0),
            "last_result" => (string)($row["last_result"] ?? ""),
        ];
    }
    return $map;
}

function file_is_unchanged(array $row, array $stateMap): bool
{
    $key = (string)$row["source"] . "|" . (string)$row["rel"];
    if (!isset($stateMap[$key])) {
        return false;
    }

    return (int)$stateMap[$key]["file_size"] === (int)$row["size"]
        && (int)$stateMap[$key]["file_mtime"] === (int)$row["mtime"]
        && (string)$stateMap[$key]["last_result"] === "parsed";
}

function upsert_import_state(PDO $pdo, array $row, string $result, ?string $error = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO contract_import_state
         (source_base, pdf_relpath, source_filename, file_size, file_mtime, last_result, last_error, last_seen_at, last_processed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
           source_filename = VALUES(source_filename),
           file_size = VALUES(file_size),
           file_mtime = VALUES(file_mtime),
           last_result = VALUES(last_result),
           last_error = VALUES(last_error),
           last_seen_at = CURRENT_TIMESTAMP,
           last_processed_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        $row["source"],
        $row["rel"],
        $row["filename"],
        (int)$row["size"],
        (int)$row["mtime"],
        $result,
        $error,
    ]);
}

function source_contract_code(string $sourceBase, string $rel, string $type, string $uniqueCode): string
{
    $uniqueSafe = preg_replace('/[^A-Za-z0-9]/', '', $uniqueCode) ?? "";
    if ($uniqueSafe !== "") {
        return substr(strtoupper($uniqueSafe), 0, 64);
    }
    $hash = strtoupper(substr(sha1($sourceBase . "|" . $rel), 0, 12));
    $code = strtoupper($type . "-" . $hash);
    return substr($code, 0, 64);
}

function upsert_worker(PDO $pdo, array $m): int
{
    $stmt = $pdo->prepare("SELECT id FROM workers WHERE full_name = ? LIMIT 1");
    $stmt->execute([$m["full_name"]]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO workers (first_name, last_name, full_name, inss_code)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$m["first_name"], $m["last_name"], $m["full_name"], $m["inss_code"]]);
    return (int)$pdo->lastInsertId();
}

function upsert_contract(PDO $pdo, int $workerId, string $sourceBase, string $rel, string $filename, array $m): bool
{
    $contractCode = source_contract_code($sourceBase, $rel, $m["contract_type"], (string)($m["unique_code"] ?? ""));
    $ruleValues = [
        "es_sustitucion" => !empty($m["es_sustitucion"]) ? 1 : 0,
        "persona_sustituida" => $m["persona_sustituida"] ?? null,
        "es_prorroga" => !empty($m["es_prorroga"]) ? 1 : 0,
        "numero_prorroga" => $m["numero_prorroga"] ?? null,
        "codigo_inss_base" => $m["codigo_inss_base"] ?? null,
        "es_conversion" => !empty($m["es_conversion"]) ? 1 : 0,
        "es_indefinido" => !empty($m["es_indefinido"]) ? 1 : 0,
        "modalidad" => $m["modalidad"] ?? null,
    ];
    $availableRuleValues = [];
    foreach ($ruleValues as $column => $value) {
        if (column_exists($pdo, "contracts", $column)) {
            $availableRuleValues[$column] = $value;
        }
    }

    $stmt = $pdo->prepare("SELECT id FROM contracts WHERE source_base = ? AND pdf_relpath = ? LIMIT 1");
    $stmt->execute([$sourceBase, $rel]);
    $existingId = $stmt->fetchColumn();

    if (!$existingId) {
        $stmt = $pdo->prepare("SELECT id FROM contracts WHERE source_base = ? AND source_filename = ? LIMIT 1");
        $stmt->execute([$sourceBase, $filename]);
        $existingId = $stmt->fetchColumn();
    }

    if (!$existingId && $contractCode !== "") {
        $stmt = $pdo->prepare("SELECT id FROM contracts WHERE source_base = ? AND contract_code = ? LIMIT 1");
        $stmt->execute([$sourceBase, $contractCode]);
        $existingId = $stmt->fetchColumn();
    }

    if (!$existingId && $contractCode !== "") {
        $stmt = $pdo->prepare("SELECT id FROM contracts WHERE contract_code = ? LIMIT 1");
        $stmt->execute([$contractCode]);
        $existingId = $stmt->fetchColumn();
    }

    if ($existingId) {
        $extraSet = "";
        $extraParams = [];
        foreach ($availableRuleValues as $column => $value) {
            $extraSet .= ", {$column} = ?";
            $extraParams[] = $value;
        }
        $stmt = $pdo->prepare(
            "UPDATE contracts
             SET worker_id = ?, contract_code = ?, worker_name = ?, contract_type = ?, start_date = ?, end_date = ?,
                 department = ?, category = ?, inss_code = ?, status = ?, source_base = ?, pdf_relpath = ?, source_filename = ?{$extraSet}, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $params = [
            $workerId,
            $contractCode,
            $m["full_name"],
            $m["contract_type"],
            $m["start_date"],
            $m["end_date"],
            $m["department"],
            $m["category"],
            $m["inss_code"],
            $m["status"],
            $sourceBase,
            $rel,
            $filename,
        ];
        $params = array_merge($params, $extraParams, [
            (int)$existingId,
        ]);
        $stmt->execute($params);
        return false;
    }

    $extraColumns = array_keys($availableRuleValues);
    $extraSqlColumns = $extraColumns ? ", " . implode(", ", $extraColumns) : "";
    $extraPlaceholders = $extraColumns ? ", " . implode(", ", array_fill(0, count($extraColumns), "?")) : "";
    $stmt = $pdo->prepare(
        "INSERT INTO contracts
         (worker_id, contract_code, worker_name, contract_type, start_date, end_date, department, category, inss_code, status, source_base, pdf_relpath, source_filename{$extraSqlColumns})
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$extraPlaceholders})"
    );
    $params = [
        $workerId,
        $contractCode,
        $m["full_name"],
        $m["contract_type"],
        $m["start_date"],
        $m["end_date"],
        $m["department"],
        $m["category"],
        $m["inss_code"],
        $m["status"],
        $sourceBase,
        $rel,
        $filename,
    ];
    $params = array_merge($params, array_values($availableRuleValues));
    $stmt->execute($params);
    return true;
}

$isCli = PHP_SAPI === "cli";
$apply = false;
$offset = 0;
$limit = 100;
$sourceParam = "";
$onlyChanged = true;

if ($isCli) {
    $apply = in_array("--apply", $argv ?? [], true);
    $onlyChanged = !in_array("--all", $argv ?? [], true);
    foreach (($argv ?? []) as $arg) {
        if (str_starts_with((string)$arg, "--source=")) {
            $sourceParam = (string)substr((string)$arg, strlen("--source="));
        }
    }
} else {
    session_start();
    if (empty($_SESSION["user"])) {
        header("Location: login.php");
        exit;
    }
    if (empty($_SESSION["is_admin"])) {
        header("Location: panel.php");
        exit;
    }
    $apply = ($_GET["apply"] ?? "0") === "1";
    $offset = max(0, (int)($_GET["offset"] ?? 0));
    $limit = max(20, min(500, (int)($_GET["limit"] ?? 100)));
    $sourceParam = trim((string)($_GET["source"] ?? "NAS"));
    $onlyChanged = ($_GET["scope"] ?? "changed") !== "all";
}

$requestedSources = array_values(array_filter(array_map(
    static fn(string $value): string => trim($value),
    preg_split('/\s*,\s*/', $sourceParam) ?: []
), static fn(string $value): bool => $value !== ""));
$requestedSources = array_values(array_unique($requestedSources));
$sourceQuery = $requestedSources ? implode(",", $requestedSources) : "";

$summary = [
    "mode" => $apply ? "APPLY" : "DRY-RUN",
    "scope" => $onlyChanged ? "PENDING_ONLY" : "FULL_SCAN",
    "sources" => $sourceQuery !== "" ? $sourceQuery : "ALL",
    "offset" => $offset,
    "limit" => $limit,
    "total_files_found" => 0,
    "total_files" => 0,
    "unchanged_skipped" => 0,
    "processed_in_run" => 0,
    "scanned" => 0,
    "parsed_ok" => 0,
    "inserted_contracts" => 0,
    "updated_contracts" => 0,
    "workers_linked" => 0,
    "skipped" => 0,
];
$parseSkippedSamples = [];
$applyErrorSamples = [];
$errors = [];

try {
    $roots = require __DIR__ . "/config.php";
    $pdo = require __DIR__ . "/db.php";

    if (!table_exists($pdo, "workers") || !table_exists($pdo, "contracts")) {
        throw new RuntimeException("Faltan tablas workers/contracts. Ejecuta workers_contracts_schema.sql primero.");
    }
    ensure_import_state_table($pdo);
    $stateMap = load_import_state($pdo, $requestedSources);

    $allFiles = [];
    foreach ($roots as $sourceBase => $root) {
        if ($requestedSources && !in_array((string)$sourceBase, $requestedSources, true)) {
            continue;
        }
        if (!is_dir($root) || !is_readable($root)) {
            continue;
        }
        $rootNorm = rtrim(str_replace("\\", "/", $root), "/");
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $f) {
            if (!($f instanceof SplFileInfo) || !$f->isFile()) {
                continue;
            }
            $filename = $f->getFilename();
            if (!preg_match('/\.pdf$/i', $filename)) {
                continue;
            }
            $full = str_replace("\\", "/", $f->getPathname());
            if (strpos($full, $rootNorm) !== 0) {
                continue;
            }
            $rel = ltrim(substr($full, strlen($rootNorm)), "/");
            if (should_skip_import_rel($rel)) {
                continue;
            }
            $allFiles[] = [
                "source" => (string)$sourceBase,
                "root" => $rootNorm,
                "full" => $full,
                "rel" => $rel,
                "filename" => $filename,
                "size" => (int)$f->getSize(),
                "mtime" => (int)$f->getMTime(),
            ];
        }
    }

    usort($allFiles, static function (array $a, array $b): int {
        $cmp = strcmp($a["source"], $b["source"]);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp($a["rel"], $b["rel"]);
    });
    $summary["total_files_found"] = count($allFiles);

    $pendingFiles = [];
    foreach ($allFiles as $row) {
        if ($onlyChanged && file_is_unchanged($row, $stateMap)) {
            $summary["unchanged_skipped"]++;
            continue;
        }
        $pendingFiles[] = $row;
    }
    $summary["total_files"] = count($pendingFiles);

    $slice = $isCli ? $pendingFiles : array_slice($pendingFiles, $offset, $limit);
    foreach ($slice as $row) {
        $summary["processed_in_run"]++;
        $summary["scanned"]++;

        $meta = parse_contract_filename($row["filename"], $row["rel"]);
        if ($meta === null) {
            $meta = parse_contract_filename_legacy($row["filename"], $row["rel"]);
        }
        if ($meta === null) {
            $summary["skipped"]++;
            if ($apply) {
                upsert_import_state($pdo, $row, "skipped", "parse_failed");
            }
            if (count($parseSkippedSamples) < 25) {
                $parseSkippedSamples[] = $row["source"] . ": " . $row["rel"];
            }
            continue;
        }
        $summary["parsed_ok"]++;

        if (!$apply) {
            continue;
        }

        try {
            $pdo->beginTransaction();
            $workerId = upsert_worker($pdo, $meta);
            $summary["workers_linked"]++;
            $inserted = upsert_contract($pdo, $workerId, $row["source"], $row["rel"], $row["filename"], $meta);
            if ($inserted) {
                $summary["inserted_contracts"]++;
            } else {
                $summary["updated_contracts"]++;
            }
            upsert_import_state($pdo, $row, "parsed", null);
            $pdo->commit();
        } catch (Throwable $eRow) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $summary["skipped"]++;
            upsert_import_state($pdo, $row, "error", substr($eRow->getMessage(), 0, 255));
            if (count($applyErrorSamples) < 25) {
                $applyErrorSamples[] = $row["source"] . ": " . $row["rel"] . " (" . $eRow->getMessage() . ")";
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ($isCli) {
    echo "Import contracts [" . $summary["mode"] . "]\n";
    foreach ($summary as $k => $v) {
        echo $k . ": " . $v . "\n";
    }
    if ($errors) {
        echo "Errors:\n- " . implode("\n- ", $errors) . "\n";
    }
    if ($parseSkippedSamples) {
        echo "Parse skipped samples:\n- " . implode("\n- ", $parseSkippedSamples) . "\n";
    }
    if ($applyErrorSamples) {
        echo "Apply error samples:\n- " . implode("\n- ", $applyErrorSamples) . "\n";
    }
    exit;
}

$nextOffset = $offset + $summary["processed_in_run"];
$hasMore = $nextOffset < $summary["total_files"];
$scopeQuery = $onlyChanged ? "changed" : "all";

render_layout_start("Importar contratos - Portal del trabajador", [
    "mode" => "app",
    "active" => "workers",
    "page_title" => "Importador de contratos",
    "page_subtitle" => "Lee PDFs y rellena workers/contracts",
]);
?>
<div class="container wide">
  <div class="card">
    <div style="margin-bottom:12px;"><strong>Modo:</strong> <?= htmlspecialchars((string)$summary["mode"]) ?></div>
    <div class="table-wrap" style="margin-bottom:12px;">
      <table>
        <tbody>
          <?php foreach ($summary as $k => $v): ?>
            <tr><th style="width:240px;"><?= htmlspecialchars((string)$k) ?></th><td><?= htmlspecialchars((string)$v) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($errors): ?>
      <?php foreach ($errors as $err): ?>
        <div class="error" style="margin-bottom:10px;"><?= htmlspecialchars($err) ?></div>
      <?php endforeach; ?>
    <?php endif; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn btn-ghost" href="import_contracts.php?limit=<?= (int)$limit ?>&scope=changed&source=<?= urlencode($sourceQuery !== "" ? $sourceQuery : "NAS") ?>">DRY-RUN pendientes</a>
      <a class="btn btn-ghost" href="import_contracts.php?limit=<?= (int)$limit ?>&scope=all&source=<?= urlencode($sourceQuery !== "" ? $sourceQuery : "NAS") ?>">DRY-RUN completo</a>
      <a class="btn" href="import_contracts.php?apply=1&offset=0&limit=<?= (int)$limit ?>&scope=<?= urlencode($scopeQuery) ?>&source=<?= urlencode($sourceQuery !== "" ? $sourceQuery : "NAS") ?>">Aplicar importación</a>
      <?php if ($apply && $hasMore): ?>
        <a class="btn" href="import_contracts.php?apply=1&offset=<?= (int)$nextOffset ?>&limit=<?= (int)$limit ?>&scope=<?= urlencode($scopeQuery) ?>&source=<?= urlencode($sourceQuery !== "" ? $sourceQuery : "NAS") ?>">Siguiente lote</a>
      <?php endif; ?>
    </div>
    <?php if ($apply): ?>
      <div class="muted" style="margin-top:10px;">
        Progreso: <?= min($summary["total_files"], $nextOffset) ?> / <?= (int)$summary["total_files"] ?> archivos.
      </div>
    <?php endif; ?>
  </div>

  <?php if ($parseSkippedSamples): ?>
    <div class="card">
      <h2 style="margin:0 0 8px 0;">Archivos no parseados (muestra)</h2>
      <ul style="margin:0;padding-left:18px;">
        <?php foreach ($parseSkippedSamples as $s): ?>
          <li><?= htmlspecialchars($s) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($applyErrorSamples): ?>
    <div class="card">
      <h2 style="margin:0 0 8px 0;">Errores al aplicar importación (muestra)</h2>
      <ul style="margin:0;padding-left:18px;">
        <?php foreach ($applyErrorSamples as $s): ?>
          <li><?= htmlspecialchars($s) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
<?php render_layout_end(["mode" => "app"]); ?>

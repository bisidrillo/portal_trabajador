<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/layout.php";
require_once __DIR__ . "/includes/contract_utils.php";

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
        $type = normalize_contract_type_hint($rel) ?? normalize_contract_type_hint($filename);
    }
    if ($type === null) {
        return null;
    }

    $typeIdx = find_contract_type_index($parts, $type);

    if ($typeIdx !== null) {
        $beforeType = array_slice($parts, 0, $typeIdx);
        $afterType = array_slice($parts, $typeIdx + 1);
    } else {
        $firstDateIdx = null;
        foreach ($parts as $i => $token) {
            if (parse_filename_date_iso((string)$token) !== null) {
                $firstDateIdx = $i;
                break;
            }
        }

        if ($firstDateIdx !== null) {
            $beforeType = array_slice($parts, 0, $firstDateIdx);
            while ($beforeType && normalize_contract_type_hint((string)end($beforeType)) === $type) {
                array_pop($beforeType);
            }

            $afterType = array_slice($parts, count($beforeType));
            while ($afterType && normalize_contract_type_hint((string)$afterType[0]) === $type) {
                array_shift($afterType);
            }
        } else {
            $beforeType = [];
            $afterType = $parts;
        }
    }

    $dateIdxs = [];
    foreach ($afterType as $i => $token) {
        $d = parse_filename_date_iso((string)$token);
        if ($d) {
            $dateIdxs[] = $i;
        }
    }
    $startIdx = $dateIdxs[0] ?? null;
    $startDate = $startIdx !== null ? parse_filename_date_iso((string)$afterType[$startIdx]) : null;
    if ($startDate === null) {
        return null;
    }

    $endIdx = $dateIdxs[1] ?? null;
    $endDate = $endIdx !== null ? parse_filename_date_iso((string)$afterType[$endIdx]) : null;
    if (is_substitution_contract_type($type) && count($dateIdxs) < 2) {
        $endIdx = null;
        $endDate = null;
    }

    $department = "";
    $category = "";
    $uniqueCode = "";

    // Soporta dos variantes:
    // A) NOMBRE..._TIPO_FECHAINICIO_FECHAFIN_DEPARTAMENTO_CATEGORIA_CODIGO
    // B) NOMBRE..._TIPO_DEPARTAMENTO_CATEGORIA..._FECHAINICIO_FECHAFIN_CODIGO
    if ($startIdx === 0) {
        $rest = array_slice($afterType, ($endIdx !== null ? $endIdx + 1 : 1));
        if ($rest) {
            $department = clean_token((string)$rest[0]);
            $catTokens = array_slice($rest, 1);
            if ($catTokens) {
                $last = (string)end($catTokens);
                if (looks_like_unique_code($last)) {
                    $uniqueCode = clean_token($last);
                    array_pop($catTokens);
                }
                $category = clean_token((string)implode(" ", array_map("strval", $catTokens)));
            }
        }
    } else {
        if (isset($afterType[0])) {
            $department = clean_token((string)$afterType[0]);
        }
        $roleTokens = [];
        if ($startIdx > 1) {
            $roleTokens = array_slice($afterType, 1, $startIdx - 1);
        } elseif ($startIdx === 1 && isset($afterType[1])) {
            $roleTokens = [$afterType[1]];
        }
        $category = clean_token((string)implode(" ", array_map("strval", $roleTokens)));
        $tail = array_slice($afterType, ($endIdx !== null ? $endIdx + 1 : $startIdx + 1));
        if ($tail) {
            $uniqueCode = clean_token((string)implode("_", array_map("strval", $tail)));
        }
    }

    if ($beforeType) {
        $nameTokens = array_values(array_filter(array_map("strval", $beforeType), static function (string $v): bool {
            return trim($v) !== "";
        }));
    } else {
        $baseName = trim((string)($parts[0] ?? ""));
        if ($baseName === "") {
            return null;
        }
        $nameTokens = [clean_token($baseName)];
    }

    $person = normalize_person_name($nameTokens);
    $fullName = (string)$person["full_name"];
    if ($fullName === "") {
        return null;
    }

    $today = (new DateTimeImmutable("today"))->format("Y-m-d");
    if ($startDate > $today) {
        $status = "inactive";
    } elseif ($endDate !== null && $endDate < $today) {
        $status = "ended";
    } else {
        $status = "active";
    }

    $inssCode = "";
    if ((bool)preg_match('/^\d{6,12}$/', preg_replace('/\D+/', '', $uniqueCode) ?? "")) {
        $inssCode = preg_replace('/\D+/', '', $uniqueCode) ?? "";
    }

    return [
        "first_name" => (string)$person["first_name"],
        "last_name" => (string)$person["last_name"],
        "full_name" => $fullName,
        "inss_code" => $inssCode,
        "contract_type" => (string)$type,
        "start_date" => $startDate,
        "end_date" => $endDate,
        "department" => $department,
        "category" => $category,
        "status" => $status,
        "unique_code" => $uniqueCode,
    ];
}

function parse_contract_filename_legacy(string $filename, string $rel = ""): ?array
{
    $filename = normalize_filename_text($filename);
    $base = preg_replace('/\.pdf$/i', '', $filename);
    $parts = array_values(array_filter(array_map("trim", preg_split('/_+/u', (string)$base) ?: []), static function (string $v): bool {
        return $v !== "";
    }));
    if (count($parts) <= 1) {
        $parts = array_values(array_filter(array_map("trim", preg_split('/\s+/u', (string)$base) ?: []), static function (string $v): bool {
            return $v !== "";
        }));
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
    $stmt = $pdo->prepare("SELECT id FROM contracts WHERE source_base = ? AND pdf_relpath = ? LIMIT 1");
    $stmt->execute([$sourceBase, $rel]);
    $existingId = $stmt->fetchColumn();
    $contractCode = source_contract_code($sourceBase, $rel, $m["contract_type"], (string)($m["unique_code"] ?? ""));

    if ($existingId) {
        $stmt = $pdo->prepare(
            "UPDATE contracts
             SET worker_id = ?, contract_code = ?, worker_name = ?, contract_type = ?, start_date = ?, end_date = ?,
                 department = ?, category = ?, inss_code = ?, status = ?, source_filename = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([
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
            $filename,
            (int)$existingId,
        ]);
        return false;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO contracts
         (worker_id, contract_code, worker_name, contract_type, start_date, end_date, department, category, inss_code, status, source_base, pdf_relpath, source_filename)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
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
    ]);
    return true;
}

$isCli = PHP_SAPI === "cli";
$apply = false;
$offset = 0;
$limit = 100;
$sourceParam = "";

if ($isCli) {
    $apply = in_array("--apply", $argv ?? [], true);
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
}

$requestedSources = array_values(array_filter(array_map(
    static fn(string $value): string => trim($value),
    preg_split('/\s*,\s*/', $sourceParam) ?: []
), static fn(string $value): bool => $value !== ""));
$requestedSources = array_values(array_unique($requestedSources));
$sourceQuery = $requestedSources ? implode(",", $requestedSources) : "";

$summary = [
    "mode" => $apply ? "APPLY" : "DRY-RUN",
    "sources" => $sourceQuery !== "" ? $sourceQuery : "ALL",
    "offset" => $offset,
    "limit" => $limit,
    "total_files" => 0,
    "processed_in_run" => 0,
    "scanned" => 0,
    "parsed_ok" => 0,
    "inserted_contracts" => 0,
    "updated_contracts" => 0,
    "workers_linked" => 0,
    "skipped" => 0,
];
$skippedSamples = [];
$errors = [];

try {
    $roots = require __DIR__ . "/config.php";
    $pdo = require __DIR__ . "/db.php";

    if (!table_exists($pdo, "workers") || !table_exists($pdo, "contracts")) {
        throw new RuntimeException("Faltan tablas workers/contracts. Ejecuta workers_contracts_schema.sql primero.");
    }

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
            $allFiles[] = [
                "source" => (string)$sourceBase,
                "root" => $rootNorm,
                "full" => $full,
                "rel" => $rel,
                "filename" => $filename,
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
    $summary["total_files"] = count($allFiles);

    $slice = $isCli ? $allFiles : array_slice($allFiles, $offset, $limit);
    foreach ($slice as $row) {
        $summary["processed_in_run"]++;
        $summary["scanned"]++;

        $meta = parse_contract_filename($row["filename"], $row["rel"]);
        if ($meta === null) {
            $meta = parse_contract_filename_legacy($row["filename"], $row["rel"]);
        }
        if ($meta === null) {
            $summary["skipped"]++;
            if (count($skippedSamples) < 25) {
                $skippedSamples[] = $row["source"] . ": " . $row["rel"];
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
            $pdo->commit();
        } catch (Throwable $eRow) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $summary["skipped"]++;
            if (count($skippedSamples) < 25) {
                $skippedSamples[] = $row["source"] . ": " . $row["rel"];
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
    if ($skippedSamples) {
        echo "Skipped samples:\n- " . implode("\n- ", $skippedSamples) . "\n";
    }
    exit;
}

$nextOffset = $offset + $summary["processed_in_run"];
$hasMore = $nextOffset < $summary["total_files"];

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
      <a class="btn btn-ghost" href="import_contracts.php?limit=<?= (int)$limit ?>&source=<?= urlencode($sourceQuery !== "" ? $sourceQuery : "NAS") ?>">Ejecutar DRY-RUN</a>
      <a class="btn" href="import_contracts.php?apply=1&offset=0&limit=<?= (int)$limit ?>&source=<?= urlencode($sourceQuery !== "" ? $sourceQuery : "NAS") ?>">Aplicar importación (lote)</a>
      <?php if ($apply && $hasMore): ?>
        <a class="btn" href="import_contracts.php?apply=1&offset=<?= (int)$nextOffset ?>&limit=<?= (int)$limit ?>&source=<?= urlencode($sourceQuery !== "" ? $sourceQuery : "NAS") ?>">Siguiente lote</a>
      <?php endif; ?>
    </div>
    <?php if ($apply): ?>
      <div class="muted" style="margin-top:10px;">
        Progreso: <?= min($summary["total_files"], $nextOffset) ?> / <?= (int)$summary["total_files"] ?> archivos.
      </div>
    <?php endif; ?>
  </div>

  <?php if ($skippedSamples): ?>
    <div class="card">
      <h2 style="margin:0 0 8px 0;">Archivos no parseados (muestra)</h2>
      <ul style="margin:0;padding-left:18px;">
        <?php foreach ($skippedSamples as $s): ?>
          <li><?= htmlspecialchars($s) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
<?php render_layout_end(["mode" => "app"]); ?>

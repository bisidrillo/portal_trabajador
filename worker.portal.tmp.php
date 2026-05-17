<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/includes/layout.php";

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

function normalize_path(string $path): string
{
    return rtrim(str_replace("\\", "/", $path), "/");
}

function normalize_text(string $value): string
{
    $value = trim($value);
    if ($value === "") {
        return "";
    }
    if (function_exists("iconv")) {
        $converted = @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = function_exists("mb_strtolower")
        ? mb_strtolower($value, "UTF-8")
        : strtolower($value);
    $value = preg_replace('/[^a-z0-9 ]+/i', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
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
        "SIN DEPARTAMENTO",
    ];
}

function unique_contract_rows(array $rows): array
{
    $seen = [];
    $result = [];
    foreach ($rows as $row) {
        $id = (int)($row["id"] ?? 0);
        $key = $id > 0 ? ("id:" . $id) : md5(json_encode($row, JSON_UNESCAPED_UNICODE) ?: serialize($row));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = $row;
    }
    return $result;
}

function collect_sensitive_docs(array $roots, ?string $workerName = null, int $limit = 300): array
{
    $docs = [];
    $workerTokens = [];
    if ($workerName !== null && trim($workerName) !== "") {
        $workerNormalized = normalize_text((string)$workerName);
        $workerTokens = array_values(array_filter(explode(" ", $workerNormalized), static function (string $t): bool {
            return strlen($t) >= 3;
        }));
    }

    foreach ($roots as $base => $root) {
        $baseName = strtoupper((string)$base);
        $rootName = strtoupper((string)$root);
        if (strpos($baseName, "PERSONAL") === false && strpos($rootName, "PERSONAL") === false) {
            continue;
        }
        if (!is_dir($root) || !is_readable($root)) {
            continue;
        }
        $rootNorm = normalize_path((string)$root);
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator((string)$root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (Throwable $e) {
            continue;
        }

        foreach ($it as $f) {
            if (!($f instanceof SplFileInfo) || !$f->isFile()) {
                continue;
            }
            $name = $f->getFilename();
            if (!preg_match('/\.pdf$/i', $name)) {
                continue;
            }
            $full = normalize_path($f->getPathname());
            if (strpos($full, $rootNorm) !== 0) {
                continue;
            }
            $rel = ltrim(substr($full, strlen($rootNorm)), "/");
            if ($workerTokens) {
                $searchTarget = normalize_text($name . " " . $rel);
                $matched = 0;
                foreach ($workerTokens as $token) {
                    if (strpos($searchTarget, $token) !== false) {
                        $matched++;
                    }
                }
                if ($matched === 0) {
                    continue;
                }
            }
            $docs[] = [
                "base" => (string)$base,
                "name" => $name,
                "rel" => $rel,
            ];
            if (count($docs) >= $limit) {
                break 2;
            }
        }
    }
    usort($docs, static function (array $a, array $b): int {
        return strcasecmp($a["name"], $b["name"]);
    });
    return $docs;
}

$id = (int)($_GET["id"] ?? 0);
$isAdmin = !empty($_SESSION["is_admin"]);
$worker = null;
$contracts = [];
$sensitiveDocs = [];
$warnings = [];
$statusOk = trim((string)($_GET["ok"] ?? ""));
$statusErr = trim((string)($_GET["err"] ?? ""));
$departmentOptions = canonical_department_options();

if ($id <= 0) {
    header("Location: workers.php");
    exit;
}

try {
    $roots = require __DIR__ . "/config.php";
    $pdo = require __DIR__ . "/db.php";
    if (!table_exists($pdo, "workers") || !table_exists($pdo, "contracts")) {
        $warnings[] = "Faltan tablas workers/contracts. Ejecuta workers_contracts_schema.sql.";
    } else {
        $hasCategory = column_exists($pdo, "contracts", "category");
        $hasSourceFilename = column_exists($pdo, "contracts", "source_filename");
        $hasSourceBase = column_exists($pdo, "contracts", "source_base");
        $hasPdfRelPath = column_exists($pdo, "contracts", "pdf_relpath");
        $hasInssCode = column_exists($pdo, "contracts", "inss_code");

        $stmt = $pdo->prepare("SELECT id, first_name, last_name, full_name, inss_code, sensitive_notes, created_at FROM workers WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $worker = $stmt->fetch();

        if ($worker) {
            $baseContractsSql =
                "SELECT id, contract_type, start_date, end_date, department,
                        " . ($hasCategory ? "category" : "NULL") . " AS category,
                        " . ($hasInssCode ? "inss_code" : "NULL") . " AS inss_code,
                        status,
                        " . ($hasSourceFilename ? "source_filename" : "NULL") . " AS source_filename,
                        " . ($hasSourceBase ? "source_base" : "NULL") . " AS source_base,
                        " . ($hasPdfRelPath ? "pdf_relpath" : "NULL") . " AS pdf_relpath,
                        worker_id,
                        worker_name
                 FROM contracts";

            $stmt = $pdo->prepare($baseContractsSql . "
                 WHERE worker_id = ?
                 ORDER BY start_date DESC, id DESC");
            $stmt->execute([$id]);
            $contracts = $stmt->fetchAll();

            $workerFullName = trim((string)($worker["full_name"] ?? ""));
            $normalizedWorkerName = normalize_text($workerFullName);
            if ($workerFullName !== "") {
                $stmt = $pdo->prepare($baseContractsSql . "
                     WHERE TRIM(worker_name) = ?
                     ORDER BY start_date DESC, id DESC");
                $stmt->execute([$workerFullName]);
                $contracts = array_merge($contracts, $stmt->fetchAll());
            }

            if ($normalizedWorkerName !== "") {
                $stmt = $pdo->query($baseContractsSql . " ORDER BY start_date DESC, id DESC");
                $normalizedMatches = [];
                foreach ($stmt->fetchAll() as $contractRow) {
                    if (normalize_text((string)($contractRow["worker_name"] ?? "")) !== $normalizedWorkerName) {
                        continue;
                    }
                    $normalizedMatches[] = $contractRow;
                }
                $contracts = array_merge($contracts, $normalizedMatches);
            }

            $contracts = unique_contract_rows($contracts);
            usort($contracts, static function (array $a, array $b): int {
                $startA = trim((string)($a["start_date"] ?? ""));
                $startB = trim((string)($b["start_date"] ?? ""));
                if ($startA === $startB) {
                    return ((int)($b["id"] ?? 0)) <=> ((int)($a["id"] ?? 0));
                }
                return strcmp($startB, $startA);
            });

            if (!$contracts) {
                $warnings[] = "No hay contratos enlazados por ID ni por nombre para este trabajador.";
            }
            if ($isAdmin) {
                $sensitiveDocs = collect_sensitive_docs($roots, (string)($worker["full_name"] ?? ""), 500);
                if (!$sensitiveDocs) {
                    $warnings[] = "No se encontraron PDFs sensibles para este trabajador en carpetas PERSONAL*.";
                }
            }
        } else {
            $warnings[] = "Trabajador no encontrado.";
        }
    }
} catch (Throwable $e) {
    $warnings[] = "No se pudo cargar la ficha del trabajador.";
    if (!empty($_SESSION["is_admin"])) {
        $warnings[] = "Detalle técnico: " . $e->getMessage();
    }
}

render_layout_start("Ficha trabajador - Portal del trabajador", [
    "mode" => "app",
    "active" => "workers",
    "page_title" => "Ficha de trabajador",
    "page_subtitle" => $worker ? (string)$worker["full_name"] : "Sin datos",
]);
?>
<style>
  .icon-link{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border:1px solid var(--border);border-radius:8px;background:#fff;color:#1e3a8a;text-decoration:none;}
  .icon-link:hover{background:#eef2ff;}
  .icon-link svg{width:14px;height:14px;display:block;}
  .worker-contracts-table{width:100%;table-layout:fixed;}
  .worker-contracts-table th,.worker-contracts-table td{white-space:normal;vertical-align:middle;}
  .worker-contracts-table .col-type{width:8%;text-align:center;white-space:nowrap;}
  .worker-contracts-table .col-date{width:10%;white-space:nowrap;}
  .worker-contracts-table .col-dept{width:18%;}
  .worker-contracts-table .col-cat{width:10%;}
  .worker-contracts-table .col-status{width:10%;}
  .worker-contracts-table .col-file{width:28%;overflow-wrap:anywhere;}
  .worker-contracts-table .col-pdf{width:6%;white-space:nowrap;}
  .dept-edit{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px;align-items:center;}
  .dept-edit select{min-width:0;padding:4px 6px;border:1px solid var(--border);border-radius:8px;background:#f9fafb;font-size:11px;}
  .status-badge{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.02em;text-transform:uppercase;}
  .status-badge.active{background:#dcfce7;color:#166534;border:1px solid #86efac;}
  .status-badge.inactive{background:#fef3c7;color:#92400e;border:1px solid #fcd34d;}
  .status-badge.ended{background:#e5e7eb;color:#374151;border:1px solid #d1d5db;}
  .worker-overview{display:grid;gap:14px;margin-bottom:14px;}
  .worker-meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;}
  .worker-meta-item{padding:14px 16px;border-radius:18px;background:rgba(255,255,255,.72);border:1px solid rgba(148,163,184,.16);}
  .worker-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
  .worker-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;}
  .worker-summary-card{padding:16px;border-radius:18px;background:rgba(255,255,255,.72);border:1px solid rgba(148,163,184,.16);}
  .worker-summary-label{font-size:11px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:var(--muted);margin-bottom:6px;}
  .worker-summary-value{font-family:"Space Grotesk",sans-serif;font-size:26px;line-height:1;}
  @media (max-width: 1100px){
    .worker-contracts-table th,.worker-contracts-table td{padding:10px;font-size:13px;}
    .worker-contracts-table .col-type{width:8%;}
    .worker-contracts-table .col-date{width:12%;}
    .worker-contracts-table .col-dept{width:19%;}
    .worker-contracts-table .col-cat{width:12%;}
    .worker-contracts-table .col-status{width:12%;}
    .worker-contracts-table .col-file{width:27%;}
    .worker-contracts-table .col-pdf{width:8%;}
  }
  @media (max-width: 900px){
    .worker-contracts-table .col-dept,
    .worker-contracts-table .col-cat{display:none;}
  }
</style>
<div class="container wide" x-data="{tab: 'contracts'}">
  <?php if ($statusOk !== ""): ?>
    <div class="card"><div class="success"><?= htmlspecialchars($statusOk) ?></div></div>
  <?php endif; ?>
  <?php if ($statusErr !== ""): ?>
    <div class="card"><div class="error"><?= htmlspecialchars($statusErr) ?></div></div>
  <?php endif; ?>

  <div class="card" style="margin-bottom:12px;">
    <a href="workers.php">&larr; Volver al listado</a>
  </div>

  <?php foreach ($warnings as $w): ?>
    <div class="card"><div class="error"><?= htmlspecialchars($w) ?></div></div>
  <?php endforeach; ?>

  <?php if ($worker): ?>
    <div class="worker-overview">
      <div class="card glass-accent bento-card-md">
        <span class="section-kicker">Ficha</span>
        <h2 class="section-title" style="margin-top:12px;">Resumen del trabajador</h2>
        <div class="worker-meta-grid">
          <div class="worker-meta-item"><strong>Nombre:</strong><br><?= htmlspecialchars((string)$worker["full_name"]) ?></div>
          <div class="worker-meta-item"><strong>Alta ficha:</strong><br><?= htmlspecialchars((string)($worker["created_at"] ?? "")) ?></div>
        </div>
      </div>

      <div class="card">
        <div class="worker-tabs">
        <button type="button" class="btn btn-ghost" @click="tab='contracts'" :class="tab==='contracts' ? 'active' : ''">Contratos</button>
        <button type="button" class="btn btn-ghost" @click="tab='summary'" :class="tab==='summary' ? 'active' : ''">Resumen</button>
        <?php if ($isAdmin): ?>
          <button type="button" class="btn btn-ghost" @click="tab='sensitive'" :class="tab==='sensitive' ? 'active' : ''">Área sensible</button>
        <?php endif; ?>
        </div>

        <div x-show="tab==='contracts'" x-cloak>
          <div class="table-wrap">
            <table class="worker-contracts-table">
              <thead>
                <tr>
                  <th class="col-type">Tipo</th>
                  <th class="col-date">Inicio</th>
                  <th class="col-date">Fin</th>
                  <th class="col-dept">Departamento</th>
                  <th class="col-cat">Categoria</th>
                  <th class="col-status">Estado</th>
                  <th class="col-file">Archivo</th>
                  <th class="col-pdf">PDF</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$contracts): ?>
                  <tr><td colspan="8">Sin contratos</td></tr>
                <?php else: ?>
                  <?php foreach ($contracts as $c): ?>
                    <?php
                      $statusRaw = (string)($c["status"] ?? "");
                      $statusClass = in_array($statusRaw, ["active", "inactive", "ended"], true) ? $statusRaw : "ended";
                    ?>
                    <tr>
                      <td class="col-type"><?= htmlspecialchars((string)($c["contract_type"] ?? "")) ?></td>
                      <td class="col-date"><?= htmlspecialchars(format_date_es((string)($c["start_date"] ?? ""))) ?></td>
                      <td class="col-date"><?= htmlspecialchars(format_date_es((string)($c["end_date"] ?? ""))) ?></td>
                      <td class="col-dept">
                        <?php if ($isAdmin): ?>
                          <form method="post" action="dashboard.php" class="dept-edit" style="margin:0;">
                            <input type="hidden" name="action" value="set_contract_department">
                            <input type="hidden" name="contract_id" value="<?= (int)$c["id"] ?>">
                            <input type="hidden" name="return_to" value="<?= htmlspecialchars('worker.php?id=' . (int)$id) ?>">
                            <select name="department_new" required>
                              <?php foreach ($departmentOptions as $dep): ?>
                                <option value="<?= htmlspecialchars($dep) ?>" <?= ((string)($c["department"] ?? "") === $dep) ? "selected" : "" ?>><?= htmlspecialchars($dep) ?></option>
                              <?php endforeach; ?>
                            </select>
                            <button class="btn btn-ghost" type="submit">Guardar</button>
                          </form>
                        <?php else: ?>
                          <?= htmlspecialchars((string)($c["department"] ?? "")) ?>
                        <?php endif; ?>
                      </td>
                      <td class="col-cat"><?= htmlspecialchars((string)($c["category"] ?? "")) ?></td>
                      <td class="col-status"><span class="status-badge <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusRaw) ?></span></td>
                      <td class="col-file"><?= htmlspecialchars((string)($c["source_filename"] ?? "")) ?></td>
                      <td class="col-pdf">
                        <?php if (!empty($c["source_base"]) && !empty($c["pdf_relpath"])): ?>
                          <a class="icon-link" href="view.php?base=<?= rawurlencode((string)$c["source_base"]) ?>&file=<?= rawurlencode((string)$c["pdf_relpath"]) ?>" target="_blank" rel="noopener" title="Abrir PDF" aria-label="Abrir PDF">
                            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                              <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                              <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                          </a>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div x-show="tab==='summary'" x-cloak>
          <div class="worker-summary-grid">
            <div class="worker-summary-card">
              <div class="worker-summary-label">Total contratos</div>
              <div class="worker-summary-value"><?= count($contracts) ?></div>
            </div>
            <div class="worker-summary-card">
              <div class="worker-summary-label">Activos</div>
              <div class="worker-summary-value"><?= count(array_filter($contracts, static function (array $c): bool { return ($c["status"] ?? "") === "active"; })) ?></div>
            </div>
            <div class="worker-summary-card">
              <div class="worker-summary-label">Último tipo</div>
              <div class="worker-summary-value" style="font-size:20px;"><?= htmlspecialchars((string)($contracts[0]["contract_type"] ?? "")) ?></div>
            </div>
            <div class="worker-summary-card">
              <div class="worker-summary-label">Último inicio</div>
              <div class="worker-summary-value" style="font-size:20px;"><?= htmlspecialchars((string)($contracts[0]["start_date"] ?? "")) ?></div>
            </div>
          </div>
        </div>

        <?php if ($isAdmin): ?>
          <div x-show="tab==='sensitive'" x-cloak>
            <p class="muted" style="margin-top:0;">Documentos sensibles filtrados para este trabajador.</p>
            <div class="card card-flat" style="margin-bottom:0;">
              <div style="margin-bottom:10px;">
                <?= nl2br(htmlspecialchars((string)($worker["sensitive_notes"] ?? "Sin notas sensibles."))) ?>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Documento</th>
                      <th>Origen</th>
                      <th>Abrir</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$sensitiveDocs): ?>
                      <tr><td colspan="3">Sin documentos sensibles encontrados para este trabajador.</td></tr>
                    <?php else: ?>
                      <?php foreach ($sensitiveDocs as $d): ?>
                        <tr>
                          <td><?= htmlspecialchars((string)$d["name"]) ?></td>
                          <td><?= htmlspecialchars((string)$d["base"]) ?></td>
                          <td>
                            <a class="icon-link" href="view.php?base=<?= rawurlencode((string)$d["base"]) ?>&file=<?= rawurlencode((string)$d["rel"]) ?>" target="_blank" rel="noopener" title="Abrir PDF" aria-label="Abrir PDF">
                              <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                              </svg>
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php render_layout_end(["mode" => "app"]); ?>

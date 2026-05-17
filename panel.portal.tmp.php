<?php
session_start();
if (empty($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

$DOCUMENT_ROOTS = require __DIR__ . "/config.php";
require_once __DIR__ . "/includes/layout.php";
require_once __DIR__ . "/includes/contract_utils.php";
require_once __DIR__ . "/includes/contract_filename_parser.php";

function normalize_path(string $path): string {
    $path = str_replace("\\", "/", $path);
    return rtrim($path, "/");
}

function parse_date_str(string $value): ?DateTimeImmutable {
    return contract_parse_date($value);
}

function extract_dates_from_name(string $name): array {
    $dates = [];
    if (preg_match_all('/\b(\d{4}-\d{2}-\d{2})\b/', $name, $m)) {
        foreach ($m[1] as $d) {
            $dt = parse_date_str($d);
            if ($dt) { $dates[] = $dt; }
        }
    }
    if (preg_match_all('/\b(\d{2}-\d{2}-\d{4})\b/', $name, $m)) {
        foreach ($m[1] as $d) {
            $dt = parse_date_str($d);
            if ($dt) { $dates[] = $dt; }
        }
    }
    if (preg_match_all('/\b(\d{2}-\d{2}-\d{2})\b/', $name, $m)) {
        foreach ($m[1] as $d) {
            $dt = parse_date_str($d);
            if ($dt) { $dates[] = $dt; }
        }
    }
    return $dates;
}

function normalize_text(string $value): string {
    if (function_exists("iconv")) {
        $converted = @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = function_exists("mb_strtolower")
        ? mb_strtolower($value, "UTF-8")
        : strtolower($value);
    $value = str_replace(["_", "-"], " ", $value);
    $value = preg_replace('/[^a-z0-9 ]+/i', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function text_match(string $haystack, string $needle): bool {
    $needle = normalize_text($needle);
    if ($needle === "") {
        return true;
    }
    $haystack = normalize_text($haystack);
    if ($haystack === "") {
        return false;
    }
    $parts = array_values(array_filter(explode(" ", $needle)));
    foreach ($parts as $part) {
        if (strpos($haystack, $part) === false) {
            return false;
        }
    }
    return true;
}

function extract_person_name(string $name): string {
    $base = preg_replace('/\.pdf$/i', '', $name);
    $parts = explode("_", $base);
    if (!$parts) {
        return trim($base);
    }
    return trim($parts[0]);
}

function parse_contract_metadata(string $name): array {
    $meta = [
        "person" => extract_person_name($name),
        "type" => null,
        "department" => null,
        "role" => null,
        "start" => null,
        "end" => null,
        "id" => null,
        "sustituto" => null,
        "sustituido" => null,
    ];
    $parsed = parseContratoFilename($name);
    if ($parsed === null) {
        return $meta;
    }

    $meta["person"] = (string)$parsed["full_name"];
    $meta["type"] = (string)$parsed["contract_type"];
    $meta["department"] = (string)$parsed["department"];
    $meta["role"] = (string)$parsed["category"];
    $meta["id"] = (string)$parsed["unique_code"];
    $meta["start"] = parse_date_str((string)$parsed["start_date"]);
    $meta["end"] = !empty($parsed["end_date"]) ? parse_date_str((string)$parsed["end_date"]) : null;
    if (!empty($parsed["es_sustitucion"])) {
        $meta["sustituto"] = (string)$parsed["full_name"];
        $meta["sustituido"] = (string)($parsed["persona_sustituida"] ?? "");
    }
    return $meta;
}

function person_key(string $name): string {
    return normalize_text($name);
}

function detect_contract_type(string $rel, string $name): ?string {
    return contract_detect_type($rel, $name);
}

function detect_section_from_rel(string $rel): array {
    $parts = array_values(array_filter(explode("/", str_replace("\\", "/", $rel))));
    $top = $parts[0] ?? "";
    $top_norm = normalize_text($top);
    if ($top === "") {
        return ["other", "Otros"];
    }
    if (preg_match('/^\d{3,4}$/', $top)) {
        return ["contracts", "Contratos"];
    }
    if ($top_norm === "cambio de contrato") {
        return ["change", "Cambio de contrato"];
    }
    if ($top_norm === "suspencion llamamientos" || $top_norm === "suspension llamamientos") {
        return ["calls", "Suspensiones y llamamientos"];
    }
    return [$top_norm, $top];
}

function find_people(array $roots, string $query, ?string $section, int $limit = 200): array {
    $people = [];
    foreach ($roots as $root) {
        if (!is_dir($root) || !is_readable($root)) {
            continue;
        }
        $root_norm = normalize_path($root);
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (Throwable $e) {
            continue;
        }
        foreach ($iterator as $fileinfo) {
            if (!($fileinfo instanceof SplFileInfo) || !$fileinfo->isFile()) {
                continue;
            }
            $name = $fileinfo->getFilename();
            if (!preg_match('/\.pdf$/i', $name)) {
                continue;
            }
            $full = normalize_path($fileinfo->getPathname());
            if (strpos($full, $root_norm) !== 0) {
                continue;
            }
            $rel = ltrim(substr($full, strlen($root_norm)), "/");
            [$section_key] = detect_section_from_rel($rel);
            if ($section && $section_key !== $section) {
                continue;
            }
            $meta = parse_contract_metadata($name);
            $person = !empty($meta["person"]) ? (string)$meta["person"] : extract_person_name($name);
            if ($person === "" || !text_match($person, $query)) {
                continue;
            }
            $key = person_key($person);
            if (!isset($people[$key])) {
                $people[$key] = ["name" => $person, "count" => 0];
            }
            $people[$key]["count"]++;
            if (count($people) >= $limit) {
                break 2;
            }
        }
    }
    uasort($people, static function (array $a, array $b): int {
        return strcasecmp($a["name"], $b["name"]);
    });
    return array_values($people);
}

function search_documents(
    array $roots,
    string $query,
    ?string $person,
    ?string $type,
    ?string $section,
    ?DateTimeImmutable $from,
    ?DateTimeImmutable $to,
    ?string $sustituto,
    ?string $sustituido,
    int $limit = 200
): array {
    $results = [];
    foreach ($roots as $label => $root) {
        if (!is_dir($root) || !is_readable($root)) {
            continue;
        }
        $root_norm = normalize_path($root);
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (Throwable $e) {
            continue;
        }

        foreach ($iterator as $fileinfo) {
            if (!($fileinfo instanceof SplFileInfo) || !$fileinfo->isFile()) {
                continue;
            }
            $name = $fileinfo->getFilename();
            if (!preg_match('/\.pdf$/i', $name)) {
                continue;
            }

            $meta = parse_contract_metadata($name);
            $person_name = (string)$meta["person"];
            $person_match_key = person_key($person_name);

            if ($person !== null && $person !== "" && $person_match_key !== person_key($person)) {
                continue;
            }
            if ($person === null && $query !== "" && !text_match($person_name . " " . $name, $query)) {
                continue;
            }

            $contract_type = $meta["type"] ?: detect_contract_type($fileinfo->getPathname(), $name);
            if ($type && $contract_type !== $type) {
                continue;
            }

            if ($sustituto || $sustituido) {
                $meta_sustituto = $meta["sustituto"] ?: $person_name;
                $meta_sustituido = $meta["sustituido"] ?: "";
                if ($sustituto && !text_match($meta_sustituto, $sustituto)) {
                    continue;
                }
                if ($sustituido && !text_match($meta_sustituido, $sustituido)) {
                    continue;
                }
            }

            if ($from || $to) {
                $keep = false;
                if ($meta["start"] instanceof DateTimeImmutable && $meta["end"] instanceof DateTimeImmutable) {
                    $start = $meta["start"];
                    $end = $meta["end"];
                    if (($from === null || $end >= $from) && ($to === null || $start <= $to)) {
                        $keep = true;
                    }
                } elseif ($meta["start"] instanceof DateTimeImmutable) {
                    $d = $meta["start"];
                    if (($from === null || $d >= $from) && ($to === null || $d <= $to)) {
                        $keep = true;
                    }
                } else {
                    $dates = extract_dates_from_name($name);
                    if (count($dates) >= 2) {
                        $start = $dates[0];
                        $end = $dates[1];
                        if (($from === null || $end >= $from) && ($to === null || $start <= $to)) {
                            $keep = true;
                        }
                    } elseif (count($dates) === 1) {
                        $d = $dates[0];
                        if (($from === null || $d >= $from) && ($to === null || $d <= $to)) {
                            $keep = true;
                        }
                    }
                }
                if (!$keep) {
                    continue;
                }
            }

            $full = normalize_path($fileinfo->getPathname());
            if (strpos($full, $root_norm) !== 0) {
                continue;
            }
            $rel = ltrim(substr($full, strlen($root_norm)), "/");
            [$section_key, $section_label] = detect_section_from_rel($rel);
            if ($section && $section_key !== $section) {
                continue;
            }

            $results[] = [
                "base" => $label,
                "rel" => $rel,
                "name" => $name,
                "type" => $contract_type,
                "person" => $person_name,
                "section_key" => $section_key,
                "section" => $section_label,
            ];
            if (count($results) >= $limit) {
                break 2;
            }
        }
    }
    return $results;
}

$q = trim($_GET["q"] ?? "");
$person = trim($_GET["person"] ?? "");
$type = trim($_GET["type"] ?? "");
$section = trim($_GET["section"] ?? "");
$date_from = trim($_GET["from"] ?? "");
$date_to = trim($_GET["to"] ?? "");
$sustituto = trim($_GET["sustituto"] ?? "");
$sustituido = trim($_GET["sustituido"] ?? "");
$errors = [];
$results = [];
$people = [];
$limit = 200;
$isAdmin = !empty($_SESSION["is_admin"]);
$pdo = null;
$has_filter = $type !== "" || $section !== "" || $date_from !== "" || $date_to !== "" || $sustituto !== "" || $sustituido !== "";
$available_roots = [];

foreach ($DOCUMENT_ROOTS as $label => $root) {
    if (is_dir($root) && is_readable($root)) {
        $available_roots[$label] = $root;
    }
}

if ($isAdmin) {
    try {
        $pdo = require __DIR__ . "/db.php";
    } catch (Throwable $e) {
        $pdo = null;
    }
}

if ($q !== "" || $person !== "" || $has_filter) {
    if (!$available_roots) {
        $errors[] = "No se puede leer la carpeta de contratos desde PHP. Revisa la ruta/permisos en config.php.";
    }
    $len = function_exists("mb_strlen") ? mb_strlen($q) : strlen($q);
    if ($q !== "" && $len < 2 && $person === "") {
        $errors[] = "Escribe al menos 2 caracteres para buscar.";
    }
    $from_dt = parse_date_str($date_from);
    $to_dt = parse_date_str($date_to);
    if (($date_from !== "" && !$from_dt) || ($date_to !== "" && !$to_dt)) {
        $errors[] = "Formato de fecha inválido. Usa DD-MM-AA o DD-MM-AAAA.";
    }
    if (!$errors) {
        if ($person === "" && $q !== "") {
            $people = find_people($available_roots, $q, $section ?: null, $limit);
        } else {
            $results = search_documents(
                $available_roots,
                $q,
                $person !== "" ? $person : null,
                $type ?: null,
                $section ?: null,
                $from_dt,
                $to_dt,
                $sustituto ?: null,
                $sustituido ?: null,
                $limit
            );
        }
    }
}

if ($results && $pdo instanceof PDO) {
    $stmt = $pdo->prepare("SELECT id, source_base, pdf_relpath, worker_name FROM contracts WHERE source_base = ? AND pdf_relpath = ? LIMIT 1");
    foreach ($results as &$r) {
        $stmt->execute([(string)$r["base"], (string)$r["rel"]]);
        $contract = $stmt->fetch();
        $r["contract_id"] = $contract ? (int)$contract["id"] : 0;
        $r["db_worker_name"] = $contract ? (string)($contract["worker_name"] ?? "") : "";
    }
    unset($r);
}

render_layout_start("Panel - Portal del trabajador", [
    "mode" => "app",
    "active" => "panel",
    "page_title" => "Panel",
    "page_subtitle" => "Bienvenido, " . ($_SESSION["user"] ?? ""),
]);
?>
<style>
  .icon-link{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:24px;
    height:24px;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
    color:#1e3a8a;
    text-decoration:none;
  }
  .icon-link:hover{background:#eef2ff;}
  .icon-link svg{width:14px;height:14px;display:block;}
  .name-inline{display:flex;gap:4px;align-items:center;flex-wrap:wrap;}
  .name-pill{display:inline-flex;align-items:center;padding:3px 7px;border-radius:999px;background:#f5f7fb;color:#0f172a;font-size:10px;font-weight:700;}
  .name-form[hidden]{display:none!important;}
  .inline-feedback{padding:6px 8px;border-radius:8px;font-size:11px;margin-bottom:8px;}
  .inline-feedback.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
  .inline-feedback.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
  .panel-hero{display:grid;gap:14px;}
  .panel-summary{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
  .panel-badges{display:flex;gap:8px;flex-wrap:wrap;}
  .panel-badge{display:inline-flex;align-items:center;padding:7px 10px;border-radius:999px;background:rgba(255,255,255,.82);border:1px solid rgba(148,163,184,.18);font-size:11px;font-weight:700;color:#314155;}
  .panel-intro{max-width:66ch}
  @media (min-width: 1024px){
    .panel-hero{grid-template-columns:2.2fr 1fr;}
  }
</style>
<div class="container">
  <div class="panel-hero">
    <div class="card glass-accent bento-card-lg">
      <span class="section-kicker">Explorador documental</span>
      <h2 class="section-title" style="margin-top:12px;">Buscar contratos</h2>
      <div class="muted panel-intro" style="margin-bottom:14px;">Localiza documentación por nombre, fechas, tipo y sección sin cambiar el flujo actual. Límite configurado: <?= $limit ?> resultados.</div>

      <form method="get" class="search">
        <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Nombre del trabajador">
        <input name="from" value="<?= htmlspecialchars($date_from) ?>" placeholder="Fecha inicio (DD-MM-AA)">
        <input name="to" value="<?= htmlspecialchars($date_to) ?>" placeholder="Fecha fin (DD-MM-AA)">
        <input name="type" value="<?= htmlspecialchars($type) ?>" placeholder="Tipo (389, 402, 410, 502, 510...)">
        <select name="section">
          <option value="">Sección: todas</option>
          <option value="contracts" <?= $section === "contracts" ? "selected" : "" ?>>Contratos</option>
          <option value="change" <?= $section === "change" ? "selected" : "" ?>>Cambio de contrato</option>
          <option value="calls" <?= $section === "calls" ? "selected" : "" ?>>Suspensiones y llamamientos</option>
        </select>
        <input name="sustituto" value="<?= htmlspecialchars($sustituto) ?>" placeholder="Nombre sustituto">
        <input name="sustituido" value="<?= htmlspecialchars($sustituido) ?>" placeholder="Nombre sustituido">
        <button class="btn" type="submit">Buscar</button>
      </form>
    </div>

    <div class="card bento-card-md card-flat">
      <span class="section-kicker">Filtros</span>
      <h3 class="section-title" style="margin-top:12px;">Cobertura de búsqueda</h3>
      <div class="panel-badges">
        <span class="panel-badge">Nombre</span>
        <span class="panel-badge">Fechas</span>
        <span class="panel-badge">Tipo</span>
        <span class="panel-badge">Sección</span>
        <span class="panel-badge">Sustituciones</span>
      </div>
      <p class="muted" style="margin:14px 0 0 0;">La lógica de búsqueda, edición de nombre y apertura de PDF se mantiene igual; aquí solo cambia la presentación visual.</p>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="card">
      <?php foreach ($errors as $e): ?>
        <div class="error"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (($q !== "" || $person !== "" || $has_filter) && !$errors): ?>
  <div class="container wide">
    <div class="card">
      <div class="panel-summary">
        <?php if ($person === "" && $q !== ""): ?>
          <div>
            <span class="section-kicker">Resultados</span>
            <div class="panel-badges" style="margin-top:10px;">
              <span class="panel-badge">Personas encontradas: <?= count($people) ?></span>
            </div>
          </div>
        <?php elseif ($person !== ""): ?>
          <div>
            <span class="section-kicker">Detalle</span>
            <div class="panel-badges" style="margin-top:10px;">
              <span class="panel-badge"><?= htmlspecialchars($person) ?></span>
              <span class="panel-badge"><?= count($results) ?> documento<?= count($results) === 1 ? "" : "s" ?></span>
            </div>
          </div>
          <a class="btn btn-ghost" href="panel.php?q=<?= rawurlencode($q) ?>&section=<?= rawurlencode($section) ?>">Volver a personas</a>
        <?php else: ?>
          <div>
            <span class="section-kicker">Resultados</span>
            <div class="panel-badges" style="margin-top:10px;">
              <span class="panel-badge"><?= count($results) ?> resultado<?= count($results) === 1 ? "" : "s" ?></span>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($person === "" && $q !== ""): ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Persona</th>
                <th>Coincidencias</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$people): ?>
                <tr><td colspan="3">Sin resultados</td></tr>
              <?php else: ?>
                <?php foreach ($people as $p): ?>
                  <tr>
                    <td><?= htmlspecialchars($p["name"]) ?></td>
                    <td><?= (int)$p["count"] ?></td>
                    <td><a href="panel.php?q=<?= rawurlencode($q) ?>&section=<?= rawurlencode($section) ?>&person=<?= rawurlencode($p["name"]) ?>">Ver documentos</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div id="panel-edit-feedback" hidden></div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Archivo</th>
                <th>Sección</th>
                <th>Origen</th>
                <th>PDF</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$results): ?>
                <tr><td colspan="5">Sin resultados</td></tr>
              <?php else: ?>
                <?php foreach ($results as $r): ?>
                  <tr>
                    <td>
                      <?php if ($isAdmin && !empty($r["contract_id"])): ?>
                        <div class="name-inline name-display">
                          <span class="name-pill"><?= htmlspecialchars($r["db_worker_name"] !== "" ? $r["db_worker_name"] : $r["person"]) ?></span>
                          <button class="btn btn-ghost name-edit-toggle" type="button">Editar</button>
                        </div>
                        <form method="post" action="dashboard.php" class="search name-form js-name-form" style="margin:4px 0 0 0;gap:4px;align-items:center;" hidden>
                          <input type="hidden" name="action" value="set_contract_worker_name">
                          <input type="hidden" name="contract_id" value="<?= (int)$r["contract_id"] ?>">
                          <input type="hidden" name="return_to" value="<?= htmlspecialchars('panel.php?q=' . rawurlencode($q) . '&section=' . rawurlencode($section) . '&person=' . rawurlencode($person)) ?>">
                          <input type="text" name="worker_name_new" value="<?= htmlspecialchars($r["db_worker_name"] !== "" ? $r["db_worker_name"] : $r["person"]) ?>" required>
                          <button class="btn btn-ghost" type="submit">Guardar</button>
                        </form>
                      <?php else: ?>
                        <?= htmlspecialchars($r["person"]) ?>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($r["name"]) ?></td>
                    <td><?= htmlspecialchars($r["section"]) ?></td>
                    <td><?= htmlspecialchars($r["base"]) ?></td>
                    <td>
                      <a
                        class="icon-link"
                        href="view.php?base=<?= rawurlencode($r["base"]) ?>&file=<?= rawurlencode($r["rel"]) ?>"
                        target="_blank"
                        rel="noopener"
                        title="Abrir PDF"
                        aria-label="Abrir PDF"
                      >
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
        <script>
          document.addEventListener("DOMContentLoaded", () => {
            const feedback = document.getElementById("panel-edit-feedback");
            const setFeedback = (ok, message) => {
              if (!feedback) return;
              feedback.hidden = false;
              feedback.className = "inline-feedback " + (ok ? "ok" : "err");
              feedback.textContent = message;
            };

            document.querySelectorAll(".name-edit-toggle").forEach((button) => {
              button.addEventListener("click", () => {
                const cell = button.closest("td");
                const form = cell ? cell.querySelector(".name-form") : null;
                if (form) form.hidden = !form.hidden;
              });
            });

            document.querySelectorAll(".js-name-form").forEach((form) => {
              form.addEventListener("submit", async (ev) => {
                ev.preventDefault();
                const submitBtn = form.querySelector("button[type='submit']");
                if (submitBtn) submitBtn.disabled = true;
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
                    setFeedback(false, (data && data.message) ? data.message : "No se pudo actualizar el nombre.");
                    return;
                  }
                  setFeedback(true, data.message || "Nombre actualizado.");
                  const cell = form.closest("td");
                  const display = cell ? cell.querySelector(".name-display") : null;
                  const pill = display ? display.querySelector(".name-pill") : null;
                  const input = form.querySelector("input[name='worker_name_new']");
                  if (pill && input) pill.textContent = input.value.trim();
                  form.hidden = true;
                } catch (e) {
                  setFeedback(false, "Error de red al actualizar el nombre.");
                } finally {
                  if (submitBtn) submitBtn.disabled = false;
                }
              });
            });
          });
        </script>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php render_layout_end(["mode" => "app"]); ?>

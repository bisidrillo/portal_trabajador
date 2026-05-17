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

$window = (int)($_GET["window"] ?? 3);
if (!in_array($window, [3, 6, 9, 12], true)) {
    $window = 3;
}
$department = trim((string)($_GET["department"] ?? ""));
$departments = [];
$rows = [];
$byDept = [];
$maxDept = 0;
$total = 0;
$warnings = [];
$statusOk = trim((string)($_GET["ok"] ?? ""));
$statusErr = trim((string)($_GET["err"] ?? ""));
$departmentOptions = canonical_department_options();

try {
    $pdo = require __DIR__ . "/db.php";
    if (!table_exists($pdo, "contracts")) {
        $warnings[] = "No existe la tabla contracts.";
    } else {
        $required = ["start_date", "department", "worker_name", "contract_type", "end_date", "contract_code"];
        foreach ($required as $col) {
            if (!column_exists($pdo, "contracts", $col)) {
                $warnings[] = "Falta la columna contracts.{$col}.";
            }
        }
        if (!$warnings) {
            $stmt = $pdo->query(
                "SELECT DISTINCT TRIM(department) AS dep
                 FROM contracts
                 WHERE department IS NOT NULL AND TRIM(department) <> ''
                 ORDER BY dep"
            );
            $departments = array_values(array_filter(array_map(static function ($v): string {
                return trim((string)$v);
            }, $stmt->fetchAll(PDO::FETCH_COLUMN)), static function (string $v): bool {
                return $v !== "";
            }));

            $sql = "
                SELECT id, worker_name, contract_type, department, start_date, end_date, contract_code
                FROM contracts
                WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                  AND start_date <= CURDATE()
            ";
            $params = [$window];
            if ($department !== "") {
                $sql .= " AND TRIM(COALESCE(department,'')) = ?";
                $params[] = $department;
            }
            $sql .= " ORDER BY start_date DESC, worker_name ASC LIMIT 1200";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $total = count($rows);

            $deptSql = "
                SELECT COALESCE(NULLIF(TRIM(department), ''), 'Sin departamento') AS department_name,
                       COUNT(*) AS hires_total
                FROM contracts
                WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                  AND start_date <= CURDATE()
            ";
            $deptParams = [$window];
            if ($department !== "") {
                $deptSql .= " AND TRIM(COALESCE(department,'')) = ?";
                $deptParams[] = $department;
            }
            $deptSql .= " GROUP BY department_name ORDER BY hires_total DESC, department_name ASC";
            $stmt = $pdo->prepare($deptSql);
            $stmt->execute($deptParams);
            $byDept = $stmt->fetchAll();
            foreach ($byDept as $r) {
                $maxDept = max($maxDept, (int)($r["hires_total"] ?? 0));
            }
        }
    }
} catch (Throwable $e) {
    $warnings[] = "No se pudo cargar la analítica de altas.";
}

render_layout_start("Analítica de altas - Portal del trabajador", [
    "mode" => "app",
    "active" => "dashboard",
    "page_title" => "Analítica de altas",
    "page_subtitle" => "Detalle por periodo y departamento",
]);
?>
<style>
  .split{display:grid;gap:14px;grid-template-columns:1fr;}
  .mini-row{display:grid;grid-template-columns:minmax(180px,1.2fr) minmax(120px,3fr) auto;gap:10px;align-items:center;margin:8px 0;}
  .mini-track{height:10px;border-radius:999px;background:#dbeafe;overflow:hidden;}
  .mini-fill{height:100%;background:linear-gradient(90deg,#2563eb,#06b6d4);border-radius:999px;}
  .mini-val{font-size:12px;font-weight:700;color:#1f2937;white-space:nowrap;}
  .analytics-kpis{display:grid;gap:14px;grid-template-columns:1fr;}
  .analytics-kpi-card{display:flex;flex-direction:column;justify-content:space-between;min-height:170px;}
  .analytics-kpi-value{font-family:"Space Grotesk",sans-serif;font-size:52px;line-height:.95;letter-spacing:-.05em;margin:8px 0 10px 0;}
  .analytics-intro{max-width:62ch}
  .hires-table{width:100%;table-layout:fixed;}
  .hires-table th,.hires-table td{white-space:normal;vertical-align:middle;word-break:normal;}
  .hires-table .col-date{width:12%;white-space:nowrap;}
  .hires-table .col-worker{width:38%;}
  .hires-table .col-type{width:10%;text-align:center;white-space:nowrap;}
  .hires-table .col-dept{width:26%;}
  .hires-table .col-end{width:14%;white-space:nowrap;}
  .dept-edit{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:4px;align-items:center;}
  .dept-edit select{min-width:0;padding:4px 6px;border:1px solid var(--border);border-radius:8px;background:#f9fafb;font-size:11px;}
  .dept-chip{display:inline-flex;align-items:center;padding:3px 7px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:10px;font-weight:700;}
  .dept-chip.missing{background:#fff7ed;color:#9a3412;}
  .dept-inline{display:flex;gap:4px;align-items:center;flex-wrap:wrap;}
  .name-inline{display:flex;gap:4px;align-items:center;flex-wrap:wrap;}
  .name-pill{display:inline-flex;align-items:center;padding:3px 7px;border-radius:999px;background:#f5f7fb;color:#0f172a;font-size:10px;font-weight:700;}
  .row-highlight{background:#fffdfa;}
  .row-fade{opacity:.45;transition:opacity .18s ease;}
  .inline-feedback{padding:6px 8px;border-radius:8px;font-size:11px;margin-bottom:8px;}
  .inline-feedback.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
  .inline-feedback.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
  .edit-toggle[hidden]{display:none!important;}
  .dept-form[hidden]{display:none!important;}
  .name-form[hidden]{display:none!important;}
  @media (min-width: 1024px){ .split{grid-template-columns:1.1fr 1fr;} .analytics-kpis{grid-template-columns:1.2fr .8fr;} }
  @media (max-width: 900px){
    .mini-row{grid-template-columns:1fr;}
    .hires-table .col-dept{display:none;}
    .hires-table .col-date{width:18%;}
    .hires-table .col-worker{width:46%;}
    .hires-table .col-type{width:14%;}
    .hires-table .col-end{width:22%;}
  }
</style>

<div class="container wide">
  <?php if ($statusOk !== ""): ?>
    <div class="card"><div class="success"><?= htmlspecialchars($statusOk) ?></div></div>
  <?php endif; ?>
  <?php if ($statusErr !== ""): ?>
    <div class="card"><div class="error"><?= htmlspecialchars($statusErr) ?></div></div>
  <?php endif; ?>

  <?php foreach ($warnings as $w): ?>
    <div class="card"><div class="error"><?= htmlspecialchars($w) ?></div></div>
  <?php endforeach; ?>

  <div class="card glass-accent">
    <span class="section-kicker">Altas</span>
    <h2 class="section-title" style="margin-top:12px;">Analítica de incorporaciones</h2>
    <p class="muted analytics-intro" style="margin:0 0 14px 0;">Reordeno la vista como dashboard Bento, pero mantengo igual el cálculo por periodo, departamento y la edición rápida de nombres y departamentos.</p>
    <form method="get" class="search">
      <select name="window">
        <option value="3" <?= $window === 3 ? "selected" : "" ?>>Últimos 3 meses</option>
        <option value="6" <?= $window === 6 ? "selected" : "" ?>>Últimos 6 meses</option>
        <option value="9" <?= $window === 9 ? "selected" : "" ?>>Últimos 9 meses</option>
        <option value="12" <?= $window === 12 ? "selected" : "" ?>>Últimos 12 meses</option>
      </select>
      <select name="department">
        <option value="">Departamento: todos</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= htmlspecialchars((string)$d) ?>" <?= $department === (string)$d ? "selected" : "" ?>><?= htmlspecialchars((string)$d) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit">Calcular</button>
      <a href="altas_analytics.php" class="btn btn-ghost">Limpiar</a>
    </form>
  </div>

  <div class="analytics-kpis">
    <div class="card analytics-kpi-card">
      <div>
        <div class="kpi-label">Altas en periodo</div>
        <div class="analytics-kpi-value"><?= (int)$total ?></div>
      </div>
      <div class="muted">Contratos con `start_date` en los últimos <?= (int)$window ?> meses.</div>
    </div>
    <div class="card card-flat">
      <div class="kpi-label">Filtro actual</div>
      <div class="metric-strip" style="margin-top:10px;">
        <span class="metric-chip"><?= $window ?> meses</span>
        <span class="metric-chip"><?= $department !== "" ? htmlspecialchars($department) : "Todos los departamentos" ?></span>
      </div>
      <p class="muted" style="margin:14px 0 0 0;">Los contratos destacados siguen siendo los mismos; solo cambia la organización visual.</p>
    </div>
  </div>

  <div class="split">
    <div class="card">
      <h3 style="margin:0 0 8px 0;">Distribución por departamento</h3>
      <?php if (!$byDept): ?>
        <div class="muted">Sin altas por departamento en este filtro.</div>
      <?php else: ?>
        <?php foreach ($byDept as $r): ?>
          <?php
            $cnt = (int)($r["hires_total"] ?? 0);
            $pct = $maxDept > 0 ? (int)round(($cnt / $maxDept) * 100) : 0;
          ?>
          <div class="mini-row">
            <div><?= htmlspecialchars((string)$r["department_name"]) ?></div>
            <div class="mini-track"><div class="mini-fill" style="width: <?= max(2, $pct) ?>%;"></div></div>
            <div class="mini-val"><?= $cnt ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 10px 0;">Contratos que componen el cálculo de altas</h2>
    <div id="dept-feedback" hidden></div>
    <div class="table-wrap">
      <table class="hires-table">
        <thead>
          <tr>
            <th class="col-date">Inicio</th>
            <th class="col-worker">Trabajador</th>
            <th class="col-type">Tipo</th>
            <th class="col-dept">Departamento</th>
            <th class="col-end">Fin</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5">Sin contratos en el periodo/filtro seleccionado.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php $currentDepartment = trim((string)($r["department"] ?? "")); ?>
              <tr class="<?= $currentDepartment === "" ? "row-highlight" : "" ?>" data-worker-name="<?= htmlspecialchars((string)($r["worker_name"] ?? ""), ENT_QUOTES) ?>">
                <td class="col-date"><?= htmlspecialchars(format_date_es((string)($r["start_date"] ?? ""))) ?></td>
                <td class="col-worker">
                  <div class="name-inline name-display">
                    <span class="name-pill"><?= htmlspecialchars((string)($r["worker_name"] ?? "")) ?></span>
                    <button class="btn btn-ghost name-edit-toggle" type="button">Editar</button>
                  </div>
                  <form method="post" action="dashboard.php" class="search name-form js-name-form" style="margin:4px 0 0 0;gap:4px;align-items:center;" hidden>
                    <input type="hidden" name="action" value="set_contract_worker_name">
                    <input type="hidden" name="contract_id" value="<?= (int)$r["id"] ?>">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars('altas_analytics.php?window=' . (int)$window . '&department=' . rawurlencode($department)) ?>">
                    <input type="text" name="worker_name_new" value="<?= htmlspecialchars((string)($r["worker_name"] ?? "")) ?>" required>
                    <button class="btn btn-ghost" type="submit">Guardar</button>
                  </form>
                </td>
                <td class="col-type"><?= htmlspecialchars((string)($r["contract_type"] ?? "")) ?></td>
                <td class="col-dept">
                  <?php if ($currentDepartment !== ""): ?>
                    <div class="dept-inline dept-display">
                      <span class="dept-chip"><?= htmlspecialchars($currentDepartment) ?></span>
                      <button class="btn btn-ghost edit-toggle" type="button">Editar</button>
                    </div>
                  <?php else: ?>
                    <div class="dept-inline dept-display">
                      <span class="dept-chip missing">Sin departamento</span>
                      <button class="btn btn-ghost edit-toggle" type="button">Editar</button>
                    </div>
                  <?php endif; ?>
                  <form method="post" action="dashboard.php" class="dept-edit dept-form js-dept-form" style="margin:4px 0 0 0;" hidden>
                    <input type="hidden" name="action" value="set_contract_department">
                    <input type="hidden" name="contract_id" value="<?= (int)$r["id"] ?>">
                    <input type="hidden" name="worker_name" value="<?= htmlspecialchars((string)($r["worker_name"] ?? "")) ?>">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars('altas_analytics.php?window=' . (int)$window . '&department=' . rawurlencode($department)) ?>">
                    <select name="department_new" required>
                      <?php foreach ($departmentOptions as $dep): ?>
                        <option value="<?= htmlspecialchars($dep) ?>" <?= ($currentDepartment === $dep) ? "selected" : "" ?>><?= htmlspecialchars($dep) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-ghost" type="submit">Guardar</button>
                    <?php if ($currentDepartment === ""): ?>
                      <button class="btn btn-ghost js-batch-btn" type="button">Lote</button>
                    <?php endif; ?>
                  </form>
                </td>
                <td class="col-end"><?= htmlspecialchars(format_date_es((string)($r["end_date"] ?? ""))) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    const feedback = document.getElementById("dept-feedback");
    const setFeedback = (ok, message) => {
      if (!feedback) return;
      feedback.hidden = false;
      feedback.className = "inline-feedback " + (ok ? "ok" : "err");
      feedback.textContent = message;
    };

    document.querySelectorAll(".edit-toggle").forEach((button) => {
      button.addEventListener("click", () => {
        const cell = button.closest(".col-dept");
        const form = cell ? cell.querySelector(".dept-form") : null;
        if (form) {
          form.hidden = !form.hidden;
        }
      });
    });

    document.querySelectorAll(".name-edit-toggle").forEach((button) => {
      button.addEventListener("click", () => {
        const cell = button.closest(".col-worker");
        const form = cell ? cell.querySelector(".name-form") : null;
        if (form) {
          form.hidden = !form.hidden;
        }
      });
    });

    document.querySelectorAll(".js-dept-form").forEach((form) => {
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
            setFeedback(false, (data && data.message) ? data.message : "No se pudo actualizar el departamento.");
            return;
          }
          setFeedback(true, data.message || "Departamento actualizado.");
          const cell = form.closest(".col-dept");
          const display = cell ? cell.querySelector(".dept-display") : null;
          const chip = display ? display.querySelector(".dept-chip") : null;
          let editBtn = display ? display.querySelector(".edit-toggle") : null;
          const select = form.querySelector("select[name='department_new']");
          const selectedText = select ? select.options[select.selectedIndex].text : "";
          if (chip && selectedText !== "") {
            chip.textContent = selectedText;
            chip.classList.remove("missing");
          }
          if (display && !editBtn) {
            editBtn = document.createElement("button");
            editBtn.type = "button";
            editBtn.className = "btn btn-ghost edit-toggle";
            editBtn.textContent = "Editar";
            editBtn.addEventListener("click", () => {
              form.hidden = !form.hidden;
            });
            display.appendChild(editBtn);
          }
          if (form) {
            form.hidden = true;
          }
          const row = form.closest("tr");
          if (row) {
            row.classList.remove("row-highlight");
            row.classList.remove("row-fade");
          }
        } catch (e) {
          setFeedback(false, "Error de red al actualizar el departamento.");
        } finally {
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    });

    document.querySelectorAll(".js-batch-btn").forEach((button) => {
      button.addEventListener("click", async () => {
        const form = button.closest(".js-dept-form");
        if (!form) return;
        button.disabled = true;
        try {
          const fd = new FormData(form);
          fd.set("ajax", "1");
          fd.set("action", "set_contract_department_batch");
          const res = await fetch("dashboard.php", {
            method: "POST",
            body: fd,
            headers: { "X-Requested-With": "XMLHttpRequest" },
          });
          const data = await res.json();
          if (!data || !data.ok) {
            setFeedback(false, (data && data.message) ? data.message : "No se pudo aplicar el lote.");
            return;
          }
          setFeedback(true, data.message || "Lote aplicado.");
          const workerName = fd.get("worker_name");
          const select = form.querySelector("select[name='department_new']");
          const selectedText = select ? select.options[select.selectedIndex].text : "";
          document.querySelectorAll(`tr[data-worker-name="${CSS.escape(String(workerName))}"]`).forEach((row) => {
            const cell = row.querySelector(".col-dept");
            const display = cell ? cell.querySelector(".dept-display") : null;
            const chip = display ? display.querySelector(".dept-chip") : null;
            const editBtn = display ? display.querySelector(".edit-toggle") : null;
            const deptForm = cell ? cell.querySelector(".dept-form") : null;
            if (chip && selectedText !== "") {
              chip.textContent = selectedText;
              chip.classList.remove("missing");
            }
            if (display && !editBtn) {
              const newEditBtn = document.createElement("button");
              newEditBtn.type = "button";
              newEditBtn.className = "btn btn-ghost edit-toggle";
              newEditBtn.textContent = "Editar";
              newEditBtn.addEventListener("click", () => {
                if (deptForm) deptForm.hidden = !deptForm.hidden;
              });
              display.appendChild(newEditBtn);
            }
            if (deptForm) {
              deptForm.hidden = true;
              const batch = deptForm.querySelector(".js-batch-btn");
              if (batch) batch.remove();
            }
            row.classList.remove("row-highlight");
          });
        } catch (e) {
          setFeedback(false, "Error de red al aplicar el lote.");
        } finally {
          button.disabled = false;
        }
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
          const cell = form.closest(".col-worker");
          const display = cell ? cell.querySelector(".name-display") : null;
          const pill = display ? display.querySelector(".name-pill") : null;
          const input = form.querySelector("input[name='worker_name_new']");
          if (pill && input) {
            pill.textContent = input.value.trim();
          }
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
<?php render_layout_end(["mode" => "app"]); ?>

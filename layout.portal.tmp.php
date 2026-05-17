<?php
declare(strict_types=1);

require_once __DIR__ . "/menu.php";

function asset_href(string $relativePath): string
{
    $full = __DIR__ . "/../" . ltrim($relativePath, "/");
    if (is_file($full)) {
        return "/" . ltrim($relativePath, "/") . "?v=" . filemtime($full);
    }
    return "/" . ltrim($relativePath, "/");
}

function render_layout_start(string $title, array $options = []): void
{
    $mode = $options["mode"] ?? "app";
    $active = $options["active"] ?? "";
    $page_title = $options["page_title"] ?? "";
    $page_subtitle = $options["page_subtitle"] ?? "";
    $is_admin = !empty($_SESSION["is_admin"]);

    $cssFile = __DIR__ . "/../public/assets/app.css";
    $jsFile = __DIR__ . "/../public/assets/app.js";
    $hasBuiltAssets = is_file($cssFile) && is_file($jsFile);

    echo "<!doctype html>\n";
    echo "<html lang=\"es\">\n";
    echo "<head>\n";
    echo "  <meta charset=\"utf-8\">\n";
    echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "  <title>" . htmlspecialchars($title) . "</title>\n";
    if ($hasBuiltAssets) {
        echo "  <link rel=\"stylesheet\" href=\"" . htmlspecialchars(asset_href("public/assets/app.css")) . "\">\n";
        echo "  <script type=\"module\" src=\"" . htmlspecialchars(asset_href("public/assets/app.js")) . "\" defer></script>\n";
        echo "  <style>[x-cloak]{display:none!important;}</style>\n";
    } else {
        echo "  <script defer src=\"https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js\"></script>\n";
        echo "  <style>\n";
        echo "    [x-cloak]{display:none!important;}\n";
        echo "    @import url(\"https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap\");\n";
        echo "    :root{--bg1:#f3f7f2;--bg2:#e6eef0;--bg3:#f8f3eb;--card:rgba(255,255,255,.78);--card-strong:#ffffff;--text:#102131;--muted:#5f7082;--accent:#0f766e;--accent-2:#2563eb;--accent-3:#f59e0b;--border:rgba(148,163,184,.24);--shadow:0 20px 50px rgba(15,23,42,.08);--shadow-soft:0 10px 30px rgba(15,23,42,.06);--radius:24px;--radius-sm:16px;}\n";
        echo "    *{box-sizing:border-box} html{scroll-behavior:smooth} body{margin:0;font-family:\"IBM Plex Sans\",sans-serif;color:var(--text);font-size:14px;line-height:1.45;background:linear-gradient(180deg,var(--bg1),var(--bg2) 52%,var(--bg3)) fixed} a{text-decoration:none;color:inherit}\n";
        echo "    body::before{content:\"\";position:fixed;inset:0;background:radial-gradient(900px 420px at 0% 0%,rgba(15,118,110,.10),transparent 60%),radial-gradient(760px 360px at 100% 0%,rgba(37,99,235,.10),transparent 58%),radial-gradient(640px 320px at 50% 100%,rgba(245,158,11,.08),transparent 58%);pointer-events:none;z-index:-1}\n";
        echo "    .btn{border:none;padding:10px 14px;border-radius:14px;font-weight:700;font-size:12px;letter-spacing:.01em;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;cursor:pointer;box-shadow:0 12px 28px rgba(15,118,110,.22);transition:transform .18s ease,box-shadow .18s ease,opacity .18s ease}\n";
        echo "    .btn:hover{transform:translateY(-1px);box-shadow:0 16px 34px rgba(15,118,110,.26)} .btn:disabled{opacity:.65;cursor:not-allowed;transform:none}\n";
        echo "    .btn-ghost{background:rgba(255,255,255,.72);color:var(--text);border:1px solid var(--border);box-shadow:none}\n";
        echo "    .card{position:relative;background:var(--card);backdrop-filter:blur(18px);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:18px;margin-bottom:14px;overflow:hidden}\n";
        echo "    .card::after{content:\"\";position:absolute;inset:auto -20% -55% auto;width:180px;height:180px;background:radial-gradient(circle,rgba(255,255,255,.55),transparent 68%);pointer-events:none}\n";
        echo "    .muted{color:var(--muted)} .title{font-family:\"Space Grotesk\",sans-serif;font-size:30px;line-height:1.05;margin:0;letter-spacing:-.04em}\n";
        echo "    .section-title{font-family:\"Space Grotesk\",sans-serif;font-size:22px;line-height:1.1;margin:0 0 6px 0;letter-spacing:-.03em}\n";
        echo "    .section-kicker{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:rgba(15,118,110,.10);color:var(--accent);font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}\n";
        echo "    .error{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3;padding:10px 12px;border-radius:14px;font-size:12px}\n";
        echo "    .success{background:#ecfdf5;color:#166534;border:1px solid #a7f3d0;padding:10px 12px;border-radius:14px;font-size:12px}\n";
        echo "    .table-wrap{width:100%;overflow-x:auto;border-radius:18px;border:1px solid rgba(148,163,184,.18);background:rgba(255,255,255,.76);box-shadow:inset 0 1px 0 rgba(255,255,255,.65)} table{border-collapse:collapse;width:100%;min-width:0}\n";
        echo "    th,td{padding:11px 12px;text-align:left;border-bottom:1px solid rgba(148,163,184,.16);font-size:12px;word-break:normal;overflow-wrap:break-word} th{background:rgba(241,245,249,.85);font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#405266} tbody tr:hover td{background:rgba(248,250,252,.7)}\n";
        echo "    .search{display:flex;gap:10px;flex-wrap:wrap;align-items:center} .search input,.search select{flex:1;min-width:220px;padding:12px 14px;border:1px solid rgba(148,163,184,.24);border-radius:16px;background:rgba(255,255,255,.88);font-size:13px;outline:none;color:var(--text);box-shadow:var(--shadow-soft)}\n";
        echo "    .search input:focus,.search select:focus{border-color:rgba(15,118,110,.45);box-shadow:0 0 0 4px rgba(15,118,110,.12),var(--shadow-soft);background:#fff}\n";
        echo "    .mode-auth{min-height:100vh;display:grid;place-items:center;padding:20px}\n";
        echo "    .mode-app{min-height:100vh}\n";
        echo "    .app-shell{min-height:100vh}.main{padding:20px}.topbar{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:18px;margin:0 auto 18px auto;max-width:1380px;align-items:stretch}\n";
        echo "    .topbar-panel{background:linear-gradient(145deg,rgba(255,255,255,.82),rgba(255,255,255,.62));border:1px solid var(--border);box-shadow:var(--shadow);border-radius:30px;padding:22px 24px;backdrop-filter:blur(18px)}\n";
        echo "    .title-copy{display:flex;flex-direction:column;gap:10px}.page-subtitle{font-size:14px;max-width:60ch}\n";
        echo "    .nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-content:start}.nav a{padding:14px 16px;border-radius:18px;color:var(--muted);font-size:13px;font-weight:700;background:rgba(255,255,255,.76);border:1px solid var(--border);box-shadow:var(--shadow-soft);transition:transform .18s ease,border-color .18s ease,background .18s ease,color .18s ease} .nav a:hover{transform:translateY(-1px);border-color:rgba(15,118,110,.24);color:var(--text)} .nav a.active{background:linear-gradient(135deg,rgba(15,118,110,.14),rgba(37,99,235,.12));color:#0f172a;border-color:rgba(15,118,110,.28)}\n";
        echo "    .container{max-width:1080px;margin:0 auto 14px auto}.container.wide{max-width:1380px}\n";
        echo "    .bento-grid{display:grid;grid-template-columns:1fr;gap:14px}.bento-stack{display:grid;gap:14px}.bento-card-lg{min-height:220px}.bento-card-md{min-height:160px}.bento-card-sm{min-height:120px}.glass-accent{background:linear-gradient(145deg,rgba(255,255,255,.86),rgba(235,248,255,.72))}.card-flat{box-shadow:var(--shadow-soft)}\n";
        echo "    .metric-strip{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.metric-chip{display:inline-flex;align-items:center;padding:7px 10px;border-radius:999px;background:rgba(255,255,255,.82);border:1px solid rgba(148,163,184,.18);font-size:11px;font-weight:700;color:#314155}\n";
        echo "    .auth-main{width:100%;display:grid;place-items:center}.auth-card{width:100%;max-width:560px}.auth-card.narrow{max-width:440px}\n";
        echo "    .field{margin:10px 0}.field label{display:block;font-size:11px;color:var(--muted);margin-bottom:6px;font-weight:700;letter-spacing:.03em;text-transform:uppercase}\n";
        echo "    .field input{width:100%;padding:12px 14px;border:1px solid rgba(148,163,184,.24);border-radius:16px;font-size:13px;outline:none;background:rgba(255,255,255,.88);box-shadow:var(--shadow-soft)}.field input:focus{border-color:rgba(15,118,110,.45);box-shadow:0 0 0 4px rgba(15,118,110,.12),var(--shadow-soft);background:#fff}\n";
        echo "    .row{display:grid;grid-template-columns:1fr;gap:12px}\n";
        echo "    @media (min-width:720px){.row{grid-template-columns:1fr 1fr}.bento-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.bento-col-2{grid-column:span 2}.nav{grid-template-columns:repeat(3,minmax(0,1fr))}}\n";
        echo "    @media (min-width:1160px){.bento-grid{grid-template-columns:repeat(6,minmax(0,1fr))}.bento-col-2{grid-column:span 2}.bento-col-3{grid-column:span 3}.bento-col-4{grid-column:span 4}.bento-col-6{grid-column:span 6}}\n";
        echo "    @media (max-width:1100px){.topbar{grid-template-columns:1fr}.nav{grid-template-columns:repeat(2,minmax(0,1fr))}}\n";
        echo "    @media (max-width:960px){.main{padding:14px}.topbar-panel{padding:18px}.title{font-size:24px}.nav{grid-template-columns:1fr 1fr}}\n";
        echo "    @media (max-width:640px){.nav{grid-template-columns:1fr}.search input,.search select{min-width:100%}.card{padding:16px}.section-title{font-size:19px}}\n";
        echo "  </style>\n";
    }
    echo "</head>\n";

    $bodyClass = $mode === "auth" ? "mode-auth" : "mode-app";
    echo "<body class=\"" . $bodyClass . "\" x-data>\n";

    if ($mode === "auth") {
        echo "  <main class=\"auth-main\">\n";
        return;
    }

    $menu = build_main_menu($is_admin);
    echo "  <div class=\"app-shell\">\n";
    echo "    <main class=\"main\">\n";
    if ($page_title !== "") {
        echo "      <div class=\"topbar\">\n";
        echo "        <div class=\"topbar-panel title-copy\">\n";
        echo "          <span class=\"section-kicker\">Portal laboral</span>\n";
        echo "          <h1 class=\"title\">" . htmlspecialchars($page_title) . "</h1>\n";
        if ($page_subtitle !== "") {
            echo "          <div class=\"muted page-subtitle\">" . htmlspecialchars($page_subtitle) . "</div>\n";
        }
        echo "          <div class=\"metric-strip\"><span class=\"metric-chip\">Diseño modular</span><span class=\"metric-chip\">Vista segura</span><span class=\"metric-chip\">Flujo actual intacto</span></div>\n";
        echo "        </div>\n";
        echo "        <nav class=\"nav topbar-panel\">\n";
        foreach ($menu as $item) {
            $class = ($active === $item["id"]) ? "active" : "";
            echo "          <a class=\"" . $class . "\" href=\"" . htmlspecialchars($item["href"]) . "\">" . htmlspecialchars($item["label"]) . "</a>\n";
        }
        echo "        </nav>\n";
        echo "      </div>\n";
    }
}

function render_layout_end(array $options = []): void
{
    $mode = $options["mode"] ?? "app";
    if ($mode === "auth") {
        echo "  </main>\n";
        echo "</body>\n";
        echo "</html>\n";
        return;
    }
    echo "    </main>\n";
    echo "  </div>\n";
    echo "</body>\n";
    echo "</html>\n";
}

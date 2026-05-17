<?php
declare(strict_types=1);

const SOURCE_BASE = "NAS";

$apply = in_array("--apply", $argv ?? [], true);

$aliasMap = [
    "RESTURANTE" => "RESTAURANTE",
    "RESRTAURANTE" => "RESTAURANTE",
    "RESTAURANTES" => "RESTAURANTE",
];

$roots = require "/Volumes/web/portal_trabajador/config.php";
$pdo = require "/Volumes/web/portal_trabajador/db.php";

$root = trim((string)($roots[SOURCE_BASE] ?? ""));
if ($root === "" || !is_dir($root)) {
    throw new RuntimeException("No se pudo resolver la ruta NAS.");
}
$root = rtrim(str_replace("\\", "/", $root), "/");

$summary = [
    "mode" => $apply ? "APPLY" : "DRY-RUN",
    "files_examined" => 0,
    "file_moves_needed" => 0,
    "file_moves_done" => 0,
    "db_rows_updated" => 0,
    "db_alias_rows_normalized" => 0,
    "dirs_removed" => 0,
];
$samples = [];

$updateByRel = $pdo->prepare(
    "UPDATE contracts
     SET department = ?, pdf_relpath = ?, source_filename = ?, updated_at = CURRENT_TIMESTAMP
     WHERE source_base = ? AND pdf_relpath = ?"
);
$updateByFilename = $pdo->prepare(
    "UPDATE contracts
     SET department = ?, pdf_relpath = ?, source_filename = ?, updated_at = CURRENT_TIMESTAMP
     WHERE source_base = ? AND source_filename = ? AND contract_type = ?"
);
$normalizeAliasStmt = $pdo->prepare(
    "UPDATE contracts
     SET department = ?, updated_at = CURRENT_TIMESTAMP
     WHERE source_base = ? AND TRIM(COALESCE(department, '')) = ?"
);

if ($apply) {
    $pdo->beginTransaction();
}

try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );

    foreach ($iterator as $file) {
        if (!($file instanceof SplFileInfo) || !$file->isFile()) {
            continue;
        }
        if (!preg_match('/\.pdf$/i', $file->getFilename())) {
            continue;
        }

        $summary["files_examined"]++;
        $fullPath = str_replace("\\", "/", $file->getPathname());
        $relativePath = ltrim(substr($fullPath, strlen($root)), "/");
        $parts = explode("/", $relativePath);
        if (count($parts) < 3) {
            continue;
        }

        $typeFolder = trim((string)$parts[0]);
        $departmentFolder = trim((string)$parts[1]);
        $filename = basename($fullPath);
        $canonicalDepartment = $aliasMap[strtoupper($departmentFolder)] ?? $departmentFolder;
        $newFilename = rewriteFilenameDepartment($filename, $aliasMap);

        if ($canonicalDepartment === $departmentFolder && $newFilename === $filename) {
            continue;
        }

        $summary["file_moves_needed"]++;
        if (count($samples) < 20) {
            $samples[] = $relativePath . " => " . $typeFolder . "/" . $canonicalDepartment . "/" . $newFilename;
        }

        if (!$apply) {
            continue;
        }

        $targetDirectory = $root . "/" . $typeFolder . "/" . $canonicalDepartment;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException("No se pudo crear {$targetDirectory}");
        }

        $targetPath = uniqueTargetPath($targetDirectory, $newFilename, $fullPath);
        if ($targetPath !== $fullPath && !rename($fullPath, $targetPath)) {
            throw new RuntimeException("No se pudo mover {$relativePath}");
        }

        $newRelativePath = ltrim(substr(str_replace("\\", "/", $targetPath), strlen($root)), "/");

        $updateByRel->execute([$canonicalDepartment, $newRelativePath, basename($targetPath), SOURCE_BASE, $relativePath]);
        $affected = (int)$updateByRel->rowCount();
        if ($affected === 0) {
            $updateByFilename->execute([$canonicalDepartment, $newRelativePath, basename($targetPath), SOURCE_BASE, $filename, $typeFolder]);
            $affected = (int)$updateByFilename->rowCount();
        }
        $summary["db_rows_updated"] += $affected;
        $summary["file_moves_done"]++;
    }

    foreach ($aliasMap as $alias => $canonical) {
        $normalizeAliasStmt->execute([$canonical, SOURCE_BASE, $alias]);
        $summary["db_alias_rows_normalized"] += (int)$normalizeAliasStmt->rowCount();
    }

    foreach (array_keys($aliasMap) as $alias) {
        $dirs = findAliasDirectories($root, $alias);
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $items = scandir($dir);
            if ($items === false) {
                continue;
            }
            $visibleItems = array_values(array_filter($items, static function (string $item): bool {
                return $item !== "." && $item !== "..";
            }));
            if ($visibleItems === [] && @rmdir($dir)) {
                $summary["dirs_removed"]++;
            }
        }
    }

    if ($apply) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Repair department aliases [" . $summary["mode"] . "]\n";
foreach ($summary as $key => $value) {
    echo $key . ": " . $value . "\n";
}
if ($samples) {
    echo "Samples:\n- " . implode("\n- ", $samples) . "\n";
}

function rewriteFilenameDepartment(string $filename, array $aliasMap): string
{
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $parts = preg_split('/_+/u', $base) ?: [];
    $updated = false;

    foreach ($parts as $index => $part) {
        $trimmed = trim((string)$part);
        $upper = strtoupper($trimmed);
        if (isset($aliasMap[$upper])) {
            $parts[$index] = $aliasMap[$upper];
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        return $filename;
    }

    $rebuilt = implode("_", array_map(static fn($part): string => (string)$part, $parts));
    return $extension !== "" ? $rebuilt . "." . $extension : $rebuilt;
}

function uniqueTargetPath(string $directory, string $filename, string $currentPath): string
{
    $directory = rtrim($directory, "/");
    $candidate = $directory . "/" . $filename;
    if ($candidate === $currentPath || !file_exists($candidate)) {
        return $candidate;
    }

    $name = pathinfo($filename, PATHINFO_FILENAME);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $counter = 1;
    do {
        $candidate = $directory . "/" . $name . "_" . $counter . ($extension !== "" ? "." . $extension : "");
        $counter++;
    } while (file_exists($candidate) && $candidate !== $currentPath);

    return $candidate;
}

function findAliasDirectories(string $root, string $alias): array
{
    $dirs = [];
    $rootItems = scandir($root);
    if ($rootItems === false) {
        return [];
    }

    foreach ($rootItems as $item) {
        if ($item === "." || $item === "..") {
            continue;
        }
        $typeDir = $root . "/" . $item;
        if (!is_dir($typeDir)) {
            continue;
        }
        $aliasDir = $typeDir . "/" . $alias;
        if (is_dir($aliasDir)) {
            $dirs[] = $aliasDir;
        }
    }

    return $dirs;
}

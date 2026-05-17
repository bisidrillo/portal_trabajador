<?php
declare(strict_types=1);

require_once "/Volumes/web/portal_trabajador/includes/contract_utils.php";

$sourceFile = $argv[1] ?? "/Volumes/web/portal_trabajador/import_contracts.php";
$lines = file($sourceFile);
$code = "";
$stopIndex = count($lines);
foreach ($lines as $index => $line) {
    if (strpos($line, '$isCli = PHP_SAPI === "cli";') !== false) {
        $stopIndex = $index;
        break;
    }
}
foreach ($lines as $index => $line) {
    if ($index >= 6 && $index < $stopIndex) {
        $code .= $line;
    }
}
eval($code);

$root = "/Volumes/CONTRATOS/CONTRATOS";
$rootNorm = rtrim(str_replace("\\", "/", $root), "/");
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
    RecursiveIteratorIterator::CATCH_GET_CHILD
);

$total = 0;
$parsedOk = 0;
$skipped = [];

foreach ($iterator as $file) {
    if (!($file instanceof SplFileInfo) || !$file->isFile()) {
        continue;
    }

    $filename = $file->getFilename();
    if (!preg_match('/\.pdf$/i', $filename)) {
        continue;
    }

    $total++;
    $full = str_replace("\\", "/", $file->getPathname());
    $rel = ltrim(substr($full, strlen($rootNorm)), "/");

    $meta = parse_contract_filename($filename, $rel);
    if ($meta === null) {
        $meta = parse_contract_filename_legacy($filename, $rel);
    }

    if ($meta === null) {
        if (count($skipped) < 50) {
            $skipped[] = $rel;
        }
        continue;
    }

    $parsedOk++;
}

echo "total={$total}\n";
echo "parsed_ok={$parsedOk}\n";
echo "skipped_sample_count=" . count($skipped) . "\n";
foreach ($skipped as $row) {
    echo "- {$row}\n";
}

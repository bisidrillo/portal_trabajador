<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/contract_filename_parser.php";

const UNKNOWN_FOLDER = '_NO_CLASIFICADO';
const TARGET_ROOT = '/Volumes/CONTRATOS/CONTRATOS';

if ($argc < 2) {
    fwrite(STDERR, "Uso: php sort_contracts.php <archivo_o_carpeta> [...]\n");
    exit(1);
}

$contractTypeMap = [
    '100' => '100',
    '109' => '109',
    '189' => '189',
    '200' => '200',
    '289' => '289',
    '300' => '300',
    '389' => '389',
    '402' => '402',
    '410' => '410',
    '420' => '420',
    '421' => '421',
    '502' => '502',
    '510' => '510',
    '530' => '530',
    'MODIFICACION DE JORNADA' => 'CAMBIO DE CONTRATO',
    'CAMBIO DE CONTRATO' => 'CAMBIO DE CONTRATO',
    'SUSPENCION-LLAMAMIENTO' => 'SUSPENCION-LLAMAMIENTOS',
    'SUSPENSION-LLAMAMIENTO' => 'SUSPENCION-LLAMAMIENTOS',
    'SUSPENCION-LLAMAMIENTOS' => 'SUSPENCION-LLAMAMIENTOS',
];

$paths = array_slice($argv, 1);
$processed = 0;

foreach ($paths as $path) {
    foreach (expandPdfPaths($path) as $pdfPath) {
        processPdf($pdfPath, $contractTypeMap);
        $processed++;
    }
}

if ($processed === 0) {
    fwrite(STDOUT, "No se encontraron PDFs para procesar.\n");
}

function expandPdfPaths(string $path): array
{
    if (!file_exists($path)) {
        fwrite(STDERR, "No existe: {$path}\n");
        return [];
    }

    if (is_dir($path)) {
        $items = scandir($path);
        if ($items === false) {
            fwrite(STDERR, "No se pudo leer la carpeta: {$path}\n");
            return [];
        }

        $pdfs = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item;
            if (is_file($fullPath) && isPdf($fullPath)) {
                $pdfs[] = $fullPath;
            }
        }

        return $pdfs;
    }

    return isPdf($path) ? [$path] : [];
}

function processPdf(string $pdfPath, array $contractTypeMap): void
{
    $filename = basename($pdfPath);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $basename = pathinfo($filename, PATHINFO_FILENAME);
    $parsed = parseContratoFilename($filename);
    if ($parsed === null) {
        moveToUnknown($pdfPath, 'No se pudo interpretar el nombre con el parser central');
        return;
    }

    $normalizedContractType = normalizeContractType((string)$parsed["contract_type"], $contractTypeMap);
    if ($normalizedContractType === null) {
        moveToUnknown($pdfPath, "Tipo de contrato no reconocido: " . (string)$parsed["contract_type"]);
        return;
    }
    $department = (string)$parsed["department"];
    if ($department === '') {
        moveToUnknown($pdfPath, 'Falta departamento');
        return;
    }

    $targetDirectory = TARGET_ROOT
        . DIRECTORY_SEPARATOR
        . sanitizeFolderName($normalizedContractType)
        . DIRECTORY_SEPARATOR
        . sanitizeFolderName($department);

    ensureDirectory($targetDirectory);

    $targetPath = buildUniquePath($targetDirectory, $basename, $extension);
    if (!rename($pdfPath, $targetPath)) {
        fwrite(STDERR, "No se pudo mover: {$pdfPath}\n");
        return;
    }

    fwrite(STDOUT, "Movido: {$filename} -> " . relativeTarget(TARGET_ROOT, $targetPath) . "\n");
}

function normalizeContractType(string $contractType, array $contractTypeMap): ?string
{
    $normalized = strtoupper(trim($contractType));
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

    foreach ($contractTypeMap as $alias => $folderName) {
        if ($normalized === strtoupper($alias)) {
            return $folderName;
        }
    }

    return null;
}

function sanitizeFolderName(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[\/:]+/', '-', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return $value === '' ? UNKNOWN_FOLDER : $value;
}

function ensureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("No se pudo crear la carpeta: {$directory}");
    }
}

function buildUniquePath(string $directory, string $basename, string $extension): string
{
    $candidate = $directory . DIRECTORY_SEPARATOR . "{$basename}.{$extension}";
    if (!file_exists($candidate)) {
        return $candidate;
    }

    $counter = 1;
    do {
        $candidate = $directory . DIRECTORY_SEPARATOR . "{$basename}_{$counter}.{$extension}";
        $counter++;
    } while (file_exists($candidate));

    return $candidate;
}

function moveToUnknown(string $pdfPath, string $reason): void
{
    $targetDirectory = TARGET_ROOT . DIRECTORY_SEPARATOR . UNKNOWN_FOLDER;
    ensureDirectory($targetDirectory);

    $filename = basename($pdfPath);
    $basename = pathinfo($filename, PATHINFO_FILENAME);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $targetPath = buildUniquePath($targetDirectory, $basename, $extension);

    if (!rename($pdfPath, $targetPath)) {
        fwrite(STDERR, "No se pudo mover a " . UNKNOWN_FOLDER . ": {$filename}\n");
        return;
    }

    fwrite(STDOUT, "Sin clasificar: {$filename} ({$reason})\n");
}

function isPdf(string $path): bool
{
    return is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
}

function relativeTarget(string $baseDirectory, string $targetPath): string
{
    $prefix = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($targetPath, $prefix)) {
        return substr($targetPath, strlen($prefix));
    }

    return $targetPath;
}

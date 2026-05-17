<?php
declare(strict_types=1);

require_once __DIR__ . "/../includes/contract_filename_parser.php";

$cases = [
    "normal" => [
        "file" => "MARIA PEREZ_402_PISOS_CAMARERA_2024-01-01_2024-06-30_12345678.pdf",
        "expect" => [
            "contract_type" => "402",
            "department" => "PISOS",
            "category" => "CAMARERA",
            "start_date" => "2024-01-01",
            "end_date" => "2024-06-30",
            "inss_code" => "12345678",
            "es_sustitucion" => false,
            "es_prorroga" => false,
            "es_conversion" => false,
        ],
    ],
    "sustitucion" => [
        "file" => "JUAN PEREZ_410_PISOS_CAMARERO_2024-05-01_MARIA GARCIA_12345678.pdf",
        "expect" => [
            "contract_type" => "410",
            "start_date" => "2024-05-01",
            "end_date" => null,
            "persona_sustituida" => "MARIA GARCIA",
            "es_sustitucion" => true,
        ],
    ],
    "prorroga" => [
        "file" => "MARIA PEREZ_402_PISOS_CAMARERA_2024-01-01_2024-06-30_12345678-01.pdf",
        "expect" => [
            "contract_type" => "402",
            "inss_code" => "12345678-01",
            "es_prorroga" => true,
            "numero_prorroga" => 1,
            "codigo_inss_base" => "12345678",
        ],
    ],
    "conversion_389" => [
        "file" => "ANA LOPEZ_389_RECEPCION_RECEPCIONISTA_2024-03-01_12345678.pdf",
        "expect" => [
            "contract_type" => "389",
            "end_date" => null,
            "es_conversion" => true,
            "es_indefinido" => true,
            "modalidad" => "fijo_discontinuo",
        ],
    ],
    "formato_legacy" => [
        "file" => "GARCIA JUAN_402_2025-01-01_2025-12-31_COCINA_AYTE_123456789.pdf",
        "expect" => [
            "contract_type" => "402",
            "department" => "COCINA",
            "category" => "AYTE",
            "start_date" => "2025-01-01",
            "end_date" => "2025-12-31",
            "inss_code" => "123456789",
        ],
    ],
];

$failures = [];
foreach ($cases as $name => $case) {
    $parsed = parseContratoFilename($case["file"]);
    if ($parsed === null) {
        $failures[] = "{$name}: parser devolvio null";
        continue;
    }
    foreach ($case["expect"] as $key => $expected) {
        $actual = $parsed[$key] ?? null;
        if ($actual !== $expected) {
            $failures[] = "{$name}: {$key} esperado=" . var_export($expected, true) . " actual=" . var_export($actual, true);
        }
    }
}

if ($failures) {
    echo "FAIL\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo "OK contract filename parser examples\n";

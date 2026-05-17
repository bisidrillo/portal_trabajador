<?php
declare(strict_types=1);

if (!function_exists("contract_parse_date")) {
    function contract_parse_date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === "") {
            return null;
        }
        if (preg_match('/^\d{2}[:.]\d{2}[:.]\d{2,4}$/', $value)) {
            $value = str_replace([':', '.'], '-', $value);
        }

        $formats = ["Y-m-d", "d-m-Y", "d-m-y", "Ymd", "dmY", "ymd", "dmy"];
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat("!" . $format, $value);
            if ($dt instanceof DateTimeImmutable && $dt->format($format) === $value) {
                return $dt;
            }
        }

        return null;
    }
}

if (!function_exists("contract_parse_date_iso")) {
    function contract_parse_date_iso(string $value): ?string
    {
        $dt = contract_parse_date($value);
        return $dt ? $dt->format("Y-m-d") : null;
    }
}

if (!function_exists("contract_detect_type")) {
    function contract_detect_type(string $rel, string $filename = "", array $parts = []): ?string
    {
        $known = "100|109|189|200|289|300|389|402|410|420|421|502|510|530";
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if (preg_match('/^(?:' . $known . ')$/', $part)) {
                return $part;
            }
        }

        $value = strtoupper(str_replace(["\\", "/", "_"], " ", $rel . " " . $filename));
        if (preg_match('/(?:^|\D)(' . $known . ')(?:\D|$)/', $value, $m)) {
            return (string)$m[1];
        }
        if (str_contains($value, "CAMBIO") || str_contains($value, "MODIFIC")) {
            return "CAMBIO DE CONTRATO";
        }
        if (str_contains($value, "SUSP") || str_contains($value, "LLAM") || str_contains($value, "SUSPEC")) {
            return "SUSPENCION-LLAMAMIENTOS";
        }

        return null;
    }
}

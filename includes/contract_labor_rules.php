<?php
declare(strict_types=1);

if (!function_exists("contract_is_substitution_type")) {
    function contract_is_substitution_type(string $type): bool
    {
        return in_array(trim($type), ["410", "510"], true);
    }
}

if (!function_exists("contract_is_conversion_type")) {
    function contract_is_conversion_type(string $type): bool
    {
        return in_array(trim($type), ["189", "289", "389"], true);
    }
}

if (!function_exists("contract_conversion_modality")) {
    function contract_conversion_modality(string $type): ?string
    {
        return trim($type) === "389" ? "fijo_discontinuo" : null;
    }
}

if (!function_exists("contract_prorroga_info")) {
    function contract_prorroga_info(string $codigoInss): array
    {
        $codigoInss = trim($codigoInss);
        if ($codigoInss === "") {
            return [
                "es_prorroga" => false,
                "numero_prorroga" => null,
                "codigo_inss_base" => null,
            ];
        }

        if (preg_match('/^(.*?)-(0?1)$/', $codigoInss, $m)) {
            $base = trim((string)$m[1]);
            return [
                "es_prorroga" => $base !== "",
                "numero_prorroga" => $base !== "" ? 1 : null,
                "codigo_inss_base" => $base !== "" ? $base : null,
            ];
        }

        return [
            "es_prorroga" => false,
            "numero_prorroga" => null,
            "codigo_inss_base" => null,
        ];
    }
}

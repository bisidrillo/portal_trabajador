<?php
declare(strict_types=1);

require_once __DIR__ . "/contract_utils.php";
require_once __DIR__ . "/contract_labor_rules.php";

if (!function_exists("parseContratoFilename")) {
    function parseContratoFilename(string $filename, string $rel = ""): ?array
    {
        $filename = contract_filename_normalize_text($filename);
        $base = preg_replace('/\.pdf$/i', '', $filename);
        $parts = contract_filename_parts((string)$base);
        if (!$parts) {
            return null;
        }

        $type = contract_detect_type($rel, $filename, $parts);
        if ($type === null) {
            return null;
        }

        $typeIdx = contract_filename_type_index($parts, $type);
        if ($typeIdx === null) {
            return null;
        }

        $nameTokens = array_slice($parts, 0, $typeIdx);
        $tail = array_slice($parts, $typeIdx + 1);
        if (!$nameTokens || count($tail) < 3) {
            return null;
        }

        $fullName = contract_filename_clean_name(implode(" ", $nameTokens));
        if ($fullName === "") {
            return null;
        }

        $isSubstitution = contract_is_substitution_type($type);
        $personaSustituida = null;
        $endDate = null;
        $uniqueCode = "";
        $department = "";
        $category = "";
        $startDate = null;

        if (contract_parse_date_iso((string)($tail[0] ?? "")) !== null) {
            $startDate = contract_parse_date_iso((string)$tail[0]);
            if ($isSubstitution) {
                $personaSustituida = contract_filename_clean_name((string)($tail[1] ?? ""));
                $department = contract_filename_clean_name((string)($tail[2] ?? ""));
                $category = contract_filename_clean_name((string)($tail[3] ?? ""));
                $uniqueCode = contract_filename_clean_code((string)implode("_", array_slice($tail, 4)));
            } else {
                $endDate = contract_parse_date_iso((string)($tail[1] ?? ""));
                $department = contract_filename_clean_name((string)($tail[2] ?? ""));
                $category = contract_filename_clean_name((string)($tail[3] ?? ""));
                $uniqueCode = contract_filename_clean_code((string)implode("_", array_slice($tail, 4)));
            }
        } else {
            $department = contract_filename_clean_name((string)($tail[0] ?? ""));
            $category = contract_filename_clean_name((string)($tail[1] ?? ""));
            $startDate = contract_parse_date_iso((string)($tail[2] ?? ""));
        }

        if ($department === "" || $category === "" || $startDate === null) {
            return null;
        }

        if ($isSubstitution) {
            if ($personaSustituida === null) {
                $personaSustituida = contract_filename_clean_name((string)($tail[3] ?? ""));
                $uniqueCode = contract_filename_clean_code((string)($tail[4] ?? ""));
            }
            if ($personaSustituida === "" || $uniqueCode === "") {
                return null;
            }
        } elseif ($uniqueCode === "") {
            $maybeEndDate = contract_parse_date_iso((string)($tail[3] ?? ""));
            if ($maybeEndDate !== null) {
                $endDate = $maybeEndDate;
                $uniqueCode = contract_filename_clean_code((string)implode("_", array_slice($tail, 4)));
            } else {
                $uniqueCode = contract_filename_clean_code((string)implode("_", array_slice($tail, 3)));
            }
            if ($uniqueCode === "") {
                return null;
            }
        }

        $person = contract_filename_person_parts($fullName);
        $inssCode = $uniqueCode;
        $prorroga = contract_prorroga_info($inssCode);
        $isConversion = contract_is_conversion_type($type);
        $today = (new DateTimeImmutable("today"))->format("Y-m-d");
        $status = "active";
        if ($startDate > $today) {
            $status = "inactive";
        } elseif ($endDate !== null && $endDate < $today) {
            $status = "ended";
        }

        return [
            "first_name" => $person["first_name"],
            "last_name" => $person["last_name"],
            "full_name" => $fullName,
            "inss_code" => $inssCode,
            "contract_type" => $type,
            "start_date" => $startDate,
            "end_date" => $endDate,
            "department" => $department,
            "category" => $category,
            "status" => $status,
            "unique_code" => $uniqueCode,
            "es_sustitucion" => $isSubstitution,
            "persona_sustituida" => $personaSustituida,
            "es_prorroga" => (bool)$prorroga["es_prorroga"],
            "numero_prorroga" => $prorroga["numero_prorroga"],
            "codigo_inss_base" => $prorroga["codigo_inss_base"],
            "es_conversion" => $isConversion,
            "es_indefinido" => $isConversion,
            "modalidad" => contract_conversion_modality($type),
        ];
    }
}

if (!function_exists("contract_filename_normalize_text")) {
    function contract_filename_normalize_text(string $value): string
    {
        $value = trim($value);
        if ($value !== "" && class_exists("Normalizer")) {
            $norm = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($norm) && $norm !== "") {
                $value = $norm;
            }
        }
        return $value;
    }
}

if (!function_exists("contract_filename_parts")) {
    function contract_filename_parts(string $base): array
    {
        return array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            preg_split('/_+/u', $base) ?: []
        ), static fn(string $part): bool => $part !== ""));
    }
}

if (!function_exists("contract_filename_type_index")) {
    function contract_filename_type_index(array $parts, string $type): ?int
    {
        foreach ($parts as $i => $part) {
            if (trim((string)$part) === $type) {
                return $i;
            }
        }
        return null;
    }
}

if (!function_exists("contract_filename_clean_name")) {
    function contract_filename_clean_name(string $value): string
    {
        $value = str_replace([".", "-"], " ", $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }
}

if (!function_exists("contract_filename_clean_code")) {
    function contract_filename_clean_code(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        return trim($value, "_");
    }
}

if (!function_exists("contract_filename_person_parts")) {
    function contract_filename_person_parts(string $fullName): array
    {
        $nameParts = preg_split('/\s+/u', trim($fullName)) ?: [];
        if (count($nameParts) >= 4) {
            $firstName = implode(" ", array_slice($nameParts, 0, 2));
            $lastName = implode(" ", array_slice($nameParts, 2));
        } else {
            $lastName = (string)($nameParts[0] ?? $fullName);
            $firstName = implode(" ", array_slice($nameParts, 1));
        }

        return [
            "first_name" => $firstName !== "" ? $firstName : $lastName,
            "last_name" => $lastName,
        ];
    }
}

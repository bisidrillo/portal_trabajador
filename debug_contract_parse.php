<?php
declare(strict_types=1);

require_once "/Volumes/web/portal_trabajador/includes/contract_utils.php";

function clean_token(string $value): string
{
    $value = str_replace(["-", "."], " ", $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function normalize_filename_text(string $value): string
{
    $value = trim($value);
    if ($value === "") {
        return "";
    }
    if (class_exists("Normalizer")) {
        $norm = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if (is_string($norm) && $norm !== "") {
            $value = $norm;
        }
    }
    return $value;
}

function is_substitution_contract_type(string $type): bool
{
    return in_array($type, ["410", "510"], true);
}

function normalize_date_like_token(string $token): string
{
    $token = trim($token);
    if (preg_match('/^\d{2}[:.]\d{2}[:.]\d{2,4}$/', $token)) {
        return str_replace([':', '.'], '-', $token);
    }
    return $token;
}

function parse_compact_date_iso(string $token): ?string
{
    $token = trim($token);
    if ($token === "") {
        return null;
    }

    $formats = [];
    if (preg_match('/^\d{8}$/', $token)) {
        $formats = ["dmY", "Ymd"];
    } elseif (preg_match('/^\d{6}$/', $token)) {
        $formats = ["dmy", "ymd"];
    }

    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $token);
        if ($dt && $dt->format($format) === $token) {
            return $dt->format("Y-m-d");
        }
    }

    return null;
}

function parse_filename_date_iso(string $token): ?string
{
    $token = normalize_date_like_token($token);
    return contract_parse_date_iso($token) ?? parse_compact_date_iso($token);
}

function merge_date_triplet(array $parts, int $offset): ?string
{
    $first = trim((string)($parts[$offset] ?? ""));
    $second = trim((string)($parts[$offset + 1] ?? ""));
    $third = trim((string)($parts[$offset + 2] ?? ""));
    if (!preg_match('/^\d{1,2}$/', $first) || !preg_match('/^\d{1,2}$/', $second) || !preg_match('/^\d{2,4}$/', $third)) {
        return null;
    }

    $candidate = str_pad($first, 2, "0", STR_PAD_LEFT)
        . "-"
        . str_pad($second, 2, "0", STR_PAD_LEFT)
        . "-"
        . (strlen($third) === 2 ? $third : str_pad($third, 4, "0", STR_PAD_LEFT));

    return parse_filename_date_iso($candidate) !== null ? $candidate : null;
}

function normalize_filename_parts(array $parts): array
{
    $normalized = [];
    $count = count($parts);
    for ($i = 0; $i < $count; $i++) {
        $part = trim((string)$parts[$i]);
        if ($part === "") {
            continue;
        }

        $tripletDate = merge_date_triplet($parts, $i);
        if ($tripletDate !== null) {
            $normalized[] = $tripletDate;
            $i += 2;
            continue;
        }

        if (preg_match('/^(\d{6,8})[-_](\d{6,8})$/', $part, $m)) {
            $left = normalize_date_like_token((string)$m[1]);
            $right = normalize_date_like_token((string)$m[2]);
            if (parse_filename_date_iso($left) !== null && parse_filename_date_iso($right) !== null) {
                $normalized[] = $left;
                $normalized[] = $right;
                continue;
            }
        }

        if (preg_match('/^(\d{2}[-:.]\d{2}[-:.]\d{2,4})[-_](\d{2}[-:.]\d{2}[-:.]\d{2,4})$/', $part, $m)) {
            $normalized[] = normalize_date_like_token((string)$m[1]);
            $normalized[] = normalize_date_like_token((string)$m[2]);
            continue;
        }

        $normalized[] = normalize_date_like_token($part);
    }

    return $normalized;
}

function looks_like_unique_code(string $token): bool
{
    $token = trim($token);
    if ($token === "") {
        return false;
    }
    return (bool)preg_match('/^(?=.*\d)[A-Za-z0-9-]{5,}$/', $token);
}

function normalize_person_name(array $nameTokens): array
{
    $nameCombined = clean_token((string)implode(" ", array_map("strval", $nameTokens)));
    $nameParts = preg_split('/\s+/u', $nameCombined) ?: [];
    $count = count($nameParts);
    if ($count >= 4) {
        $firstName = clean_token((string)implode(" ", array_slice($nameParts, 0, 2)));
        $lastName = clean_token((string)implode(" ", array_slice($nameParts, 2)));
    } else {
        $lastName = clean_token((string)($nameParts[0] ?? $nameCombined));
        $firstName = clean_token((string)implode(" ", array_slice($nameParts, 1)));
    }
    $fullName = trim($nameCombined);
    return [
        "first_name" => $firstName !== "" ? $firstName : $lastName,
        "last_name" => $lastName,
        "full_name" => $fullName,
    ];
}

function parse_contract_filename_debug(string $filename, string $rel = ""): array
{
    $debug = [];
    $filename = normalize_filename_text($filename);
    $base = preg_replace('/\.pdf$/i', '', $filename);
    $parts = array_values(array_filter(array_map("trim", preg_split('/_+/u', (string)$base) ?: []), static function (string $v): bool {
        return $v !== "";
    }));
    $parts = normalize_filename_parts($parts);
    $debug["parts"] = $parts;
    if (count($parts) <= 1) {
        $parts = array_values(array_filter(array_map("trim", preg_split('/\s+/u', (string)$base) ?: []), static function (string $v): bool {
            return $v !== "";
        }));
        $parts = normalize_filename_parts($parts);
        $debug["parts_fallback"] = $parts;
    }
    if (!$parts) {
        $debug["reason"] = "no_parts";
        return $debug;
    }

    $typeIdx = null;
    foreach ($parts as $i => $p) {
        if (preg_match('/^\d{3,4}$/', $p) && contract_parse_date((string)$p) === null) {
            $typeIdx = $i;
            break;
        }
    }
    $debug["type_idx"] = $typeIdx;
    $type = contract_detect_type($rel, $filename, $parts);
    $debug["type"] = $type;
    if ($type === null) {
        $debug["reason"] = "type_null";
        return $debug;
    }

    if ($typeIdx !== null) {
        $beforeType = array_slice($parts, 0, $typeIdx);
        $afterType = array_slice($parts, $typeIdx + 1);
    } else {
        $beforeType = [];
        $afterType = $parts;
    }
    $debug["before_type"] = $beforeType;
    $debug["after_type"] = $afterType;

    $dateIdxs = [];
    foreach ($afterType as $i => $token) {
        $d = parse_filename_date_iso((string)$token);
        if ($d) {
            $dateIdxs[] = $i;
        }
    }
    $debug["date_idxs"] = $dateIdxs;
    $startIdx = $dateIdxs[0] ?? null;
    $startDate = $startIdx !== null ? parse_filename_date_iso((string)$afterType[$startIdx]) : null;
    $debug["start_idx"] = $startIdx;
    $debug["start_date"] = $startDate;
    if ($startDate === null) {
        $debug["reason"] = "start_date_null";
        return $debug;
    }

    $endIdx = $dateIdxs[1] ?? null;
    $endDate = $endIdx !== null ? parse_filename_date_iso((string)$afterType[$endIdx]) : null;
    if (is_substitution_contract_type((string)$type) && count($dateIdxs) < 2) {
        $endIdx = null;
        $endDate = null;
    }
    $debug["end_idx"] = $endIdx;
    $debug["end_date"] = $endDate;

    $department = "";
    $category = "";
    $uniqueCode = "";

    if ($startIdx === 0) {
        $rest = array_slice($afterType, ($endIdx !== null ? $endIdx + 1 : 1));
        $debug["rest_a"] = $rest;
        if ($rest) {
            $department = clean_token((string)$rest[0]);
            $catTokens = array_slice($rest, 1);
            if ($catTokens) {
                $last = (string)end($catTokens);
                if (looks_like_unique_code($last)) {
                    $uniqueCode = clean_token($last);
                    array_pop($catTokens);
                }
                $category = clean_token((string)implode(" ", array_map("strval", $catTokens)));
            }
        }
    } else {
        if (isset($afterType[0])) {
            $department = clean_token((string)$afterType[0]);
        }
        $roleTokens = [];
        if ($startIdx > 1) {
            $roleTokens = array_slice($afterType, 1, $startIdx - 1);
        } elseif ($startIdx === 1 && isset($afterType[1])) {
            $roleTokens = [$afterType[1]];
        }
        $debug["role_tokens"] = $roleTokens;
        $category = clean_token((string)implode(" ", array_map("strval", $roleTokens)));
        $tail = array_slice($afterType, ($endIdx !== null ? $endIdx + 1 : $startIdx + 1));
        $debug["tail"] = $tail;
        if ($tail) {
            $uniqueCode = clean_token((string)implode("_", array_map("strval", $tail)));
        }
    }

    $debug["department"] = $department;
    $debug["category"] = $category;
    $debug["unique_code"] = $uniqueCode;

    if ($beforeType) {
        $nameTokens = array_values(array_filter(array_map("strval", $beforeType), static function (string $v): bool {
            return trim($v) !== "";
        }));
    } else {
        $baseName = trim((string)($parts[0] ?? ""));
        if ($baseName === "") {
            $debug["reason"] = "basename_empty";
            return $debug;
        }
        $nameTokens = [clean_token($baseName)];
    }

    $person = normalize_person_name($nameTokens);
    $debug["person"] = $person;
    if (trim((string)$person["full_name"]) === "") {
        $debug["reason"] = "fullname_empty";
        return $debug;
    }

    $debug["parsed"] = [
        "full_name" => $person["full_name"],
        "contract_type" => $type,
        "start_date" => $startDate,
        "end_date" => $endDate,
        "department" => $department,
        "category" => $category,
        "unique_code" => $uniqueCode,
    ];

    return $debug;
}

$samples = array_slice($argv, 1);
foreach ($samples as $sample) {
    $result = parse_contract_filename_debug(basename($sample), $sample);
    echo "FILE={$sample}\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
}

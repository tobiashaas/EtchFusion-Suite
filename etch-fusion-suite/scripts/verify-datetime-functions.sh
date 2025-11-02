#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PHPCS_BIN="${PROJECT_ROOT}/vendor/bin/phpcs"
PHPCS_STANDARD="${PROJECT_ROOT}/phpcs.xml.dist"
INCLUDES_PATH="${PROJECT_ROOT}/includes"
MAIN_FILE="${PROJECT_ROOT}/etch-fusion-suite.php"
DOCS_DIR="${PROJECT_ROOT}/docs"
REPORT_FILE="${DOCS_DIR}/datetime-functions-verification-report.md"
TMP_DIR="$(mktemp -d 2>/dev/null || mktemp -d -t 'verify-datetime')"

FLAG_REPORT=false
FLAG_VERBOSE=false

CURRENT_TIME_TOTAL=0
CURRENT_TIME_MYSQL=0
CURRENT_TIME_TIMESTAMP=0
CURRENT_TIME_OTHER=0
WP_DATE_TOTAL=0
PROHIBITED_DATE=0
PROHIBITED_GMDATE=0
RECOMMENDED_TOTAL=0
TOTAL_ANALYZED=0
COMPLIANCE_RATE=100

cleanup() {
  rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

print_usage() {
  cat <<'USAGE'
Usage: verify-datetime-functions.sh [options]

Options:
  --report    Regenerate docs/datetime-functions-verification-report.md
  --verbose   Show additional PHPCS output
  --help      Show this help text
USAGE
}

info() {
  echo "[•] $*"
}

warn() {
  echo "[!] $*" >&2
}

error() {
  echo "[✗] $*" >&2
}

success() {
  echo "[✓] $*"
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --report)
        FLAG_REPORT=true
        ;;
      --verbose)
        FLAG_VERBOSE=true
        ;;
      --help|-h)
        print_usage
        exit 0
        ;;
      *)
        error "Unknown option: $1"
        print_usage
        exit 2
        ;;
    esac
    shift
  done
}

check_dependencies() {
  info "Checking dependencies"

  if [[ ! -x "${PHPCS_BIN}" ]]; then
    error "phpcs binary not found at ${PHPCS_BIN}. Run composer install."
    exit 2
  fi

  if [[ ! -f "${PHPCS_STANDARD}" ]]; then
    error "phpcs.xml.dist not found at ${PHPCS_STANDARD}."
    exit 2
  fi

  if ! command -v php >/dev/null 2>&1; then
    error "php command not available."
    exit 2
  fi

  success "Dependencies OK"
}

run_phpcs() {
  local phpcs_report="${TMP_DIR}/phpcs-datetime.json"
  info "Running PHPCS (WordPress.DateTime.RestrictedFunctions)"

  set +e
  "${PHPCS_BIN}" \
    --standard="${PHPCS_STANDARD}" \
    --sniffs=WordPress.DateTime.RestrictedFunctions \
    --report=json \
    "${INCLUDES_PATH}" "${MAIN_FILE}" \
    >"${phpcs_report}"
  PHPCS_STATUS=$?
  set -e

  PHPCS_REPORT="${phpcs_report}"

  if [[ "${FLAG_VERBOSE}" == true ]]; then
    info "PHPCS summary"
    set +e
    "${PHPCS_BIN}" \
      --standard="${PHPCS_STANDARD}" \
      --sniffs=WordPress.DateTime.RestrictedFunctions \
      --report=summary \
      "${INCLUDES_PATH}" "${MAIN_FILE}" || true
    set -e
  fi
}

analyze_usage() {
  local analysis_file="${TMP_DIR}/datetime-analysis.json"
  ANALYSIS_FILE="${analysis_file}"

  local summary
  if ! summary=$(PROJECT_ROOT_ENV="${PROJECT_ROOT}" \
    INCLUDES_PATH_ENV="${INCLUDES_PATH}" \
    MAIN_FILE_ENV="${MAIN_FILE}" \
    ANALYSIS_FILE_ENV="${analysis_file}" \
    php <<'PHP'
<?php
declare(strict_types=1);

$projectRoot = getenv('PROJECT_ROOT_ENV') ?: '';
$includesPath = getenv('INCLUDES_PATH_ENV') ?: '';
$mainFile = getenv('MAIN_FILE_ENV') ?: '';
$analysisFile = getenv('ANALYSIS_FILE_ENV') ?: '';

if ($projectRoot === '' || !is_dir($projectRoot)) {
    fwrite(STDERR, "Invalid project root provided.\n");
    exit(2);
}

$phpFiles = [];

$addPhpFiles = static function (string $path) use (&$phpFiles): void {
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $path,
                FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $phpFiles[] = $file->getPathname();
            }
        }
    } elseif (is_file($path)) {
        $phpFiles[] = $path;
    }
};

if ($includesPath !== '') {
    $addPhpFiles($includesPath);
}
if ($mainFile !== '') {
    $addPhpFiles($mainFile);
}

$phpFiles = array_values(array_unique($phpFiles));
sort($phpFiles);

$relativePath = static function (string $path) use ($projectRoot): string {
    $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/';
    $normalizedPath = str_replace('\\', '/', $path);
    if (strpos($normalizedPath, $normalizedRoot) === 0) {
        return substr($normalizedPath, strlen($normalizedRoot));
    }
    return $normalizedPath;
};

$computeLine = static function (string $content, int $offset): int {
    return substr_count(substr($content, 0, $offset), "\n") + 1;
};

$getContext = static function (array $lines, int $line, int $before = 2, int $after = 2): string {
    $start = max(1, $line - $before);
    $end = min(count($lines), $line + $after);
    $slice = array_slice($lines, $start - 1, $end - $start + 1);
    return implode("\n", array_map(static fn (string $text, int $index): string => sprintf('%5d: %s', $start + $index, rtrim($text)), $slice, array_keys($slice)));
};

$currentTime = [
    'total' => 0,
    'mysql' => [],
    'timestamp' => [],
    'other' => [],
];
$wpDate = [
    'total' => 0,
    'calls' => [],
];
$prohibited = [
    'date' => [],
    'gmdate' => [],
];

foreach ($phpFiles as $filePath) {
    $content = file_get_contents($filePath);
    if ($content === false) {
        continue;
    }

    $lines = preg_split('/\R/', $content);
    if ($lines === false) {
        $lines = [];
    }

    $tokens = token_get_all($content);
    $tokenCount = count($tokens);
    for ($i = 0; $i < $tokenCount; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] !== T_STRING || strcasecmp($token[1], 'current_time') !== 0) {
            continue;
        }

        $line = (int) $token[2];
        $j = $i + 1;
        while ($j < $tokenCount && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $tokenCount || $tokens[$j] !== '(') {
            continue;
        }

        $j++;
        $depth = 1;
        $argumentTokens = [];
        while ($j < $tokenCount && $depth > 0) {
            $tok = $tokens[$j];
            if ($tok === '(') {
                $depth++;
            } elseif ($tok === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }

            if ($depth === 1) {
                if ($tok === ',') {
                    break;
                }
                $argumentTokens[] = $tok;
            }

            $j++;
        }

        $argument = '';
        foreach ($argumentTokens as $argToken) {
            $argument .= is_array($argToken) ? $argToken[1] : $argToken;
        }
        $argument = trim($argument);
        if (stripos($argument, 'current_time(') === 0) {
            continue;
        }
        $argumentNormalized = trim($argument, " \t\n\r\0\x0B'\"");
        $argumentLower = strtolower($argumentNormalized);

        $entry = [
            'file' => $relativePath($filePath),
            'line' => $line,
            'argument' => $argumentNormalized,
            'code' => isset($lines[$line - 1]) ? trim($lines[$line - 1]) : 'current_time(...)',
        ];
        $entry['context'] = $getContext($lines, $line, 2, 2);

        $currentTime['total']++;
        if ($argumentLower === 'mysql') {
            $currentTime['mysql'][] = $entry;
        } elseif ($argumentLower === 'timestamp') {
            $currentTime['timestamp'][] = $entry;
        } else {
            $currentTime['other'][] = $entry;
        }
    }

    if (preg_match_all('/wp_date\s*\(/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $offset = $match[1];
            $line = $computeLine($content, $offset);
            $entry = [
                'file' => $relativePath($filePath),
                'line' => $line,
                'code' => isset($lines[$line - 1]) ? trim($lines[$line - 1]) : 'wp_date(...)',
            ];
            $entry['context'] = $getContext($lines, $line, 2, 2);
            $wpDate['total']++;
            $wpDate['calls'][] = $entry;
        }
    }

    if (preg_match_all('/(?<![A-Za-z0-9_])date\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $offset = $match[1];
            $line = $computeLine($content, $offset);
            $prohibited['date'][] = [
                'file' => $relativePath($filePath),
                'line' => $line,
                'code' => isset($lines[$line - 1]) ? trim($lines[$line - 1]) : 'date(...)',
                'context' => $getContext($lines, $line, 3, 3),
            ];
        }
    }

    if (preg_match_all('/(?<![A-Za-z0-9_])gmdate\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $offset = $match[1];
            $line = $computeLine($content, $offset);
            $prohibited['gmdate'][] = [
                'file' => $relativePath($filePath),
                'line' => $line,
                'code' => isset($lines[$line - 1]) ? trim($lines[$line - 1]) : 'gmdate(...)',
                'context' => $getContext($lines, $line, 3, 3),
            ];
        }
    }
}

$analysis = [
    'generated' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'files_scanned' => array_map($relativePath, $phpFiles),
    'current_time' => $currentTime,
    'wp_date' => $wpDate,
    'prohibited' => $prohibited,
];

if ($analysisFile !== '') {
    file_put_contents($analysisFile, json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$recommendedTotal = $currentTime['total'] + $wpDate['total'];
$prohibitedTotal = count($prohibited['date']) + count($prohibited['gmdate']);
$totalAnalyzed = $recommendedTotal + $prohibitedTotal;
$compliance = $totalAnalyzed > 0
    ? round(($recommendedTotal / max(1, $totalAnalyzed)) * 100, 2)
    : 100.0;

printf("current_time_total=%d\n", $currentTime['total']);
printf("current_time_mysql=%d\n", count($currentTime['mysql']));
printf("current_time_timestamp=%d\n", count($currentTime['timestamp']));
printf("current_time_other=%d\n", count($currentTime['other']));
printf("wp_date_total=%d\n", $wpDate['total']);
printf("prohibited_date=%d\n", count($prohibited['date']));
printf("prohibited_gmdate=%d\n", count($prohibited['gmdate']));
printf("recommended_total=%d\n", $recommendedTotal);
printf("total_analyzed=%d\n", $totalAnalyzed);
printf("compliance_rate=%.2f\n", $compliance);
PHP
  ); then
    error "Failed to analyze date/time usage"
    exit 2
  fi

  ANALYSIS_SUMMARY="${summary}"
}

parse_analysis_summary() {
  if [[ -z "${ANALYSIS_FILE:-}" || ! -f "${ANALYSIS_FILE}" ]]; then
    error "Analysis data file not found; run analysis first"
    return 1
  fi

  local summary
  if ! summary=$(ANALYSIS_FILE_ENV="${ANALYSIS_FILE}" php <<'PHP'
<?php
declare(strict_types=1);

$analysisFile = getenv('ANALYSIS_FILE_ENV') ?: '';
if ($analysisFile === '' || !is_file($analysisFile)) {
    exit(1);
}

$analysis = json_decode((string) file_get_contents($analysisFile), true);
if (!is_array($analysis)) {
    exit(1);
}

$current = $analysis['current_time'] ?? [];
$wpDate  = $analysis['wp_date'] ?? [];
$prohib  = $analysis['prohibited'] ?? [];

$currentTotal    = is_array($current) ? (int) ($current['total'] ?? 0) : 0;
$currentMysql    = is_array($current) ? count($current['mysql'] ?? []) : 0;
$currentTimestamp= is_array($current) ? count($current['timestamp'] ?? []) : 0;
$currentOther    = is_array($current) ? count($current['other'] ?? []) : 0;

$wpDateTotal     = is_array($wpDate) ? (int) ($wpDate['total'] ?? 0) : 0;
$prohibDate      = is_array($prohib) ? count($prohib['date'] ?? []) : 0;
$prohibGmdate    = is_array($prohib) ? count($prohib['gmdate'] ?? []) : 0;

$recommendedTotal = $currentTotal + $wpDateTotal;
$prohibitedTotal  = $prohibDate + $prohibGmdate;
$totalAnalyzed    = $recommendedTotal + $prohibitedTotal;

$compliance = $totalAnalyzed > 0
    ? round(($recommendedTotal / max(1, $totalAnalyzed)) * 100, 2)
    : 100.0;

printf("current_time_total=%d\n", $currentTotal);
printf("current_time_mysql=%d\n", $currentMysql);
printf("current_time_timestamp=%d\n", $currentTimestamp);
printf("current_time_other=%d\n", $currentOther);
printf("wp_date_total=%d\n", $wpDateTotal);
printf("prohibited_date=%d\n", $prohibDate);
printf("prohibited_gmdate=%d\n", $prohibGmdate);
printf("recommended_total=%d\n", $recommendedTotal);
printf("total_analyzed=%d\n", $totalAnalyzed);
printf("compliance_rate=%.2f\n", $compliance);
PHP
  ); then
    return 1
  fi

  if [[ -z "${summary}" ]]; then
    return 1
  fi

  while IFS='=' read -r key value; do
    case "$key" in
      current_time_total) CURRENT_TIME_TOTAL="${value}" ;;
      current_time_mysql) CURRENT_TIME_MYSQL="${value}" ;;
      current_time_timestamp) CURRENT_TIME_TIMESTAMP="${value}" ;;
      current_time_other) CURRENT_TIME_OTHER="${value}" ;;
      wp_date_total) WP_DATE_TOTAL="${value}" ;;
      prohibited_date) PROHIBITED_DATE="${value}" ;;
      prohibited_gmdate) PROHIBITED_GMDATE="${value}" ;;
      recommended_total) RECOMMENDED_TOTAL="${value}" ;;
      total_analyzed) TOTAL_ANALYZED="${value}" ;;
      compliance_rate) COMPLIANCE_RATE="${value}" ;;
    esac
  done <<<"${summary}"

  return 0
}

generate_report() {
  info "Generating verification report"
  mkdir -p "${DOCS_DIR}"
  REPORT_PATH_ENV="${REPORT_FILE}" \
    PROJECT_ROOT_ENV="${PROJECT_ROOT}" \
    ANALYSIS_FILE_ENV="${ANALYSIS_FILE}" \
    php <<'PHP'
<?php
declare(strict_types=1);

$analysisFile = getenv('ANALYSIS_FILE_ENV') ?: '';
$reportPath = getenv('REPORT_PATH_ENV') ?: '';

if ($analysisFile === '' || !is_file($analysisFile)) {
    fwrite(STDERR, "Analysis data not found; run analysis first.\n");
    exit(2);
}

$analysis = json_decode((string) file_get_contents($analysisFile), true);
if (!is_array($analysis)) {
    fwrite(STDERR, "Failed to decode analysis data.\n");
    exit(2);
}

$generated = (new DateTimeImmutable())->format('Y-m-d');
$currentTime = $analysis['current_time'] ?? [];
$wpDate = $analysis['wp_date'] ?? [];
$prohibited = $analysis['prohibited'] ?? [];
$recommendedTotal = ($currentTime['total'] ?? 0) + ($wpDate['total'] ?? 0);
$prohibitedCount = count($prohibited['date'] ?? []) + count($prohibited['gmdate'] ?? []);

$makeList = static function (array $entries, string $functionName): string {
    if ($entries === []) {
        return "- None\n";
    }

    $lines = [];
    foreach ($entries as $entry) {
        $lines[] = sprintf(
            "- `%s:%d` — %s",
            $entry['file'] ?? 'unknown',
            $entry['line'] ?? 0,
            trim($entry['code'] ?? $functionName . '()')
        );
    }
    return implode("\n", $lines) . "\n";
};

$matchEntry = static function (array $entry, callable $matcher): bool {
    $file = (string) ($entry['file'] ?? '');
    return $file !== '' && $matcher($file);
};

$filterEntries = static function (array $entries, callable $matcher) use ($matchEntry): array {
    return array_values(array_filter($entries, static function ($entry) use ($matchEntry, $matcher): bool {
        return $matchEntry(is_array($entry) ? $entry : [], $matcher);
    }));
};

$focusAreas = [
    [
        'title' => 'Security Suite',
        'scope' => '`includes/security/`',
        'matcher' => static fn (string $file): bool => strpos($file, 'includes/security/') === 0,
    ],
    [
        'title' => 'Error Handler',
        'scope' => '`includes/error_handler.php`',
        'matcher' => static fn (string $file): bool => $file === 'includes/error_handler.php',
    ],
    [
        'title' => 'API Endpoints',
        'scope' => '`includes/api_endpoints.php`',
        'matcher' => static fn (string $file): bool => $file === 'includes/api_endpoints.php',
    ],
];

$focusSections = [];
foreach ($focusAreas as $area) {
    $matcher = $area['matcher'];

    $currentMysql = $filterEntries($currentTime['mysql'] ?? [], $matcher);
    $currentTimestamp = $filterEntries($currentTime['timestamp'] ?? [], $matcher);
    $currentOther = $filterEntries($currentTime['other'] ?? [], $matcher);
    $currentTotal = count($currentMysql) + count($currentTimestamp) + count($currentOther);

    $wpDateCalls = $filterEntries($wpDate['calls'] ?? [], $matcher);
    $wpDateTotal = count($wpDateCalls);

    $prohibDate = $filterEntries($prohibited['date'] ?? [], $matcher);
    $prohibGmdate = $filterEntries($prohibited['gmdate'] ?? [], $matcher);

    $lines = [];
    $lines[] = sprintf("### %s (%s)\n", $area['title'], $area['scope']);
    $lines[] = sprintf(
        "- current_time(): %d total (mysql %d | timestamp %d | other %d)",
        $currentTotal,
        count($currentMysql),
        count($currentTimestamp),
        count($currentOther)
    );
    $lines[] = sprintf("- wp_date(): %d total", $wpDateTotal);
    $lines[] = sprintf(
        "- prohibited: date() %d | gmdate() %d",
        count($prohibDate),
        count($prohibGmdate)
    );

    $occurrenceSections = [];
    if ($currentTotal > 0) {
        $occurrenceSections[] = "**current_time()**\n\n" . $makeList(array_merge($currentMysql, $currentTimestamp, $currentOther), 'current_time');
    }
    if ($wpDateTotal > 0) {
        $occurrenceSections[] = "**wp_date()**\n\n" . $makeList($wpDateCalls, 'wp_date');
    }
    if ($prohibDate !== [] || $prohibGmdate !== []) {
        $occurrenceSections[] = "**Prohibited functions**\n\n" .
            ($prohibDate === [] ? "- None\n" : $makeList($prohibDate, 'date')) .
            "\n" .
            ($prohibGmdate === [] ? "- None\n" : $makeList($prohibGmdate, 'gmdate'));
    }

    if ($occurrenceSections !== []) {
        $lines[] = "\n**Occurrences**\n\n" . implode("\n", $occurrenceSections);
    }

    $focusSections[] = implode("\n", $lines);
}

$focusSection = "## 2a. Focus Areas\n\n" . implode("\n\n", $focusSections) . "\n";

$sectionCurrent = sprintf(
    "#### current_time() Usage (%d occurrences)\n\n",
    $currentTime['total'] ?? 0
);
$sectionCurrent .= "**MySQL format**\n\n";
$sectionCurrent .= $makeList($currentTime['mysql'] ?? [], 'current_time');
$sectionCurrent .= "\n**Timestamp format**\n\n";
$sectionCurrent .= $makeList($currentTime['timestamp'] ?? [], 'current_time');
$sectionCurrent .= "\n**Other formats (review recommended)**\n\n";
$sectionCurrent .= $makeList($currentTime['other'] ?? [], 'current_time');

$sectionWpDate = sprintf(
    "#### wp_date() Usage (%d occurrences)\n\n",
    $wpDate['total'] ?? 0
);
$sectionWpDate .= $makeList($wpDate['calls'] ?? [], 'wp_date');

$sectionProhibited = "#### Prohibited Functions\n\n";
$sectionProhibited .= "**date()**\n\n";
$sectionProhibited .= $makeList($prohibited['date'] ?? [], 'date');
$sectionProhibited .= "\n**gmdate()**\n\n";
$sectionProhibited .= $makeList($prohibited['gmdate'] ?? [], 'gmdate');

$report = <<<'MD'
# Date/Time Functions Verification Report

**Generated:** %s

## 1. Executive Summary

- Compliance Status: %s
- Recommended Functions Used: %d (`current_time()` + `wp_date()`)
- Prohibited Functions Found: %d (`date()`, `gmdate()`)
- Scope: `includes/` directory and `etch-fusion-suite.php`

## 2. Function Usage Inventory

%s
%s

%s

## 3. Prohibited Function Scan

%s

## 4. Notes & Recommendations

- Keep using `current_time('mysql')` for database timestamps.
- Use `wp_date()` for formatted output.
- Run `composer verify-datetime -- --report` to refresh this document.
MD;

$status = $prohibitedCount === 0 ? '✓ 100% compliant' : '⚠ Review required';
$report = sprintf(
    $report,
    $generated,
    $status,
    $recommendedTotal,
    $prohibitedCount,
    $sectionCurrent,
    $sectionWpDate,
    $focusSection,
    $sectionProhibited
);

if ($reportPath === '') {
    echo $report;
} else {
    file_put_contents($reportPath, $report);
}
PHP
}

summarize_results() {
  local summary_message
  local prohibited_total=$(( ${PROHIBITED_DATE:-0} + ${PROHIBITED_GMDATE:-0} ))
  if [[ ${PHPCS_STATUS} -ne 0 ]]; then
    error "PHPCS detected WordPress.DateTime.RestrictedFunctions violations"
  fi
  if [[ ${prohibited_total} -gt 0 ]]; then
    error "Found ${prohibited_total} prohibited date()/gmdate() usage(s)"
  fi

  if [[ ${PHPCS_STATUS} -eq 0 && ${prohibited_total} -eq 0 ]]; then
    summary_message="All ${RECOMMENDED_TOTAL} date/time calls use WordPress APIs (${COMPLIANCE_RATE}% compliant)"
    success "${summary_message}"
  else
    warn "Compliance check completed with issues"
  fi

  echo
  echo "Summary"
  echo "======="
  printf 'current_time() total   : %s\n' "${CURRENT_TIME_TOTAL:-0}"
  printf "  ├─ 'mysql'        : %s\n" "${CURRENT_TIME_MYSQL:-0}"
  printf "  ├─ 'timestamp'    : %s\n" "${CURRENT_TIME_TIMESTAMP:-0}"
  printf "  └─ other          : %s\n" "${CURRENT_TIME_OTHER:-0}"
  printf 'wp_date() total      : %s\n' "${WP_DATE_TOTAL:-0}"
  printf 'Prohibited date()    : %s\n' "${PROHIBITED_DATE:-0}"
  printf 'Prohibited gmdate()  : %s\n' "${PROHIBITED_GMDATE:-0}"
  printf 'Compliance rate      : %s%%\n' "${COMPLIANCE_RATE:-100}"
}

main() {
  parse_args "$@"
  check_dependencies
  run_phpcs
  analyze_usage
  if ! parse_analysis_summary; then
    error "Failed to parse analysis summary"
    exit 2
  fi

  if [[ "${FLAG_REPORT}" == true ]]; then
    generate_report
    success "Report written to ${REPORT_FILE}"
  fi

  summarize_results

  local exit_code=0
  if [[ ${PHPCS_STATUS} -ne 0 ]]; then
    exit_code=1
  fi
  if [[ $(( ${PROHIBITED_DATE:-0} + ${PROHIBITED_GMDATE:-0} )) -gt 0 ]]; then
    exit_code=1
  fi

  exit "${exit_code}"
}

main "$@"

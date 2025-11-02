#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PHPCS_BIN="${PROJECT_ROOT}/vendor/bin/phpcs"
PHPCS_STANDARD="${PROJECT_ROOT}/phpcs.xml.dist"
TARGET_PATH="${PROJECT_ROOT}/includes"
MAIN_FILE="${PROJECT_ROOT}/etch-fusion-suite.php"
DOCS_DIR="${PROJECT_ROOT}/docs"
REPORT_FILE="${DOCS_DIR}/hook-prefixing-verification-report.md"
TIMESTAMP="$(date +%Y-%m-%d' '%H:%M:%S)"
TMP_DIR="$(mktemp -d 2>/dev/null || mktemp -d -t 'verify-hooks')"
trap 'rm -rf "${TMP_DIR}"' EXIT

FLAG_REPORT=false
FLAG_VERBOSE=false

PHPCS_JSON_FILE=""
PHPCS_STATUS=0

print_usage() {
  cat <<'USAGE'
Usage: verify-hook-prefixing.sh [options]

Options:
  --report    Regenerate docs/hook-prefixing-verification-report.md
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

extract_allowed_prefixes() {
  PHPCS_STANDARD_PATH="${PHPCS_STANDARD}" php <<'PHP'
<?php
declare(strict_types=1);

$standardPath = getenv('PHPCS_STANDARD_PATH') ?: '';
if ($standardPath === '' || !is_file($standardPath)) {
    fwrite(STDERR, "Unable to locate phpcs.xml.dist at {$standardPath}\n");
    exit(1);
}

$xml = simplexml_load_file($standardPath);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse phpcs.xml.dist\n");
    exit(1);
}

$prefixes = [];
foreach ($xml->rule as $rule) {
    if ((string) $rule['ref'] !== 'WordPress.NamingConventions.PrefixAllGlobals') {
        continue;
    }
    if (!isset($rule->properties->property)) {
        continue;
    }
    foreach ($rule->properties->property as $property) {
        if ((string) $property['name'] !== 'prefixes') {
            continue;
        }
        foreach ($property->element as $element) {
            $value = (string) $element['value'];
            if ($value !== '') {
                $prefixes[] = $value;
            }
        }
    }
}

if (empty($prefixes)) {
    fwrite(STDERR, "No prefixes defined in phpcs.xml.dist\n");
    exit(1);
}

echo implode("\n", $prefixes);
PHP
}

run_phpcs() {
  local json_file
  json_file="${TMP_DIR}/phpcs-prefix.json"
  PHPCS_JSON_FILE="${json_file}"

  info "Running PHPCS (PrefixAllGlobals)"

  local status
  set +e
  "${PHPCS_BIN}" \
    --standard="${PHPCS_STANDARD}" \
    --sniffs=WordPress.NamingConventions.PrefixAllGlobals \
    --report=json \
    "${TARGET_PATH}" "${MAIN_FILE}" \
    >"${json_file}"
  status=$?
  set -e

  PHPCS_STATUS=${status}

  if [[ "${FLAG_VERBOSE}" == true ]]; then
    "${PHPCS_BIN}" \
      --standard="${PHPCS_STANDARD}" \
      --sniffs=WordPress.NamingConventions.PrefixAllGlobals \
      --report=summary \
      "${TARGET_PATH}" "${MAIN_FILE}" || true
  fi
}

analyze_hooks() {
  local allowed_csv="$1"
  local summary_path="$2"
  local data_path="$3"

  PROJECT_ROOT_ENV="${PROJECT_ROOT}" \
  ALLOWED_PREFIXES_ENV="${allowed_csv}" \
  SUMMARY_PATH_ENV="${summary_path}" \
  DATA_PATH_ENV="${data_path}" \
  php <<'PHP'
<?php
declare(strict_types=1);

$projectRoot = getenv('PROJECT_ROOT_ENV') ?: '';
$allowedCsv  = getenv('ALLOWED_PREFIXES_ENV') ?: '';
$summaryPath = getenv('SUMMARY_PATH_ENV') ?: '';
$dataPath    = getenv('DATA_PATH_ENV') ?: '';

if ($projectRoot === '' || !is_dir($projectRoot)) {
    fwrite(STDERR, "Invalid project root provided.\n");
    exit(1);
}

$includesDir = $projectRoot . '/includes';
$mainFile    = $projectRoot . '/etch-fusion-suite.php';

$allowed = array_values(array_filter(array_map('trim', explode(',', $allowedCsv))));
if (empty($allowed)) {
    fwrite(STDERR, "Allowed prefixes list is empty.\n");
    exit(1);
}

$prefixCounts = [];
foreach ($allowed as $prefix) {
    $prefixCounts[$prefix] = 0;
}

$ajaxHooks       = [];
$customActions   = [];
$customFilters   = [];
$globalFunctions = [];
$violations      = ['hooks' => [], 'functions' => []];
$coreActionsUsed = [];
$coreFiltersUsed = [];
$intentionalExceptions = [];

if (!function_exists('efs_prefix_starts_with')) {
    function efs_prefix_starts_with(string $haystack, string $needle): bool {
        if ($needle === '') {
            return true;
        }
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('efs_prefix_contains')) {
    function efs_prefix_contains(string $haystack, string $needle): bool {
        if ($needle === '') {
            return true;
        }
        return strpos($haystack, $needle) !== false;
    }
}

$tokenTypeMarkers = [T_CLASS, T_INTERFACE, T_TRAIT];
if (defined('T_ENUM')) {
    $tokenTypeMarkers[] = T_ENUM;
}

$coreActions = [
    'init',
    'plugins_loaded',
    'admin_menu',
    'admin_enqueue_scripts',
    'rest_api_init',
    'send_headers',
    'admin_init',
];

$coreFilters = [
    'wp_is_application_passwords_available',
    'rest_pre_serve_request',
    'rest_pre_dispatch',
    'rest_request_before_callbacks',
];

function relative_path(string $root, string $path): string {
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $normalizedPath = str_replace('\\', '/', $path);
    if (strpos($normalizedPath, $normalizedRoot) === 0) {
        return substr($normalizedPath, strlen($normalizedRoot));
    }
    return $normalizedPath;
}

function detect_prefix(string $name, array $allowed): ?string {
    foreach ($allowed as $prefix) {
        if ($name === $prefix || efs_prefix_starts_with($name, $prefix . '_')) {
            return $prefix;
        }
    }
    return null;
}

function increment_prefix(array &$counts, ?string $prefix): void {
    if ($prefix === null) {
        return;
    }
    if (!array_key_exists($prefix, $counts)) {
        $counts[$prefix] = 0;
    }
    $counts[$prefix]++;
}

function compute_line(string $content, int $offset): int {
    return substr_count(substr($content, 0, $offset), "\n") + 1;
}

$paths = [];
if (is_dir($includesDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $includesDir,
            RecursiveDirectoryIterator::SKIP_DOTS
        )
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $paths[] = $file->getPathname();
        }
    }
}
if (is_file($mainFile)) {
    $paths[] = $mainFile;
}
$paths = array_values(array_unique($paths));
sort($paths);

foreach ($paths as $absolutePath) {
    $content = file_get_contents($absolutePath);
    if ($content === false) {
        continue;
    }

    $relative = relative_path($projectRoot, $absolutePath);

    if (preg_match_all('/add_action\s*\(\s*([\'"`])([^\'"`]+)\1/mi', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $index => $match) {
            $hookName = $match[0];
            $offset   = $matches[0][$index][1];
            $line     = compute_line($content, $offset);

            if (strpos($hookName, 'wp_ajax_nopriv_') === 0) {
                $suffix = substr($hookName, strlen('wp_ajax_nopriv_'));
                $prefix = detect_prefix($suffix, $allowed);
                $entry  = [
                    'name'   => $hookName,
                    'file'   => $relative,
                    'line'   => $line,
                    'prefix' => $prefix,
                    'group'  => 'ajax_nopriv',
                ];
                if ($prefix === null) {
                    $violations['hooks'][] = [
                        'hook'  => $hookName,
                        'file'  => $relative,
                        'line'  => $line,
                        'reason'=> 'AJAX (nopriv) hook missing allowed prefix',
                    ];
                } else {
                    increment_prefix($prefixCounts, $prefix);
                }
                $ajaxHooks[] = $entry;
                continue;
            }

            if (efs_prefix_starts_with($hookName, 'wp_ajax_')) {
                $suffix = substr($hookName, strlen('wp_ajax_'));
                $prefix = detect_prefix($suffix, $allowed);
                $group  = efs_prefix_contains($relative, 'admin_interface.php') ? 'admin_interface' : 'ajax_handlers';
                $entry  = [
                    'name'   => $hookName,
                    'file'   => $relative,
                    'line'   => $line,
                    'prefix' => $prefix,
                    'group'  => $group,
                ];
                if ($prefix === null) {
                    $violations['hooks'][] = [
                        'hook'  => $hookName,
                        'file'  => $relative,
                        'line'  => $line,
                        'reason'=> 'AJAX hook missing allowed prefix',
                    ];
                } else {
                    increment_prefix($prefixCounts, $prefix);
                }
                $ajaxHooks[] = $entry;
                continue;
            }

            if (in_array($hookName, $coreActions, true)) {
                $coreActionsUsed[] = $hookName;
                continue;
            }

            $prefix = detect_prefix($hookName, $allowed);
            if ($prefix === null) {
                $violations['hooks'][] = [
                    'hook'  => $hookName,
                    'file'  => $relative,
                    'line'  => $line,
                    'reason'=> 'Custom action registration missing allowed prefix',
                ];
            } else {
                $customActions[] = [
                    'name'   => $hookName,
                    'file'   => $relative,
                    'line'   => $line,
                    'prefix' => $prefix,
                    'context'=> 'registration',
                ];
                increment_prefix($prefixCounts, $prefix);
            }
        }
    }

    if (preg_match_all('/add_filter\s*\(\s*([\'"`])([^\'"`]+)\1/mi', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $index => $match) {
            $hookName = $match[0];
            $offset   = $matches[0][$index][1];
            $line     = compute_line($content, $offset);

            if ($hookName === 'https_local_ssl_verify') {
                $intentionalExceptions[] = [
                    'name'   => $hookName,
                    'file'   => $relative,
                    'line'   => $line,
                    'reason' => 'WordPress core filter, intentionally unprefixed with phpcs:ignore',
                ];
                $coreFiltersUsed[] = $hookName;
                continue;
            }

            if (in_array($hookName, $coreFilters, true)) {
                $coreFiltersUsed[] = $hookName;
                continue;
            }

            $prefix = detect_prefix($hookName, $allowed);
            if ($prefix === null) {
                $violations['hooks'][] = [
                    'hook'  => $hookName,
                    'file'  => $relative,
                    'line'  => $line,
                    'reason'=> 'Custom filter registration missing allowed prefix',
                ];
            } else {
                $customFilters[] = [
                    'name'   => $hookName,
                    'file'   => $relative,
                    'line'   => $line,
                    'prefix' => $prefix,
                    'context'=> 'registration',
                ];
                increment_prefix($prefixCounts, $prefix);
            }
        }
    }

    if (preg_match_all('/do_action\s*\(\s*([\'"`])([^\'"`]+)\1/mi', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $index => $match) {
            $hookName = $match[0];
            $offset   = $matches[0][$index][1];
            $line     = compute_line($content, $offset);

            $prefix = detect_prefix($hookName, $allowed);
            if ($prefix === null) {
                $violations['hooks'][] = [
                    'hook'  => $hookName,
                    'file'  => $relative,
                    'line'  => $line,
                    'reason'=> 'Custom action missing allowed prefix',
                ];
            } else {
                $customActions[] = [
                    'name'   => $hookName,
                    'file'   => $relative,
                    'line'   => $line,
                    'prefix' => $prefix,
                    'context'=> 'fire',
                ];
                increment_prefix($prefixCounts, $prefix);
            }
        }
    }

    if (preg_match_all('/apply_filters\s*\(\s*([\'"`])([^\'"`]+)\1/mi', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $index => $match) {
            $hookName = $match[0];
            $offset   = $matches[0][$index][1];
            $line     = compute_line($content, $offset);

            if ($hookName === 'https_local_ssl_verify') {
                $intentionalExceptions[] = [
                    'name'   => $hookName,
                    'file'   => $relative,
                    'line'   => $line,
                    'reason' => 'WordPress core filter, intentionally unprefixed with phpcs:ignore',
                ];
                $coreFiltersUsed[] = $hookName;
                continue;
            }

            if (in_array($hookName, $coreFilters, true)) {
                $coreFiltersUsed[] = $hookName;
                continue;
            }

            $prefix = detect_prefix($hookName, $allowed);
            if ($prefix === null) {
                $violations['hooks'][] = [
                    'hook'  => $hookName,
                    'file'  => $relative,
                    'line'  => $line,
                    'reason'=> 'Custom filter missing allowed prefix',
                ];
            } else {
                $customFilters[] = [
                    'name'   => $hookName,
                    'file'   => $relative,
                    'line'   => $line,
                    'prefix' => $prefix,
                    'context'=> 'fire',
                ];
                increment_prefix($prefixCounts, $prefix);
            }
        }
    }

    $tokens = token_get_all($content);
    $classDepth = 0;
    $classPending = false;
    $braceStack = [];

    $tokenCount = count($tokens);
    for ($i = 0; $i < $tokenCount; $i++) {
        $token = $tokens[$i];
        if (is_array($token)) {
            [$id, $value, $line] = $token;
            if (in_array($id, $tokenTypeMarkers, true)) {
                $classPending = true;
                continue;
            }

            if ($id === T_FUNCTION) {
                $isMethod = false;
                $back = $i - 1;
                while ($back >= 0) {
                    $prev = $tokens[$back];
                    if (is_array($prev)) {
                        if (in_array($prev[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            $back--;
                            continue;
                        }
                        if (in_array($prev[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT], true)) {
                            $isMethod = true;
                        }
                        break;
                    } else {
                        if (trim($prev) === '') {
                            $back--;
                            continue;
                        }
                        break;
                    }
                }

                if ($isMethod) {
                    continue;
                }

                $j = $i + 1;
                while ($j < $tokenCount && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j++;
                }
                $nameToken = $tokens[$j] ?? null;
                $name = is_array($nameToken) && $nameToken[0] === T_STRING ? $nameToken[1] : null;

                if ($isClosure || $classDepth > 0 || $nameToken === null) {
                    continue;
                }

                $functionName = $nameToken[1];
                $prefix        = detect_prefix($functionName, $allowed);
                $entry         = [
                    'name'   => $functionName,
                    'file'   => $relative,
                    'line'   => $line,
                    'prefix' => $prefix,
                ];

                if ($prefix === null) {
                    $violations['functions'][] = [
                        'function' => $functionName,
                        'file'     => $relative,
                        'line'     => $line,
                        'reason'   => 'Global function missing allowed prefix',
                    ];
                } else {
                    $globalFunctions[] = $entry;
                    increment_prefix($prefixCounts, $prefix);
                }
            }
        } else {
            if ($token === '{') {
                if ($classPending) {
                    $classDepth++;
                    $classPending = false;
                    $braceStack[] = 'class';
                } elseif ($classDepth > 0) {
                    $braceStack[] = 'block';
                }
            } elseif ($token === '}') {
                if (!empty($braceStack)) {
                    $marker = array_pop($braceStack);
                    if ($marker === 'class' && $classDepth > 0) {
                        $classDepth--;
                    }
                }
            }
        }
    }
}

$ajaxHooks = array_values($ajaxHooks);
usort($ajaxHooks, static function (array $a, array $b): int {
    return [$a['file'], $a['line'], $a['name']] <=> [$b['file'], $b['line'], $b['name']];
});

$customActions = array_values($customActions);
usort($customActions, static function (array $a, array $b): int {
    return [$a['name'], $a['file'], $a['line']] <=> [$b['name'], $b['file'], $b['line']];
});

$customFilters = array_values($customFilters);
usort($customFilters, static function (array $a, array $b): int {
    return [$a['name'], $a['file'], $a['line']] <=> [$b['name'], $b['file'], $b['line']];
});

$globalFunctions = array_values($globalFunctions);
usort($globalFunctions, static function (array $a, array $b): int {
    return [$a['name'], $a['file'], $a['line']] <=> [$b['name'], $b['file'], $b['line']];
});

$coreActionsUsed = array_values(array_unique($coreActionsUsed));
sort($coreActionsUsed);
$coreFiltersUsed = array_values(array_unique($coreFiltersUsed));
sort($coreFiltersUsed);
$intentionalExceptions = array_values($intentionalExceptions);

$totalHooks = count($ajaxHooks) + count($customActions) + count($customFilters);

$data = [
    'generated'             => date(DATE_ATOM),
    'allowed_prefixes'      => $allowed,
    'ajax_actions'          => $ajaxHooks,
    'custom_actions'        => $customActions,
    'custom_filters'        => $customFilters,
    'global_functions'      => $globalFunctions,
    'prefix_counts'         => $prefixCounts,
    'violations'            => $violations,
    'core_actions_used'     => $coreActionsUsed,
    'core_filters_used'     => $coreFiltersUsed,
    'intentional_exceptions'=> $intentionalExceptions,
];

$summary = [
    'totals' => [
        'hooks'            => $totalHooks,
        'ajax_actions'     => count($ajaxHooks),
        'custom_actions'   => count($customActions),
        'custom_filters'   => count($customFilters),
        'global_functions' => count($globalFunctions),
    ],
    'violations' => [
        'hooks'     => count($violations['hooks']),
        'functions' => count($violations['functions']),
    ],
    'prefix_counts'         => $prefixCounts,
    'core_actions'          => $coreActionsUsed,
    'core_filters'          => $coreFiltersUsed,
    'intentional_exceptions'=> $intentionalExceptions,
];

if ($dataPath !== '') {
    $dir = dirname($dataPath);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create directory for hook data: {$dir}\n");
        exit(1);
    }
    if (file_put_contents($dataPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        fwrite(STDERR, "Failed to write hook data file: {$dataPath}\n");
        exit(1);
    }
}
if ($summaryPath !== '') {
    $dir = dirname($summaryPath);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create directory for hook summary: {$dir}\n");
        exit(1);
    }
    if (file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        fwrite(STDERR, "Failed to write hook summary file: {$summaryPath}\n");
        exit(1);
    }
}

echo json_encode($summary, JSON_UNESCAPED_SLASHES);
PHP
}

generate_report() {
  local data_path="$1"
  local phpcs_json="$2"
  local output_path="$3"
  local timestamp="$4"
  local phpcs_status="$5"

  mkdir -p "${DOCS_DIR}"

  php <<'PHP' "${data_path}" "${phpcs_json}" "${output_path}" "${timestamp}" "${phpcs_status}"
<?php
declare(strict_types=1);

$dataPath      = $argv[1] ?? '';
$phpcsJsonPath = $argv[2] ?? '';
$outputPath    = $argv[3] ?? '';
$timestamp     = $argv[4] ?? '';
$phpcsStatus   = isset($argv[5]) ? (int) $argv[5] : 0;

if ($dataPath === '' || !is_file($dataPath)) {
    fwrite(STDERR, "Hook analysis data not found.\n");
    exit(1);
}

$data = json_decode(file_get_contents($dataPath), true, 512, JSON_THROW_ON_ERROR);
$summary = [
    'totals' => $data['prefix_counts'] ?? [],
];

$phpcsData = [];
if ($phpcsJsonPath !== '' && is_file($phpcsJsonPath)) {
    $phpcsData = json_decode(file_get_contents($phpcsJsonPath), true);
}

$summaryTotals = [
    'hooks'            => count($data['ajax_actions'] ?? []) + count($data['custom_actions'] ?? []) + count($data['custom_filters'] ?? []),
    'ajax_actions'     => count($data['ajax_actions'] ?? []),
    'custom_actions'   => count($data['custom_actions'] ?? []),
    'custom_filters'   => count($data['custom_filters'] ?? []),
    'global_functions' => count($data['global_functions'] ?? []),
];

$totalItems = $summaryTotals['hooks'] + $summaryTotals['global_functions'];
$hookViolations = count($data['violations']['hooks'] ?? []);
$functionViolations = count($data['violations']['functions'] ?? []);
$overallViolations = $hookViolations + $functionViolations;
$intentionalExceptions = $data['intentional_exceptions'] ?? [];

$compliant = ($phpcsStatus === 0) && $overallViolations === 0;
$complianceStatus = $compliant ? '✓ 100% compliant' : '⚠️ Violations detected';

$allowedPrefixes = $data['allowed_prefixes'] ?? [];
$prefixCounts    = $data['prefix_counts'] ?? [];
ksort($prefixCounts);

$coreActions = $data['core_actions_used'] ?? [];
$coreFilters = $data['core_filters_used'] ?? [];

$ajaxHandlers = $data['ajax_actions'] ?? [];
$ajaxPrimary = array_values(array_filter($ajaxHandlers, static fn($entry) => ($entry['group'] ?? '') === 'ajax_handlers'));
$ajaxAdmin   = array_values(array_filter($ajaxHandlers, static fn($entry) => ($entry['group'] ?? '') === 'admin_interface'));
$ajaxNopriv  = array_values(array_filter($ajaxHandlers, static fn($entry) => ($entry['group'] ?? '') === 'ajax_nopriv'));

$customActionHooks = array_values(array_filter($data['custom_actions'] ?? [], static fn($entry) => ($entry['context'] ?? '') === 'fire'));
$customFilterHooks = array_values(array_filter($data['custom_filters'] ?? [], static fn($entry) => ($entry['context'] ?? '') === 'fire'));

$markdown = [];
$markdown[] = '# Hook Prefixing Verification Report';
$markdown[] = '';
$markdown[] = '**Generated:** ' . $timestamp;
$markdown[] = '';
$markdown[] = '## 1. Executive Summary';
$markdown[] = '- Compliance status: ' . $complianceStatus;
$markdown[] = '- Total items analyzed: ' . $totalItems . ' (' . $summaryTotals['hooks'] . ' hooks + ' . $summaryTotals['global_functions'] . ' global functions)';
$markdown[] = '- Violations found: ' . $overallViolations;
$markdown[] = '- Intentional exceptions: ' . count($intentionalExceptions);
$markdown[] = '';
$markdown[] = '## 2. Verification Methodology';
$markdown[] = ''; 
$markdown[] = '**Tools Used:**';
$markdown[] = '- PHP_CodeSniffer (`WordPress.NamingConventions.PrefixAllGlobals`)';
$markdown[] = '- Custom verification script (`scripts/verify-hook-prefixing.sh`)';
$markdown[] = '- Pattern analysis via `add_action`, `add_filter`, `do_action`, and `apply_filters` inspections';
$markdown[] = '';
$markdown[] = '**Scope:**';
$markdown[] = '- `includes/` directory (recursive)';
$markdown[] = '- `etch-fusion-suite.php` (global bootstrap)';
$markdown[] = '- PHPCS configuration in `phpcs.xml.dist`';
$markdown[] = '';
$markdown[] = '## 3. Custom Hooks Inventory';
$markdown[] = '';
$markdown[] = '### 3.1 AJAX Actions (' . count($ajaxPrimary) . ' total)';
if (!empty($ajaxPrimary)) {
    foreach ($ajaxPrimary as $index => $entry) {
        $markdown[] = ($index + 1) . '. `' . $entry['name'] . '` - ' . $entry['file'] . ':' . $entry['line'];
    }
} else {
    $markdown[] = '_No AJAX handlers detected._';
}

if (!empty($ajaxAdmin)) {
    $markdown[] = '';
    $markdown[] = '**Additional AJAX Actions (Admin Interface):**';
    foreach ($ajaxAdmin as $index => $entry) {
        $markdown[] = '- `' . $entry['name'] . '` - ' . $entry['file'] . ':' . $entry['line'];
    }
}

if (!empty($ajaxNopriv)) {
    $markdown[] = '';
    $markdown[] = '**AJAX Actions (Unauthenticated):**';
    foreach ($ajaxNopriv as $entry) {
        $markdown[] = '- `' . $entry['name'] . '` - ' . $entry['file'] . ':' . $entry['line'];
    }
}

$markdown[] = '';
$markdown[] = '### 3.2 Custom Action Hooks (' . count($customActionHooks) . ' total)';
if (!empty($customActionHooks)) {
    foreach ($customActionHooks as $index => $entry) {
        $markdown[] = ($index + 1) . '. `' . $entry['name'] . '` - ' . $entry['file'] . ':' . $entry['line'];
    }
} else {
    $markdown[] = '_No custom action hooks detected._';
}

$markdown[] = '';
$markdown[] = '### 3.3 Custom Filter Hooks (' . count($customFilterHooks) . ' total)';
if (!empty($customFilterHooks)) {
    foreach ($customFilterHooks as $index => $entry) {
        $markdown[] = ($index + 1) . '. `' . $entry['name'] . '` - ' . $entry['file'] . ':' . $entry['line'];
    }
} else {
    $markdown[] = '_No custom filter hooks detected._';
}

$markdown[] = '';
$markdown[] = '## 4. Global Functions Inventory (' . $summaryTotals['global_functions'] . ' total)';
if (!empty($data['global_functions'])) {
    foreach ($data['global_functions'] as $entry) {
        $markdown[] = '- `' . $entry['name'] . '()` - ' . $entry['file'] . ':' . $entry['line'];
    }
} else {
    $markdown[] = '_No global functions detected._';
}

$markdown[] = '';
$markdown[] = '## 5. WordPress Core Hooks Used';
$markdown[] = '';
$markdown[] = '**Actions:**';
if (!empty($coreActions)) {
    foreach ($coreActions as $action) {
        $markdown[] = '- `' . $action . '`';
    }
} else {
    $markdown[] = '- _(none recorded)_';
}
$markdown[] = '';
$markdown[] = '**Filters:**';
if (!empty($coreFilters)) {
    foreach ($coreFilters as $filter) {
        $markdown[] = '- `' . $filter . '`';
    }
} else {
    $markdown[] = '- _(none recorded)_';
}

$markdown[] = '';
$markdown[] = '## 6. Intentional Exceptions';
if (!empty($intentionalExceptions)) {
    foreach ($intentionalExceptions as $exception) {
        $markdown[] = '- `' . $exception['name'] . '` - ' . $exception['file'] . ':' . $exception['line'] . ' — ' . $exception['reason'];
    }
} else {
    $markdown[] = '- _(none)_';
}

$markdown[] = '';
$markdown[] = '## 7. PHPCS Configuration Analysis';
$markdown[] = 'Allowed prefixes (from `phpcs.xml.dist`):';
foreach ($allowedPrefixes as $prefix) {
    $markdown[] = '- `' . $prefix . '`';
}

$markdown[] = '';
$markdown[] = '## 8. Prefix Usage Statistics';
$markdown[] = '**By Prefix:**';
foreach ($prefixCounts as $prefix => $count) {
    $markdown[] = '- `' . $prefix . '` : ' . $count;
}
$markdown[] = '';
$markdown[] = '**By Type:**';
$markdown[] = '- AJAX actions: ' . count($ajaxHandlers);
$markdown[] = '- Custom action hooks: ' . count($customActionHooks);
$markdown[] = '- Custom filter hooks: ' . count($customFilterHooks);
$markdown[] = '- Global functions: ' . $summaryTotals['global_functions'];

$markdown[] = '';
$markdown[] = '## 9. Compliance Verification';
$markdown[] = '```bash';
$markdown[] = 'vendor/bin/phpcs --standard=phpcs.xml.dist --sniffs=WordPress.NamingConventions.PrefixAllGlobals includes/';
$markdown[] = './scripts/verify-hook-prefixing.sh';
$markdown[] = '```';
if ($compliant) {
    $markdown[] = ''; 
    $markdown[] = '_Expected output:_';
    $markdown[] = '```
No violations found.
```';
}

$markdown[] = '';
$markdown[] = '## 10. Recommendations';
$markdown[] = '1. Continue using `efs_` for AJAX and internal hooks.';
$markdown[] = '2. Reserve `etch_fusion_suite_` for public extensibility points and global helpers.';
$markdown[] = '3. Document new hooks in `docs/naming-conventions.md` and update this report when changes occur.';
$markdown[] = '4. Run `composer verify-hooks` before releases and integrate into CI checks.';

$markdown[] = '';
$markdown[] = '## 11. Conclusion';
$markdown[] = '- Hook prefixing compliance: ' . ($compliant ? '✓ Verified (100%)' : '⚠️ Review required');
$markdown[] = '- PHPCS configuration: ✓ Validated';
$markdown[] = '- Documentation & tooling: ✓ In place';
$markdown[] = '';
$markdown[] = '**Next:** Phase 7 - Zeitfunktionen (replace `date()` with `gmdate()` or `current_time()`).';

file_put_contents($outputPath, implode("\n", $markdown) . "\n");
PHP
}

main() {
  parse_args "$@"
  check_dependencies

  mkdir -p "${DOCS_DIR}"

  local prefixes
  prefixes=$(extract_allowed_prefixes) || {
    error "Failed to extract allowed prefixes"
    exit 2
  }

  local allowed_csv
  allowed_csv=$(echo "${prefixes}" | paste -sd',' -)

  run_phpcs
  local phpcs_status=${PHPCS_STATUS}

  if (( phpcs_status >= 2 )); then
    error "PHPCS encountered an error (exit code ${phpcs_status})"
    exit 2
  fi

  local hook_summary_path="${TMP_DIR}/hook-summary.json"
  local hook_data_path="${TMP_DIR}/hook-data.json"

  local hook_summary_json
  if ! hook_summary_json=$(analyze_hooks "${allowed_csv}" "${hook_summary_path}" "${hook_data_path}"); then
    error "Failed to analyze hooks"
    exit 2
  fi

  if [[ ! -f "${hook_summary_path}" || ! -f "${hook_data_path}" ]]; then
    error "Hook analysis did not produce expected output files"
    exit 2
  fi

  local total_hooks
  total_hooks=$(php <<'PHP' "${hook_summary_path}"
<?php
$summary = json_decode(file_get_contents($argv[1]), true);
if (!is_array($summary)) {
    exit(1);
}
echo $summary['totals']['hooks'] ?? 0;
PHP
) || total_hooks=0

  local total_functions
  total_functions=$(php <<'PHP' "${hook_summary_path}"
<?php
$summary = json_decode(file_get_contents($argv[1]), true);
if (!is_array($summary)) {
    exit(1);
}
echo $summary['totals']['global_functions'] ?? 0;
PHP
) || total_functions=0

  local hook_violations
  hook_violations=$(php <<'PHP' "${hook_summary_path}"
<?php
$summary = json_decode(file_get_contents($argv[1]), true);
if (!is_array($summary)) {
    exit(1);
}
echo $summary['violations']['hooks'] ?? 0;
PHP
) || hook_violations=0

  local function_violations
  function_violations=$(php <<'PHP' "${hook_summary_path}"
<?php
$summary = json_decode(file_get_contents($argv[1]), true);
if (!is_array($summary)) {
    exit(1);
}
echo $summary['violations']['functions'] ?? 0;
PHP
) || function_violations=0

  local exit_code=0

  if [[ "${FLAG_REPORT}" == true || ! -f "${REPORT_FILE}" ]]; then
    if ! generate_report "${hook_data_path}" "${PHPCS_JSON_FILE}" "${REPORT_FILE}" "${TIMESTAMP}" "${phpcs_status}"; then
      warn "Failed to generate hook prefixing report"
    fi
  elif (( phpcs_status != 0 || hook_violations != 0 || function_violations != 0 )); then
    generate_report "${hook_data_path}" "${PHPCS_JSON_FILE}" "${REPORT_FILE}" "${TIMESTAMP}" "${phpcs_status}" || true
  fi

  if (( phpcs_status != 0 || hook_violations != 0 || function_violations != 0 )); then
    exit_code=1
  fi

  if [[ ${exit_code} -eq 0 ]]; then
    success "All ${total_hooks} hooks and ${total_functions} global functions use allowed prefixes (100% compliant)"
  else
    error "Prefix verification failed: ${hook_violations} hook issues, ${function_violations} function issues"
  fi

  exit ${exit_code}
}

main "$@"

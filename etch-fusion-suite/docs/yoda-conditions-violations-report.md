# Yoda Conditions Violations Report

**Generated:** 2026-03-04 23:13:25

## 1. Executive Summary
- PHPCS violations: Unavailable (jq not installed)
- Regex heuristic matches: 0
- Target scope: [includes/](../includes)

## 2. Violations by Directory
_- Skipped (jq not installed) -_

## 3. Detailed PHPCS Output

default report (truncated):

[•] Running PHPCS YodaConditions sniff
/usr/bin/env: ‘php’: No such file or directory

## 4. Regex Candidates

No heuristic matches found.

## 5. Next Steps
1. Prioritize Security and AJAX directories.
2. Apply Yoda conversions for literals, constants, numbers, booleans, and nulls.
3. Manually review variable-to-variable comparisons for readability.
4. Re-run ./scripts/verify-yoda-conditions.sh --report until both PHPCS and regex checks are clean.

---

> Generated automatically by [scripts/verify-yoda-conditions.sh](../scripts/verify-yoda-conditions.sh)

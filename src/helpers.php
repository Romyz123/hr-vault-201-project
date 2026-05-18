<?php
// ======================================================
// [FILE] src/helpers.php
// [PURPOSE] Global Helper Functions
// ======================================================

if (!function_exists('h')) {
    /**
     * Escape output safely for HTML.
     * @param mixed $v The value to escape
     * @return string
     */
    function h(mixed $v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Check if a document matches any defined requirements.
 */
function isDocUncategorized(array $doc, array $requirements): bool
{
    $cat = trim($doc['category'] ?? '');
    $name = $doc['original_name'] ?? '';

    foreach ($requirements as $reqName => $keywords) {
        if (strcasecmp($cat, $reqName) === 0) {
            return false;
        }
        foreach ($keywords as $k) {
            if ($k !== '' && (stripos($name, $k) !== false || stripos($cat, $k) !== false)) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Check if an employee has any documents that are uncategorized.
 */
function isEmployeeUncategorized(array $employeeDocs, array $requirements): bool
{
    foreach ($employeeDocs as $doc) {
        if (isDocUncategorized($doc, $requirements)) {
            return true;
        }
    }
    return false;
}
// Add other global helper functions here if needed
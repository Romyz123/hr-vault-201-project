<?php
// src/helpers.php

if (!function_exists('h')) {
    /**
     * Escape output safely for HTML.
     *
     * @param mixed $v The value to escape.
     * @return string
     */
    function h(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('post')) {
    /**
     * Fetch a POST value safely as string.
     *
     * @param string $key The key to retrieve from $_POST.
     * @param string $default The default value if the key is not set or invalid.
     * @return string
     */
    function post(string $key, string $default = ''): string
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        // Prevent unexpected array injection
        if (is_array($_POST[$key])) {
            return $default;
        }

        return trim((string)$_POST[$key]);
    }
}

// The 'old' function relies on a global $old array, which is typically set
// in add_employee.php and edit_employee.php. It's defined here for centralization.
if (!function_exists('old')) {
    function old(string $key, string $default = ''): string
    {
        global $old; // Requires $old to be declared global in the calling script
        return h($old[$key] ?? $default);
    }
}

if (!function_exists('isDocUncategorized')) {
    /**
     * Checks if a document is uncategorized based on predefined rules.
     *
     * @param array $document The document array (must contain 'category' and 'original_name').
     * @param array $requiredDocs An associative array of required document categories and their keywords.
     * @return bool True if the document is uncategorized, false otherwise.
     */
    function isDocUncategorized(array $document, array $requiredDocs): bool
    {
        $matched = false;
        $cat = trim($document['category'] ?? '');
        $name = $document['original_name'] ?? '';

        foreach ($requiredDocs as $reqName => $keywords) {
            if (strcasecmp($cat, $reqName) === 0) {
                $matched = true;
                break;
            }
            foreach ($keywords as $k) {
                if ($k !== '' && (stripos($name, $k) !== false || stripos($cat, $k) !== false)) {
                    $matched = true;
                    break 2; // Break out of both inner and outer loops
                }
            }
        }
        return !$matched;
    }
}

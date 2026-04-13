<?php
// src/Validator.php

class Validator
{
    /**
     * Centralized Search Sanitizer
     * Enforces 50 char limit and allows only alphanumeric, spaces, dashes, underscores, commas.
     */
    public static function sanitizeSearch($term)
    {
        $term = trim((string)$term);
        // [SECURITY] Enforce Character Limit
        if (strlen($term) > 50) {
            $term = substr($term, 0, 50);
        }
        // [SECURITY] Remove dangerous characters
        return preg_replace('/[^a-zA-Z0-9\-_ ,]/', '', $term);
    }

    /**
     * General Input Validator
     * Returns an error string if invalid, null if valid.
     */
    public static function check($input, $rule, $param = null)
    {
        $value = trim((string)$input);

        if ($rule === 'required' && $value === '') {
            return "Field is required.";
        }
        if ($rule === 'max' && strlen($value) > $param) {
            return "Exceeds maximum length of $param characters.";
        }
        if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "Invalid email format.";
        }
        if ($rule === 'pattern' && !preg_match($param, $value)) {
            return "Contains invalid characters.";
        }
        if ($rule === 'date') {
            $d = DateTime::createFromFormat('Y-m-d', $value);
            if (!$d || $d->format('Y-m-d') !== $value) {
                return "Invalid date format (YYYY-MM-DD).";
            }
        }
        return null;
    }

    /**
     * Enforces MHI Password Complexity Policy
     * Min 15 chars, at least 3 types (Upper, Lower, Number, Symbol)
     */
    public static function validatePasswordComplexity($password)
    {
        if (strlen($password) < 15) {
            return "Password must be at least 15 characters long.";
        }
        $types = 0;
        if (preg_match('/[a-z]/', $password)) $types++;
        if (preg_match('/[A-Z]/', $password)) $types++;
        if (preg_match('/[0-9]/', $password)) $types++;
        if (preg_match('/[\W_]/', $password)) $types++;

        if ($types < 3) {
            return "Password must contain at least 3 character types (uppercase, lowercase, numbers, symbols).";
        }
        return null;
    }
}

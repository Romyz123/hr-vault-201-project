<?php
class SearchHelper
{
    /**
     * Find the best fuzzy match for a search term from the employees table.
     * Uses a similarity ratio algorithm (Levenshtein based).
     */
    public static function findBestMatch($pdo, $search)
    {
        if (empty($search)) return null;

        // Fetch only necessary columns
        $stmt = $pdo->query("SELECT first_name, last_name FROM employees");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bestMatch = null;
        $highestScore = 0;
        $search = strtolower(trim($search));
        $lenS = strlen($search);

        foreach ($rows as $row) {
            $name = strtolower($row['first_name'] . ' ' . $row['last_name']);
            $lenN = strlen($name);

            // Optimization: Skip if length difference is too big (> 40%)
            if (abs($lenS - $lenN) > max($lenS, $lenN) * 0.4) continue;

            // levenshtein() only supports strings up to 255 chars
            if ($lenS > 255 || $lenN > 255) continue;

            // Calculate Similarity Ratio
            $lev = levenshtein($search, $name);
            $maxLen = max($lenS, $lenN);
            $ratio = (1 - ($lev / $maxLen)) * 100;
            if ($ratio > $highestScore && $ratio > 70) { // 70% threshold
                $highestScore = $ratio;
                $bestMatch = $row['first_name'] . ' ' . $row['last_name'];
            }
        }

        return $bestMatch;
    }
}

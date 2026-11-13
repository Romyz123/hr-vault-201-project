<?php

function loadDirectoryReportRequirements(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT name, keywords FROM document_requirements ORDER BY id ASC');
        $requirements = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $requirements[$row['name']] = array_map('trim', explode(',', (string)$row['keywords']));
        }
        return $requirements;
    } catch (Throwable $e) {
        error_log('Unable to load directory report document requirements: ' . $e->getMessage());
        return [
            '201 Files' => ['201', 'PDS', 'Data Sheet', 'Resume'],
            'Valid ID' => ['ID', 'Passport', 'License', 'SSS', 'PhilHealth'],
            'Contract' => ['Contract', 'Appointment', 'Offer'],
            'Medical' => ['Medical', 'Fit to Work', 'Exam'],
            'Clearance' => ['NBI', 'Police', 'Barangay']
        ];
    }
}

function directoryReportHasColumn(PDO $pdo, string $table, string $column): bool
{
    $allowedColumns = [
        'employees' => ['deleted_at'],
        'documents' => ['deleted_at']
    ];
    if (!isset($allowedColumns[$table]) || !in_array($column, $allowedColumns[$table], true)) {
        throw new InvalidArgumentException('Unsupported directory report table.');
    }

    foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['Field'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

function buildDirectoryReportFilters(
    PDO $pdo,
    array $filters,
    array $requiredDocs,
    bool $hasEmployeeDeletedAt,
    bool $hasDeletedDocuments
): array {
    $where = ['1=1'];
    $params = [];

    if ($hasEmployeeDeletedAt) {
        $where[] = 'deleted_at IS NULL';
    }

    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '') {
        if ($status === '__UNSPECIFIED__') {
            $where[] = "TRIM(COALESCE(status, '')) = ''
                AND LOWER(COALESCE(exit_reason, '')) NOT REGEXP 'dead|deceased|passed away|retir|transf|trasf|overseas|abroad|deploy|(^|[^a-z])awol([^a-z]|$)'";
        } else {
            $effectiveStatusSql = "CASE
                WHEN LOWER(COALESCE(exit_reason, '')) REGEXP 'dead|deceased|passed away' THEN 'Deceased'
                WHEN LOWER(COALESCE(exit_reason, '')) REGEXP 'retir' THEN 'Retired'
                WHEN LOWER(COALESCE(exit_reason, '')) REGEXP 'transf|overseas|abroad|deploy' THEN 'Transferred'
                WHEN TRIM(COALESCE(status, '')) = '' AND LOWER(COALESCE(exit_reason, '')) REGEXP '(^|[^a-z])awol([^a-z]|$)' THEN 'AWOL'
                ELSE TRIM(status)
            END";
            $where[] = "LOWER(($effectiveStatusSql)) = ?";
            $params[] = strtolower($status);
        }
    }

    $type = trim((string)($filters['type'] ?? ''));
    if ($type !== '') {
        $where[] = '(employment_type = ? OR agency_name = ?)';
        $params[] = $type;
        $params[] = $type;
    }

    $department = trim((string)($filters['dept'] ?? ''));
    if ($department !== '') {
        $where[] = 'dept LIKE ?';
        $params[] = "%{$department}%";
    }

    foreach (['section' => 'section', 'group' => '`group`'] as $filterKey => $column) {
        $value = trim((string)($filters[$filterKey] ?? ''));
        if ($value !== '') {
            $where[] = "$column LIKE ?";
            $params[] = "%{$value}%";
        }
    }

    $jobTitle = trim((string)($filters['job_title'] ?? ''));
    if ($jobTitle !== '') {
        $where[] = 'job_title = ?';
        $params[] = $jobTitle;
    }

    $documentCategory = trim((string)($filters['doc_cat'] ?? ''));
    if ($documentCategory !== '') {
        if ($documentCategory === 'Uncategorized' || $documentCategory === 'Documents for Employee') {
            $matchConditions = [];
            foreach ($requiredDocs as $name => $keywords) {
                $matchConditions[] = 'category = ' . $pdo->quote((string)$name);
                foreach ($keywords as $keyword) {
                    $keyword = trim((string)$keyword);
                    if ($keyword === '') continue;
                    $quotedKeyword = $pdo->quote("%{$keyword}%");
                    $matchConditions[] = "original_name LIKE $quotedKeyword";
                    $matchConditions[] = "category LIKE $quotedKeyword";
                }
            }
            $categorizedSql = $matchConditions ? implode(' OR ', $matchConditions) : '1=0';
            $documentCondition = $hasDeletedDocuments ? 'deleted_at IS NULL' : '1=1';
            $where[] = "emp_id IN (
                SELECT employee_id FROM documents
                WHERE $documentCondition AND NOT ($categorizedSql)
            )";
        } else {
            $subConditions = ['category = ?', 'category LIKE ?'];
            $subParams = [$documentCategory, "%{$documentCategory}%"];
            foreach ($requiredDocs[$documentCategory] ?? [] as $keyword) {
                $keyword = trim((string)$keyword);
                if ($keyword === '') continue;
                $subConditions[] = 'original_name LIKE ?';
                $subConditions[] = 'category LIKE ?';
                $subParams[] = "%{$keyword}%";
                $subParams[] = "%{$keyword}%";
            }
            $documentFilter = $hasDeletedDocuments ? 'deleted_at IS NULL AND ' : '';
            $where[] = 'EXISTS (
                SELECT 1 FROM documents
                WHERE documents.employee_id = employees.emp_id AND ' . $documentFilter .
                '(' . implode(' OR ', $subConditions) . ')
            )';
            array_push($params, ...$subParams);
        }
    }

    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $terms = preg_split('/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($terms as $term) {
            $where[] = '(emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
            $likeTerm = "%{$term}%";
            array_push($params, $likeTerm, $likeTerm, $likeTerm);
        }
    }

    return [
        'where_sql' => 'WHERE ' . implode(' AND ', $where),
        'params' => $params
    ];
}

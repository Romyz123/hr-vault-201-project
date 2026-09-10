<?php
class EmployeeService
{
    private $pdo;
    private $logger;

    public function __construct($pdo, $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    public function validate($data)
    {
        $errors = [];
        if (empty($data['emp_id'])) $errors[] = "Employee ID is required.";
        if (empty($data['first_name']) || empty($data['last_name'])) $errors[] = "First and Last Name are required.";
        if (empty($data['job_title'])) $errors[] = "Job Title is required.";
        if (empty($data['dept'])) $errors[] = "Department is required.";

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }

        // Check duplicate ID
        if (!empty($data['emp_id'])) {
            $stmt = $this->pdo->prepare("SELECT id FROM employees WHERE emp_id = ?");
            $stmt->execute([$data['emp_id']]);
            if ($stmt->fetch()) {
                $errors[] = "Employee ID '" . $data['emp_id'] . "' is already in use.";
            }
        }

        return $errors;
    }

    public function create($data, $creatorId)
    {
        // Remove non-database fields if any
        unset($data['request_note']);

        // [FIX] Fetch valid columns from employees table to prevent unknown column crashes
        $colStmt = $this->pdo->query("SHOW COLUMNS FROM employees");
        $validCols = $colStmt ? $colStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        if (!empty($validCols)) {
            $data = array_intersect_key($data, array_flip($validCols));
        }

        // [FIX] Sanitize manager_id: if empty, 0, or non-existent in employees, convert to null
        if (array_key_exists('manager_id', $data)) {
            $mgrVal = $data['manager_id'];
            if (empty($mgrVal) || $mgrVal === '0' || $mgrVal === 0 || $mgrVal === '') {
                $data['manager_id'] = null;
            } else {
                $chkMgr = $this->pdo->prepare("SELECT id FROM employees WHERE id = ?");
                $chkMgr->execute([(int)$mgrVal]);
                if (!$chkMgr->fetch()) {
                    $data['manager_id'] = null;
                } else {
                    $data['manager_id'] = (int)$mgrVal;
                }
            }
        }

        $cols = array_keys($data);

        // Lines 65-68 in EmployeeService.php
        $cols = array_keys($data);

        // [FIX] Wrap all column names in backticks (handles 'group' and all other columns)
        $colsStr = "`" . implode("`, `", $cols) . "`";

        $valsStr = implode(", ", array_fill(0, count($cols), "?"));
        $sql = "INSERT INTO employees ($colsStr) VALUES ($valsStr)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));

        $id = $this->pdo->lastInsertId();

        if ($this->logger) {
            $this->logger->log($creatorId, 'ADD_EMPLOYEE', "Added employee: " . $data['emp_id']);
        }

        return $id;
    }
}

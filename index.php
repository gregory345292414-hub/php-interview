<?php
declare(strict_types=1);

/**
 * Org chart endpoint.
 *
 * Usage:
 *   GET /?action=manager&id=1010   -> the employee's direct manager
 *   GET /?action=reports&id=1002   -> everyone reporting directly to the employee
 *   GET /?action=tree&id=1006      -> the employee's full reporting tree, with levels
 *
 * Employees can also be looked up by name instead of id, e.g. ?name=Ben%20Walsh
 */

header('Content-Type: application/json');

const EMPLOYEE_FIELDS = 'id, first_name, last_name, title, timezone, active, manager_id';

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/employees.db', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    respond(500, ['error' => 'Could not open database. Run: sqlite3 employees.db < sqlite.sql']);
}

// --- Resolve the employee (by id, or by full name as in the spec examples) ---

function findEmployee(PDO $pdo): array
{
    if (isset($_GET['id'])) {
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        if ($id === false) {
            respond(400, ['error' => 'id must be an integer']);
        }
        $stmt = $pdo->prepare('SELECT ' . EMPLOYEE_FIELDS . ' FROM employees WHERE id = ?');
        $stmt->execute([$id]);
    } elseif (isset($_GET['name'])) {
        $stmt = $pdo->prepare(
            'SELECT ' . EMPLOYEE_FIELDS . " FROM employees
             WHERE lower(first_name || ' ' || last_name) = lower(?)"
        );
        $stmt->execute([trim($_GET['name'])]);
    } else {
        respond(400, ['error' => 'Provide an employee via ?id=1010 or ?name=Ben Walsh']);
    }

    $employee = $stmt->fetch();
    if ($employee === false) {
        respond(404, ['error' => 'Employee not found']);
    }
    return $employee;
}

// --- Actions ---

function manager(PDO $pdo, array $employee): ?array
{
    if ($employee['manager_id'] === null) {
        return null; // the CEO has no manager
    }
    $stmt = $pdo->prepare('SELECT ' . EMPLOYEE_FIELDS . ' FROM employees WHERE id = ?');
    $stmt->execute([$employee['manager_id']]);
    return $stmt->fetch() ?: null;
}

function directReports(PDO $pdo, array $employee): array
{
    $stmt = $pdo->prepare(
        'SELECT ' . EMPLOYEE_FIELDS . ' FROM employees WHERE manager_id = ? ORDER BY last_name, first_name'
    );
    $stmt->execute([$employee['id']]);
    return $stmt->fetchAll();
}

function everyoneBelow(PDO $pdo, array $employee): array
{
    // Recursive CTE: level 1 = direct reports, level 2 = their reports, etc.
    // Works on both SQLite and MySQL 8.
    $stmt = $pdo->prepare(
        'WITH RECURSIVE tree AS (
            SELECT id, first_name, last_name, title, timezone, active, manager_id, 1 AS level
            FROM employees WHERE manager_id = :id
            UNION ALL
            SELECT e.id, e.first_name, e.last_name, e.title, e.timezone, e.active, e.manager_id, t.level + 1
            FROM employees e JOIN tree t ON e.manager_id = t.id
        )
        SELECT * FROM tree ORDER BY level, last_name, first_name'
    );
    $stmt->execute(['id' => $employee['id']]);
    return nestReports($stmt->fetchAll(), $employee['id']);
}

/**
 * Fold the flat CTE rows into a nested tree: each person keeps their `level`
 * and gains a `reports` array holding the people directly under them.
 */
function nestReports(array $rows, int $rootId): array
{
    $byId = [];
    foreach ($rows as $row) {
        $row['reports'] = [];
        $byId[$row['id']] = $row;
    }

    $tree = [];
    foreach ($byId as &$node) {
        if ($node['manager_id'] === $rootId) {
            $tree[] = &$node;
        } else {
            $byId[$node['manager_id']]['reports'][] = &$node;
        }
    }
    unset($node);

    return $tree;
}



// --- Routing ---

$action = $_GET['action'] ?? null;
if (!in_array($action, ['manager', 'reports', 'tree'], true)) {
    respond(400, [
        'error' => 'Provide ?action=manager, reports or tree',
        'examples' => [
            '/?action=manager&id=1010',
            '/?action=reports&id=1002',
            '/?action=tree&name=Tom Becker',
        ],
    ]);
}

$employee = findEmployee($pdo);

$result = match ($action) {
    'manager' => ['manager' => manager($pdo, $employee)],
    'reports' => ['direct_reports' => directReports($pdo, $employee)],
    'tree'    => ['everyone_below' => everyoneBelow($pdo, $employee)],
};

respond(200, ['employee' => $employee] + $result);

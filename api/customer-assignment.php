<?php

$database->exec("CREATE TABLE IF NOT EXISTS customer_employee_assignments (
    Customer_ID INT UNSIGNED NOT NULL PRIMARY KEY,
    Employee_ID INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assignment_employee (Employee_ID),
    CONSTRAINT fk_assignment_customer FOREIGN KEY (Customer_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_employee FOREIGN KEY (Employee_ID) REFERENCES users(User_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function mtrAssignCustomerToEmployee(PDO $database, int $customerId): ?int
{
    if ($customerId < 1) {
        throw new InvalidArgumentException('A valid customer is required for employee assignment.');
    }

    $existing = $database->prepare('SELECT Employee_ID FROM customer_employee_assignments WHERE Customer_ID = ?');
    $existing->execute([$customerId]);
    $assignedEmployeeId = $existing->fetchColumn();
    if ($assignedEmployeeId !== false) {
        return (int) $assignedEmployeeId;
    }

    $employee = $database->query("SELECT u.User_ID
        FROM users u
        LEFT JOIN customer_employee_assignments a ON a.Employee_ID = u.User_ID
        WHERE u.role = 'employee'
        GROUP BY u.User_ID
        ORDER BY COUNT(a.Customer_ID), u.User_ID
        LIMIT 1")->fetchColumn();
    if ($employee === false) {
        return null;
    }

    $assignment = $database->prepare('INSERT INTO customer_employee_assignments (Customer_ID, Employee_ID) VALUES (?, ?) ON DUPLICATE KEY UPDATE Employee_ID = Employee_ID');
    $assignment->execute([$customerId, (int) $employee]);

    $existing->execute([$customerId]);
    $assignedEmployeeId = $existing->fetchColumn();
    return $assignedEmployeeId === false ? null : (int) $assignedEmployeeId;
}

function mtrBackfillCustomerEmployeeAssignments(PDO $database): void
{
    $customers = $database->query("SELECT DISTINCT u.User_ID
        FROM users u
        WHERE u.role = 'customer'
          AND NOT EXISTS (
              SELECT 1 FROM customer_employee_assignments a WHERE a.Customer_ID = u.User_ID
          )
          AND (
              EXISTS (SELECT 1 FROM bookings b WHERE b.Customer_ID = u.User_ID)
              OR EXISTS (SELECT 1 FROM support_tickets t WHERE t.User_ID = u.User_ID)
          )
        ORDER BY u.User_ID")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($customers as $customerId) {
        mtrAssignCustomerToEmployee($database, (int) $customerId);
    }
}

function mtrRequireAssignedCustomer(PDO $database, int $customerId, int $employeeId): void
{
    $assignment = $database->prepare('SELECT 1 FROM customer_employee_assignments WHERE Customer_ID = ? AND Employee_ID = ?');
    $assignment->execute([$customerId, $employeeId]);
    if (!$assignment->fetchColumn()) {
        throw new InvalidArgumentException('This customer is not assigned to your account.');
    }
}

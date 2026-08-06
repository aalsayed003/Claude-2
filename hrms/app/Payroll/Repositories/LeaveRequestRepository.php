<?php
namespace App\Payroll\Repositories;

use App\Core\Database;

/**
 * Employee self-service leave requests (Pay_LeaveRequest).
 *
 * Held on the payroll side so employees can request leave now; when the Duty
 * Roster / HR link is live these can be pushed into the HR LeaveApplication
 * table. Pending -> approved / rejected by HR (fa+).
 */
class LeaveRequestRepository
{
    public const PENDING = 1, APPROVED = 2, REJECTED = 3, CANCELLED = 9;
    public const STATE_LABELS = [
        self::PENDING => 'Pending', self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected', self::CANCELLED => 'Cancelled',
    ];

    public function __construct(private Database $db) {}

    public function forEmployee(int $empId): array
    {
        return $this->db->all(
            "SELECT * FROM " . pt('leave_request') . " WHERE EmployeeID = :e ORDER BY CreatedAt DESC",
            [':e' => $empId]);
    }

    public function find(int $id): ?array
    {
        return $this->db->one("SELECT * FROM " . pt('leave_request') . " WHERE RequestID = :id", [':id' => $id]);
    }

    public function create(array $d): int
    {
        return $this->db->insert(pt('leave_request'), [
            'EmployeeID' => (int) $d['employee_id'],
            'LeaveType'  => $d['leave_type'],
            'FromDate'   => $d['from'],
            'ToDate'     => $d['to'],
            'Days'       => (float) $d['days'],
            'Reason'     => $d['reason'] ?? null,
            'Contact'    => $d['contact'] ?? null,
            'StateID'    => self::PENDING,
        ]);
    }

    /** Pending requests across all staff, with names, for the HR desk. */
    public function pending(): array
    {
        $emp = lt('employee');
        return $this->db->all(
            "SELECT r.*, e.EmpCode AS emp_code, e.Name AS emp_name, d.Name AS dept_name
               FROM " . pt('leave_request') . " r
               JOIN {$emp} e ON e.ID = r.EmployeeID
               LEFT JOIN " . lt('department') . " d ON d.Id = e.DepartmentId
              WHERE r.StateID = :s ORDER BY r.CreatedAt",
            [':s' => self::PENDING]);
    }

    public function decide(int $id, int $state, string $user, ?string $note): void
    {
        $this->db->update(pt('leave_request'), [
            'StateID' => $state, 'DecidedBy' => $user,
            'DecidedAt' => date('Y-m-d H:i:s'), 'DecisionNote' => $note,
        ], 'RequestID = :id', [':id' => $id]);
    }
}

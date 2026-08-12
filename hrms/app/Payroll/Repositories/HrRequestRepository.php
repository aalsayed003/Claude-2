<?php
namespace App\Payroll\Repositories;

use App\Core\Database;

/**
 * Requests to HR (Pay_HrRequest) — salary certificates, letters, queries.
 * Employee raises; HR (fa+) responds and closes.
 */
class HrRequestRepository
{
    public const OPEN = 1, IN_PROGRESS = 2, RESOLVED = 3, CLOSED = 9;
    public const STATE_LABELS = [
        self::OPEN => 'Open', self::IN_PROGRESS => 'In progress',
        self::RESOLVED => 'Resolved', self::CLOSED => 'Closed',
    ];

    public function __construct(private Database $db) {}

    public function forEmployee(int $empId): array
    {
        return $this->db->all(
            "SELECT * FROM " . pt('hr_request') . " WHERE EmployeeID = :e ORDER BY CreatedAt DESC",
            [':e' => $empId]);
    }

    public function find(int $id): ?array
    {
        return $this->db->one("SELECT * FROM " . pt('hr_request') . " WHERE RequestID = :id", [':id' => $id]);
    }

    public function create(array $d): int
    {
        return $this->db->insert(pt('hr_request'), [
            'EmployeeID' => (int) $d['employee_id'],
            'Category'   => $d['category'],
            'Subject'    => $d['subject'],
            'Message'    => $d['message'] ?? null,
            'StateID'    => self::OPEN,
        ]);
    }

    /** Open / in-progress requests across staff for the HR desk. */
    public function queue(?string $category = null): array
    {
        $emp = lt('employee');
        $params = [':o' => self::OPEN, ':p' => self::IN_PROGRESS];
        $catSql = '';
        if ($category !== null && $category !== '') {
            $catSql = ' AND r.Category = :c';
            $params[':c'] = $category;
        }
        return $this->db->all(
            "SELECT r.*, e.EmpCode AS emp_code, e.Name AS emp_name
               FROM " . pt('hr_request') . " r
               JOIN {$emp} e ON e.ID = r.EmployeeID
              WHERE r.StateID IN (:o, :p){$catSql} ORDER BY r.CreatedAt",
            $params);
    }

    /** Count of unresolved requests, optionally for one category. */
    public function openCount(?string $category = null): int
    {
        $params = [':o' => self::OPEN, ':p' => self::IN_PROGRESS];
        $catSql = '';
        if ($category !== null && $category !== '') {
            $catSql = ' AND Category = :c';
            $params[':c'] = $category;
        }
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM " . pt('hr_request') . " WHERE StateID IN (:o, :p){$catSql}",
            $params);
    }

    public function respond(int $id, int $state, ?string $response, string $user): void
    {
        $this->db->update(pt('hr_request'), [
            'StateID' => $state, 'Response' => $response,
            'HandledBy' => $user, 'HandledAt' => date('Y-m-d H:i:s'),
        ], 'RequestID = :id', [':id' => $id]);
    }
}

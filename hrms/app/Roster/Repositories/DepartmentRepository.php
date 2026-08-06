<?php
namespace App\Roster\Repositories;

use App\Core\Database;

/** Reads departments from the legacy `Department` master. */
class DepartmentRepository
{
    public function __construct(private Database $db) {}

    public function all(): array
    {
        $dep = lt('department');
        $rows = $this->db->all(
            "SELECT Id AS id, Name AS name, DeptCode AS code
               FROM {$dep} WHERE Deleted = 0 ORDER BY Name"
        );
        return $rows;
    }

    public function find(int $id): ?array
    {
        $dep = lt('department');
        return $this->db->one(
            "SELECT Id AS id, Name AS name, DeptCode AS code FROM {$dep} WHERE Id = :id",
            [':id' => $id]
        );
    }
}

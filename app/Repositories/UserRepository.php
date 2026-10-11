<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

final class UserRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE u.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }

        $total = (int) $this->fetchOne("SELECT COUNT(*) FROM users u{$where}", $params);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.name, u.email, u.phone, u.is_active, u.last_login_at, u.created_at,
                    GROUP_CONCAT(r.name SEPARATOR ', ') AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             {$where}
             GROUP BY u.id
             ORDER BY u.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, u.phone, u.is_active, u.last_login_at, u.created_at
             FROM users u WHERE u.id = ? AND u.deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['role_ids'] = array_map('intval', $this->pdo->query(
            'SELECT role_id FROM user_roles WHERE user_id = ' . (int) $id
        )->fetchAll(PDO::FETCH_COLUMN));
        return $row;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, email FROM users WHERE email = ? AND deleted_at IS NULL'
        );
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(string $name, string $email, string $password, array $roleIds, ?string $phone = null): int
    {
        return \App\Support\Database\Connection::transaction(function (PDO $pdo) use ($name, $email, $password, $roleIds, $phone) {
            $stmt = $pdo->prepare('INSERT INTO users (name, email, phone, password) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, strtolower(trim($email)), $phone, password_hash($password, PASSWORD_DEFAULT)]);
            $id = (int) $pdo->lastInsertId();

            $link = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
            foreach (array_map('intval', $roleIds) as $roleId) {
                $link->execute([$id, $roleId]);
            }
            return $id;
        });
    }

    public function update(int $id, string $name, ?string $phone, array $roleIds): void
    {
        \App\Support\Database\Connection::transaction(function (PDO $pdo) use ($id, $name, $phone, $roleIds) {
            $stmt = $pdo->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?');
            $stmt->execute([$name, $phone, $id]);

            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
            $link = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
            foreach (array_map('intval', array_unique($roleIds)) as $roleId) {
                $link->execute([$id, $roleId]);
            }
        });
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    /**
     * Guard: refuse to deactivate or remove the owner role from the last
     * active owner/super-administrator.
     */
    public static function assertNotLastOwner(int $userId): void
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT u.id)
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.slug = 'owner' AND u.is_active = 1 AND u.deleted_at IS NULL AND u.id != ?"
        );
        $stmt->execute([$userId]);
        $others = (int) $stmt->fetchColumn();

        $isOwner = $pdo->prepare(
            "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.slug = 'owner'"
        );
        $isOwner->execute([$userId]);

        if ((int) $isOwner->fetchColumn() > 0 && $others === 0) {
            throw new ValidationException([
                'user' => 'This is the last active super administrator. Promote another owner first.',
            ]);
        }
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'
        )->fetchColumn();
    }

    private function fetchOne(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}

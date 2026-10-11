<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

final class RoleRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /** @return list<array<string,mixed>> */
    public function allWithCounts(): array
    {
        return $this->pdo->query(
            'SELECT r.id, r.name, r.slug, r.description, r.is_system,
                    (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
             FROM roles r ORDER BY r.id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, slug, description, is_system FROM roles WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['permission_names'] = $this->pdo->query(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ' . (int) $id
        )->fetchAll(PDO::FETCH_COLUMN);
        return $row;
    }

    /** @return array<string,list<array<string,mixed>>> module => permissions */
    public function permissionsGrouped(): array
    {
        $rows = $this->pdo->query('SELECT id, name, module, description FROM permissions ORDER BY module, name')
            ->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['module']][] = $row;
        }
        return $grouped;
    }

    /**
     * Replace a role's permission set. Owner role always keeps everything.
     * Audited by the caller.
     */
    public function syncPermissions(int $roleId, array $permissionNames): void
    {
        \App\Support\Database\Connection::transaction(function (PDO $pdo) use ($roleId, $permissionNames) {
            $slug = $pdo->prepare('SELECT slug FROM roles WHERE id = ?');
            $slug->execute([$roleId]);
            $roleSlug = (string) $slug->fetchColumn();

            if ($roleSlug === 'owner') {
                return; // owner implicitly holds every permission
            }

            $safe = [];
            foreach ($permissionNames as $name) {
                $stmt = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
                $stmt->execute([(string) $name]);
                $id = $stmt->fetchColumn();
                if ($id !== false) {
                    $safe[] = (int) $id;
                }
            }

            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
            $link = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            foreach (array_unique($safe) as $permissionId) {
                $link->execute([$roleId, $permissionId]);
            }
        });
    }

    /** @return array<int,string> id => slug */
    public function idSlugMap(): array
    {
        $map = [];
        foreach ($this->pdo->query('SELECT id, slug FROM roles')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['id']] = $row['slug'];
        }
        return $map;
    }

    /**
     * Create a new custom (non-system) role. Slug must be unique.
     * Returns the new role id.
     */
    public function create(string $name, string $slug, ?string $description = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO roles (name, slug, description, is_system) VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$name, $slug, $description]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Whether a slug is already taken (case-insensitive). */
    public function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM roles WHERE slug = ?');
        $stmt->execute([$slug]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Members of a role: user id, name, email, is_active, last_login_at.
     * Returns up to $limit rows, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function members(int $roleId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, u.is_active, u.last_login_at, u.deleted_at
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             WHERE ur.role_id = ?
             ORDER BY u.name ASC
             LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete a non-system role that has no members. System roles
     * (owner, customer, etc.) can never be deleted. Roles with
     * active members must be emptied first (reassign users before
     * deleting).
     */
    public function delete(int $roleId): void
    {
        $role = $this->find($roleId);
        if ($role === null) {
            throw new \App\Exceptions\NotFoundException('Role not found.');
        }
        if ((int) $role['is_system'] === 1) {
            throw new \App\Exceptions\ValidationException([
                'role' => 'System roles cannot be deleted.',
            ]);
        }
        $members = $this->members($roleId, 500);
        if ($members !== []) {
            throw new \App\Exceptions\ValidationException([
                'role' => 'Cannot delete a role that still has members. Reassign or remove them first.',
            ]);
        }

        \App\Support\Database\Connection::transaction(function (PDO $pdo) use ($roleId) {
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
            $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
        });
    }
}

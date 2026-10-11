<?php

declare(strict_types=1);

/**
 * Secure first-administrator setup (CLI only — never exposed over HTTP).
 *
 *   php bin/create-admin.php
 *   php bin/create-admin.php --name="Owner" --email=owner@example.com
 *
 * - Prompts for the password with echo disabled (interactive mode).
 * - Requires a strong password (>= 10 chars).
 * - Assigns the 'owner' system role (full permissions).
 * - Refuses to run while an active owner already exists unless --force
 *   is explicitly given (for recovery scenarios).
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Support\Database\Connection;

// ---- Gather input ------------------------------------------------------------

$options = getopt('', ['name::', 'email::', 'password::', 'force']);

$name = trim((string) ($options['name'] ?? readline('Administrator name: ')));
$email = strtolower(trim((string) ($options['email'] ?? readline('Administrator email: '))));

$force = array_key_exists('force', $options);

if (isset($options['password'])) {
    // Non-interactive path (careful: password visible in shell history — interactive preferred).
    $password = (string) $options['password'];
    $confirm = $password;
} else {
    if (stripos(PHP_OS, 'WIN') === 0) {
        // Windows lacks readline password hiding; read via hidden PowerShell prompt.
        $read = fn (string $prompt) => trim((string) shell_exec(
            'powershell -Command "$p = Read-Host -Prompt \'' . $prompt . '\' -AsSecureString; '
            . '$b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($p); '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto($b)"'
        ));
        $password = $read('Password');
        $confirm = $read('Confirm password');
    } else {
        system('stty -echo');
        $password = readline('Password: ');
        $confirm = readline('Confirm password: ');
        system('stty echo');
        print(PHP_EOL);
    }
}

// ---- Validate -------------------------------------------------------------------

$errors = [];
if ($name === '') {
    $errors[] = 'Name is required.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}
if (strlen($password) < 10) {
    $errors[] = 'Password must be at least 10 characters.';
}
if ($password !== $confirm) {
    $errors[] = 'Password confirmation does not match.';
}
if ($password !== '' && (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))) {
    $errors[] = 'Password must contain both letters and numbers.';
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

try {
    $pdo = Connection::pdo();

    // Guard: no public web installer, no accidental duplicate owners.
    $activeOwners = (int) $pdo->query(
        "SELECT COUNT(DISTINCT u.id) FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id
         WHERE r.slug = 'owner' AND u.is_active = 1 AND u.deleted_at IS NULL"
    )->fetchColumn();

    if ($activeOwners > 0 && !$force) {
        fwrite(
            STDERR,
            "An active owner already exists ({$activeOwners}). "
            . "Use admin user management instead, or pass --force for recovery." . PHP_EOL
        );
        exit(1);
    }

    $existing = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $existing->execute([$email]);
    $userId = $existing->fetchColumn();

    if ($userId === false) {
        $insert = $pdo->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
        $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();
        $action = 'created';
    } else {
        $update = $pdo->prepare('UPDATE users SET name = ?, password = ?, is_active = 1 WHERE id = ?');
        $update->execute([$name, password_hash($password, PASSWORD_DEFAULT), (int) $userId]);
        $userId = (int) $userId;
        $action = 'updated (recovered)';
    }

    $role = $pdo->prepare("SELECT id FROM roles WHERE slug = 'owner'");
    $role->execute();
    $roleId = $role->fetchColumn();
    if ($roleId === false) {
        fwrite(STDERR, "Owner role missing — run `php bin/seed.php` first." . PHP_EOL);
        exit(1);
    }

    $link = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
    $link->execute([$userId, (int) $roleId]);

    AuditServiceRecord($userId, 'admin.setup', 'user', $userId, ['action' => $action, 'via' => 'bin/create-admin.php']);

    print("Administrator {$action}: {$email} (owner role assigned)." . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'CREATE ADMIN FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function AuditServiceRecord(int $userId, string $action, string $type, int $entityId, array $meta): void
{
    $stmt = Connection::pdo()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId, $action, $type, $entityId,
        json_encode($meta, JSON_UNESCAPED_SLASHES), 'cli',
    ]);
}

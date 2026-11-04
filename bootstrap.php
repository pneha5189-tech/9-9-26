<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'placement_system';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPassword = getenv('DB_PASSWORD') ?: '';
$pdo = null;
$databaseError = null;

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    error_log('Placement system database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database is unavailable. Check the MySQL service and the DB_* settings, then reload this page.';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

function verify_csrf(): void
{
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(400);
        exit('Invalid form token. Return to the previous page and try again.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function redirect(string $page = 'dashboard'): never
{
    header('Location: index.php?page=' . rawurlencode($page));
    exit;
}

function require_role(array $roles): array
{
    global $currentUser;
    if (!$currentUser || !in_array($currentUser['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to perform this action.');
    }
    return $currentUser;
}

$currentUser = null;
if ($pdo && isset($_SESSION['user_id'])) {
    $statement = $pdo->prepare('SELECT id, name, email, role, company_id FROM users WHERE id = ? AND is_active = 1');
    $statement->execute([$_SESSION['user_id']]);
    $currentUser = $statement->fetch() ?: null;
    if (!$currentUser) {
        unset($_SESSION['user_id']);
    }
}

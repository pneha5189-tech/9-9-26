<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function api_string(array $source, string $key): string
{
    return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : '';
}

function api_current_user(): array
{
    global $currentUser;
    if (!$currentUser) {
        api_response(['error' => 'Sign in to continue.'], 401);
    }
    return $currentUser;
}

function api_require_role(array $roles): array
{
    $user = api_current_user();
    if (!in_array($user['role'], $roles, true)) {
        api_response(['error' => 'You do not have permission to perform this action.'], 403);
    }
    return $user;
}

function api_json_body(): array
{
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        api_response(['error' => 'Request body must be valid JSON.'], 400);
    }
    return $body;
}

function api_csrf(): void
{
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        api_response(['error' => 'Your session token expired. Refresh the page and try again.'], 400);
    }
}

function api_profile(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM students WHERE user_id = ?');
    $statement->execute([$userId]);
    return $statement->fetch() ?: null;
}

function api_eligible(?array $student, array $job): bool
{
    if (!$student || (float) $student['cgpa'] < (float) $job['min_cgpa']) {
        return false;
    }
    if ($job['graduation_year'] !== null && (int) $student['graduation_year'] !== (int) $job['graduation_year']) {
        return false;
    }
    $departments = array_map('trim', explode(',', (string) $job['eligible_departments']));
    return in_array('*', $departments, true)
        || in_array(strtolower((string) $student['department']), array_map('strtolower', $departments), true);
}

function api_job_rows(PDO $pdo, array $user): array
{
    if ($user['role'] === 'student') {
        $jobs = $pdo->query("SELECT j.*, c.name AS company_name FROM jobs j JOIN companies c ON c.id = j.company_id WHERE j.status = 'open' ORDER BY j.created_at DESC")->fetchAll();
    } elseif ($user['role'] === 'recruiter') {
        $statement = $pdo->prepare('SELECT j.*, c.name AS company_name FROM jobs j JOIN companies c ON c.id = j.company_id WHERE j.company_id = ? ORDER BY j.created_at DESC');
        $statement->execute([(int) $user['company_id']]);
        $jobs = $statement->fetchAll();
    } else {
        $jobs = $pdo->query('SELECT j.*, c.name AS company_name FROM jobs j JOIN companies c ON c.id = j.company_id ORDER BY j.created_at DESC')->fetchAll();
    }
    return $jobs;
}

function api_application_query(): string
{
    return 'SELECT a.id, a.status, a.recruiter_notes, a.applied_at, a.updated_at,
                   u.name AS student_name, s.roll_number, s.department, s.cgpa,
                   j.id AS job_id, j.title, c.name AS company_name, j.company_id
            FROM applications a JOIN students s ON s.id = a.student_id
            JOIN users u ON u.id = s.user_id JOIN jobs j ON j.id = a.job_id
            JOIN companies c ON c.id = j.company_id';
}

function api_application_rows(PDO $pdo, array $user): array
{
    $base = api_application_query();
    if ($user['role'] === 'student') {
        $profile = api_profile($pdo, (int) $user['id']);
        $statement = $pdo->prepare($base . ' WHERE a.student_id = ? ORDER BY a.updated_at DESC');
        $statement->execute([(int) ($profile['id'] ?? 0)]);
    } elseif ($user['role'] === 'recruiter') {
        $statement = $pdo->prepare($base . ' WHERE j.company_id = ? ORDER BY a.updated_at DESC');
        $statement->execute([(int) $user['company_id']]);
    } else {
        $statement = $pdo->query($base . ' ORDER BY a.updated_at DESC');
    }
    return $statement->fetchAll();
}

function api_dashboard(PDO $pdo, array $user, ?array $profile): array
{
    if ($user['role'] === 'admin') {
        $metrics = [
            'Students' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
            'Open roles' => (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'open'")->fetchColumn(),
            'Applications' => (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn(),
            'Offers' => (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'offered'")->fetchColumn(),
        ];
        $statusRows = $pdo->query("SELECT status, COUNT(*) AS total FROM applications GROUP BY status")->fetchAll();
        $departmentRows = $pdo->query(
            "SELECT s.department, COUNT(a.id) AS applications,
                    SUM(CASE WHEN a.status = 'offered' THEN 1 ELSE 0 END) AS offers
             FROM students s LEFT JOIN applications a ON a.student_id = s.id
             GROUP BY s.department ORDER BY applications DESC, s.department"
        )->fetchAll();
    } elseif ($user['role'] === 'recruiter') {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE company_id = ? AND status = 'open'");
        $statement->execute([(int) $user['company_id']]);
        $metrics = ['Open roles' => (int) $statement->fetchColumn()];
        $statement = $pdo->prepare('SELECT a.status, COUNT(*) AS total FROM applications a JOIN jobs j ON j.id = a.job_id WHERE j.company_id = ? GROUP BY a.status');
        $statement->execute([(int) $user['company_id']]);
        $statusRows = $statement->fetchAll();
        $counts = array_column($statusRows, 'total', 'status');
        $metrics['Applications'] = array_sum(array_map('intval', $counts));
        $metrics['Interviews'] = (int) ($counts['interviewed'] ?? 0);
        $metrics['Offers'] = (int) ($counts['offered'] ?? 0);
        $departmentRows = [];
    } else {
        $jobs = $pdo->query("SELECT * FROM jobs WHERE status = 'open'")->fetchAll();
        $eligibleJobs = count(array_filter($jobs, static fn(array $job): bool => api_eligible($profile, $job)));
        $studentId = (int) ($profile['id'] ?? 0);
        $statement = $pdo->prepare('SELECT status, COUNT(*) AS total FROM applications WHERE student_id = ? GROUP BY status');
        $statement->execute([$studentId]);
        $counts = array_column($statement->fetchAll(), 'total', 'status');
        $metrics = [
            'Eligible open roles' => $eligibleJobs,
            'Applications' => array_sum(array_map('intval', $counts)),
            'Interviews' => (int) ($counts['interviewed'] ?? 0),
            'Offers' => (int) ($counts['offered'] ?? 0),
        ];
        $statusRows = [];
        $departmentRows = [];
    }
    $applications = api_application_rows($pdo, $user);
    return [
        'metrics' => $metrics,
        'pipeline' => array_column($statusRows, 'total', 'status'),
        'departmentReports' => $departmentRows,
        'recentApplications' => array_slice($applications, 0, 6),
    ];
}

if (!$pdo) {
    api_response(['error' => $databaseError ?? 'Database unavailable.'], 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = api_string($_GET, 'action');

if ($method === 'GET' && $action === 'session') {
    api_response([
        'user' => $currentUser,
        'csrfToken' => $_SESSION['csrf_token'],
    ]);
}

if ($method === 'POST') {
    api_csrf();
    $body = api_json_body();

    if ($action === 'login') {
        $email = api_string($body, 'email');
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $statement = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ? AND is_active = 1');
        $statement->execute([$email]);
        $account = $statement->fetch();
        if (strlen($password) > 72 || !$account || !password_verify($password, $account['password_hash'])) {
            api_response(['error' => 'Email or password is incorrect.'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $account['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        api_response(['message' => 'Signed in.', 'csrfToken' => $_SESSION['csrf_token']]);
    }

    if ($action === 'logout') {
        api_current_user();
        $_SESSION = [];
        session_destroy();
        api_response(['message' => 'Signed out.']);
    }

    if ($action === 'save_profile') {
        $user = api_require_role(['student']);
        $rollNumber = api_string($body, 'roll_number');
        $department = api_string($body, 'department');
        $year = filter_var($body['graduation_year'] ?? null, FILTER_VALIDATE_INT);
        $cgpa = filter_var($body['cgpa'] ?? null, FILTER_VALIDATE_FLOAT);
        $phone = api_string($body, 'phone');
        $skills = api_string($body, 'skills');
        if (!$rollNumber || strlen($rollNumber) > 40 || !$department || strlen($department) > 100
            || $year === false || $year < 2000 || $year > 2100 || $cgpa === false || $cgpa < 0 || $cgpa > 10
            || strlen($phone) > 30 || strlen($skills) > 500) {
            api_response(['error' => 'Check the roll number, department, graduation year, CGPA, phone, and skills.'], 422);
        }
        try {
            $statement = $pdo->prepare(
                'INSERT INTO students (user_id, roll_number, department, graduation_year, cgpa, phone, skills)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE roll_number = VALUES(roll_number), department = VALUES(department),
                   graduation_year = VALUES(graduation_year), cgpa = VALUES(cgpa), phone = VALUES(phone), skills = VALUES(skills)'
            );
            $statement->execute([(int) $user['id'], $rollNumber, $department, $year, $cgpa, $phone ?: null, $skills ?: null]);
        } catch (PDOException $exception) {
            error_log('Student profile save failed: ' . $exception->getMessage());
            api_response(['error' => 'Profile could not be saved. Check whether the roll number is already in use.'], 409);
        }
        api_response(['message' => 'Student profile saved.']);
    }

    if ($action === 'create_job') {
        $user = api_require_role(['admin', 'recruiter']);
        $title = api_string($body, 'title');
        $description = api_string($body, 'description');
        $location = api_string($body, 'location');
        $minCgpa = filter_var($body['min_cgpa'] ?? null, FILTER_VALIDATE_FLOAT);
        $departmentInput = api_string($body, 'eligible_departments');
        $yearInput = api_string($body, 'graduation_year');
        $year = $yearInput === '' ? null : filter_var($yearInput, FILTER_VALIDATE_INT);
        if (!$title || strlen($title) > 150 || !$description || strlen($description) > 10000
            || !$location || strlen($location) > 120 || $minCgpa === false || $minCgpa < 0 || $minCgpa > 10
            || !$departmentInput || strlen($departmentInput) > 255
            || ($yearInput !== '' && ($year === false || $year < 2000 || $year > 2100))) {
            api_response(['error' => 'Check the job details and eligibility fields.'], 422);
        }
        $departments = array_values(array_filter(array_map('trim', explode(',', $departmentInput))));
        if (!$departments) {
            api_response(['error' => 'Enter eligible departments separated by commas, or use * for all departments.'], 422);
        }
        $companyId = $user['role'] === 'recruiter'
            ? (int) ($user['company_id'] ?? 0)
            : filter_var($body['company_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$companyId || $companyId === false) {
            api_response(['error' => 'Choose a valid company.'], 422);
        }
        $companyCheck = $pdo->prepare('SELECT id FROM companies WHERE id = ?');
        $companyCheck->execute([$companyId]);
        if (!$companyCheck->fetch()) {
            api_response(['error' => 'The selected company does not exist.'], 422);
        }
        $statement = $pdo->prepare(
            'INSERT INTO jobs (company_id, posted_by, title, description, location, min_cgpa, eligible_departments, graduation_year)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $companyId, (int) $user['id'], $title, $description, $location, $minCgpa,
            in_array('*', $departments, true) ? '*' : implode(',', $departments), $year,
        ]);
        api_response(['message' => 'Job posting created.']);
    }

    if ($action === 'toggle_job') {
        $user = api_require_role(['admin', 'recruiter']);
        $jobId = filter_var($body['job_id'] ?? null, FILTER_VALIDATE_INT);
        $status = api_string($body, 'status');
        if (!$jobId || !in_array($status, ['open', 'closed'], true)) {
            api_response(['error' => 'Invalid job posting.'], 422);
        }
        $statement = $pdo->prepare("UPDATE jobs SET status = ? WHERE id = ? AND (? = 'admin' OR company_id = ?)");
        $statement->execute([$status, $jobId, $user['role'], (int) ($user['company_id'] ?? 0)]);
        if (!$statement->rowCount()) {
            api_response(['error' => 'Job not found or outside your company.'], 404);
        }
        api_response(['message' => 'Job posting updated.']);
    }

    if ($action === 'apply') {
        $user = api_require_role(['student']);
        $profile = api_profile($pdo, (int) $user['id']);
        $jobId = filter_var($body['job_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$profile || !$jobId) {
            api_response(['error' => 'Complete your profile before applying.'], 422);
        }
        $statement = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND status = 'open'");
        $statement->execute([$jobId]);
        $job = $statement->fetch();
        if (!$job || !api_eligible($profile, $job)) {
            api_response(['error' => 'This job is unavailable or your profile does not meet its criteria.'], 422);
        }
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO applications (job_id, student_id) VALUES (?, ?)');
            $statement->execute([$jobId, $profile['id']]);
            $applicationId = (int) $pdo->lastInsertId();
            $statement = $pdo->prepare("INSERT INTO application_events (application_id, changed_by, old_status, new_status) VALUES (?, ?, NULL, 'applied')");
            $statement->execute([$applicationId, (int) $user['id']]);
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Application submission failed: ' . $exception->getMessage());
            api_response(['error' => 'Application could not be submitted. You may already have applied.'], 409);
        }
        api_response(['message' => 'Application submitted.']);
    }

    if ($action === 'update_application') {
        $user = api_require_role(['admin', 'recruiter']);
        $applicationId = filter_var($body['application_id'] ?? null, FILTER_VALIDATE_INT);
        $status = api_string($body, 'status');
        $notes = api_string($body, 'notes');
        if (!$applicationId || strlen($notes) > 1000
            || !in_array($status, ['applied', 'shortlisted', 'interviewed', 'offered', 'rejected'], true)) {
            api_response(['error' => 'Invalid application update.'], 422);
        }
        $statement = $pdo->prepare('SELECT a.id, a.status, j.company_id FROM applications a JOIN jobs j ON j.id = a.job_id WHERE a.id = ?');
        $statement->execute([$applicationId]);
        $application = $statement->fetch();
        if (!$application || ($user['role'] === 'recruiter' && (int) $application['company_id'] !== (int) $user['company_id'])) {
            api_response(['error' => 'You cannot update this application.'], 403);
        }
        $transitions = [
            'applied' => ['shortlisted', 'rejected'],
            'shortlisted' => ['interviewed', 'rejected'],
            'interviewed' => ['offered', 'rejected'],
            'offered' => [],
            'rejected' => [],
        ];
        if ($status !== $application['status'] && !in_array($status, $transitions[$application['status']], true)) {
            api_response(['error' => 'Candidates move through Applied → Shortlisted → Interviewed → Offered, or can be rejected.'], 422);
        }
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('UPDATE applications SET status = ?, recruiter_notes = ? WHERE id = ?');
            $statement->execute([$status, $notes ?: null, $applicationId]);
            if ($status !== $application['status']) {
                $statement = $pdo->prepare('INSERT INTO application_events (application_id, changed_by, old_status, new_status, note) VALUES (?, ?, ?, ?, ?)');
                $statement->execute([$applicationId, (int) $user['id'], $application['status'], $status, $notes ?: null]);
            }
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Application status update failed: ' . $exception->getMessage());
            api_response(['error' => 'Application update failed. Please try again.'], 500);
        }
        api_response(['message' => 'Application update saved.']);
    }

    if ($action === 'create_user') {
        api_require_role(['admin']);
        $name = api_string($body, 'name');
        $email = api_string($body, 'email');
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $role = api_string($body, 'role');
        $companyId = filter_var($body['company_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$name || strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
            || strlen($password) < 10 || strlen($password) > 72 || !in_array($role, ['student', 'recruiter'], true)
            || ($role === 'recruiter' && !$companyId)) {
            api_response(['error' => 'Enter a valid name, email, password (10–72 characters), role, and recruiter company when applicable.'], 422);
        }
        $company = null;
        if ($role === 'recruiter') {
            $statement = $pdo->prepare('SELECT id FROM companies WHERE id = ?');
            $statement->execute([$companyId]);
            if (!$statement->fetch()) {
                api_response(['error' => 'The selected company does not exist.'], 422);
            }
            $company = $companyId;
        }
        try {
            $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, company_id) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $company]);
        } catch (PDOException $exception) {
            error_log('Account creation failed: ' . $exception->getMessage());
            api_response(['error' => 'Account could not be created. The email may already be registered.'], 409);
        }
        api_response(['message' => ucfirst($role) . ' account created.']);
    }

    api_response(['error' => 'Unknown action.'], 404);
}

if ($method !== 'GET') {
    api_response(['error' => 'Method not allowed.'], 405);
}

$user = api_current_user();
$profile = $user['role'] === 'student' ? api_profile($pdo, (int) $user['id']) : null;
$resource = api_string($_GET, 'resource');
if ($resource === 'bootstrap') {
    api_response([
        'user' => $user,
        'profile' => $profile,
        'dashboard' => api_dashboard($pdo, $user, $profile),
        'jobs' => api_job_rows($pdo, $user),
        'applications' => api_application_rows($pdo, $user),
        'companies' => $user['role'] === 'admin' ? $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll() : [],
    ]);
}
api_response(['error' => 'Unknown resource.'], 404);

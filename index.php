<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function student_profile(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM students WHERE user_id = ?');
    $statement->execute([$userId]);
    return $statement->fetch() ?: null;
}

function job_is_eligible(?array $student, array $job): bool
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

function posted_string(string $key, int $maxLength): ?string
{
    $rawValue = $_POST[$key] ?? '';
    if (!is_string($rawValue)) {
        return null;
    }
    $value = trim($rawValue);
    if ($value === '' || strlen($value) > $maxLength) {
        return null;
    }
    return $value;
}

function request_string(array $source, string $key): string
{
    $value = $source[$key] ?? '';
    return is_string($value) ? $value : '';
}

function render_job_cards(array $jobs, ?array $student, array $appliedJobIds = []): void
{
    if (!$jobs) {
        echo '<div class="empty-state">No job postings to show yet.</div>';
        return;
    }
    foreach ($jobs as $job) {
        $eligible = $student ? job_is_eligible($student, $job) : null;
        echo '<article class="job-card"><div class="job-heading"><div><p class="eyebrow">' . e($job['company_name']) . '</p><h3>' . e($job['title']) . '</h3></div>';
        echo '<span class="badge">' . e(ucfirst($job['status'])) . '</span></div>';
        echo '<p>' . nl2br(e($job['description'])) . '</p><div class="job-meta">';
        echo '<span>' . e($job['location']) . '</span><span>Minimum CGPA: ' . e($job['min_cgpa']) . '</span>';
        echo '<span>Departments: ' . e($job['eligible_departments'] === '*' ? 'All' : $job['eligible_departments']) . '</span>';
        echo '<span>Graduation year: ' . e($job['graduation_year'] ?: 'Any') . '</span></div>';
        if ($student) {
            if ($eligible) {
                if (in_array((int) $job['id'], $appliedJobIds, true)) {
                    echo '<span class="status-text">Application submitted</span>';
                } else {
                    echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="apply"><input type="hidden" name="job_id" value="' . (int) $job['id'] . '"><button type="submit">Apply now</button></form>';
                }
            } else {
                echo '<span class="muted">Your profile does not meet the listed criteria.</span>';
            }
        } elseif (isset($GLOBALS['currentUser']) && in_array($GLOBALS['currentUser']['role'], ['admin', 'recruiter'], true)) {
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="toggle_job"><input type="hidden" name="job_id" value="' . (int) $job['id'] . '"><input type="hidden" name="new_status" value="' . ($job['status'] === 'open' ? 'closed' : 'open') . '"><button class="button-secondary" type="submit">' . ($job['status'] === 'open' ? 'Close posting' : 'Reopen posting') . '</button></form>';
        }
        echo '</article>';
    }
}

function render_application_table(array $applications, bool $canManage): void
{
    if (!$applications) {
        echo '<div class="empty-state">No applications found.</div>';
        return;
    }
    echo '<div class="table-wrap"><table><thead><tr><th>Student</th><th>Role / company</th><th>Applied</th><th>Status</th>';
    if ($canManage) {
        echo '<th>Interview / selection update</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($applications as $application) {
        echo '<tr><td><strong>' . e($application['student_name']) . '</strong><small>' . e($application['roll_number']) . ' · ' . e($application['department']) . ' · CGPA ' . e($application['cgpa']) . '</small></td>';
        echo '<td>' . e($application['title']) . '<small>' . e($application['company_name']) . '</small></td><td>' . e(date('M j, Y', strtotime($application['applied_at']))) . '</td>';
        echo '<td><span class="status status-' . e($application['status']) . '">' . e(ucfirst($application['status'])) . '</span>';
        if (!empty($application['recruiter_notes'])) {
            echo '<small>' . e($application['recruiter_notes']) . '</small>';
        }
        echo '</td>';
        if ($canManage) {
            echo '<td><form method="post" class="status-form">' . csrf_field() . '<input type="hidden" name="action" value="update_application"><input type="hidden" name="application_id" value="' . (int) $application['id'] . '"><select name="status" aria-label="New application status">';
            $nextStatuses = [
                'applied' => ['applied', 'shortlisted', 'rejected'],
                'shortlisted' => ['shortlisted', 'interviewed', 'rejected'],
                'interviewed' => ['interviewed', 'offered', 'rejected'],
                'offered' => ['offered'],
                'rejected' => ['rejected'],
            ];
            foreach ($nextStatuses[$application['status']] as $status) {
                $selected = $application['status'] === $status ? ' selected' : '';
                echo '<option value="' . e($status) . '"' . $selected . '>' . e(ucfirst($status)) . '</option>';
            }
            echo '</select><input name="notes" maxlength="1000" placeholder="Interview notes (optional)" value="' . e($application['recruiter_notes'] ?? '') . '"><button type="submit">Save</button></form></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    verify_csrf();
    $action = request_string($_POST, 'action');

    if ($action === 'login') {
        $email = trim(request_string($_POST, 'email'));
        $password = request_string($_POST, 'password');
        $statement = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ? AND is_active = 1');
        $statement->execute([$email]);
        $account = $statement->fetch();
        if (strlen($password) <= 72 && $account && password_verify($password, $account['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $account['id'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            flash('Welcome back.');
            redirect('dashboard');
        }
        flash('Email or password is incorrect.', 'error');
        redirect('login');
    }

    if ($action === 'logout') {
        require_role(['admin', 'student', 'recruiter']);
        $_SESSION = [];
        session_destroy();
        header('Location: index.php?page=login');
        exit;
    }

    if (!$currentUser) {
        http_response_code(401);
        exit('Please sign in before submitting this form.');
    }

    if ($action === 'create_user') {
        require_role(['admin']);
        $name = posted_string('name', 120);
        $email = trim(request_string($_POST, 'email'));
        $password = request_string($_POST, 'password');
        $role = request_string($_POST, 'role');
        $companyInput = request_string($_POST, 'company_id');
        $companyId = $companyInput === '' ? null : filter_var($companyInput, FILTER_VALIDATE_INT);
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
            || strlen($password) < 10 || strlen($password) > 72
            || !in_array($role, ['student', 'recruiter'], true)
            || ($role === 'recruiter' && (!$companyId || $companyId === false))
            || ($role === 'student' && $companyInput !== '')) {
            flash('Enter a valid name, email, password (10–72 characters), role, and recruiter company when applicable.', 'error');
            redirect('dashboard');
        }
        if ($role === 'recruiter') {
            $companyCheck = $pdo->prepare('SELECT id FROM companies WHERE id = ?');
            $companyCheck->execute([$companyId]);
            if (!$companyCheck->fetch()) {
                flash('The selected recruiter company does not exist.', 'error');
                redirect('dashboard');
            }
        } else {
            $companyId = null;
        }
        try {
            $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, company_id) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $companyId]);
        } catch (PDOException $exception) {
            error_log('Account creation failed: ' . $exception->getMessage());
            flash('The account could not be created. The email may already be registered.', 'error');
            redirect('dashboard');
        }
        flash(ucfirst($role) . ' account created. Share its temporary password with the user securely.');
        redirect('dashboard');
    }

    if ($action === 'save_profile') {
        $user = require_role(['student']);
        $rollNumber = posted_string('roll_number', 40);
        $department = posted_string('department', 100);
        $graduationYear = filter_var(request_string($_POST, 'graduation_year'), FILTER_VALIDATE_INT);
        $cgpa = filter_var(request_string($_POST, 'cgpa'), FILTER_VALIDATE_FLOAT);
        $phone = trim(request_string($_POST, 'phone'));
        $skills = trim(request_string($_POST, 'skills'));
        if (!$rollNumber || !$department || $graduationYear === false || $graduationYear < 2000 || $graduationYear > 2100
            || $cgpa === false || $cgpa < 0 || $cgpa > 10 || strlen($phone) > 30 || strlen($skills) > 500) {
            flash('Please provide a valid roll number, department, graduation year, CGPA (0–10), phone, and skills.', 'error');
            redirect('profile');
        }
        try {
            $statement = $pdo->prepare(
                'INSERT INTO students (user_id, roll_number, department, graduation_year, cgpa, phone, skills)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE roll_number = VALUES(roll_number), department = VALUES(department),
                   graduation_year = VALUES(graduation_year), cgpa = VALUES(cgpa), phone = VALUES(phone), skills = VALUES(skills)'
            );
            $statement->execute([(int) $user['id'], $rollNumber, $department, $graduationYear, $cgpa, $phone ?: null, $skills ?: null]);
        } catch (PDOException $exception) {
            error_log('Student profile save failed: ' . $exception->getMessage());
            flash('The profile could not be saved. Check that the roll number is not already in use.', 'error');
            redirect('profile');
        }
        flash('Student profile saved.');
        redirect('profile');
    }

    if ($action === 'create_job') {
        $user = require_role(['admin', 'recruiter']);
        $title = posted_string('title', 150);
        $description = posted_string('description', 10000);
        $location = posted_string('location', 120);
        $minCgpa = filter_var(request_string($_POST, 'min_cgpa'), FILTER_VALIDATE_FLOAT);
        $departmentInput = posted_string('eligible_departments', 255);
        $graduationInput = trim(request_string($_POST, 'graduation_year'));
        $graduationYear = $graduationInput === '' ? null : filter_var($graduationInput, FILTER_VALIDATE_INT);
        if (!$title || !$description || !$location || $minCgpa === false || $minCgpa < 0 || $minCgpa > 10
            || !$departmentInput || ($graduationInput !== '' && ($graduationYear === false || $graduationYear < 2000 || $graduationYear > 2100))) {
            flash('Please complete each job field with valid eligibility criteria.', 'error');
            redirect('jobs');
        }
        $departments = array_values(array_filter(array_map('trim', explode(',', $departmentInput)), static fn(string $value): bool => $value !== ''));
        if (!$departments || strlen(implode(',', $departments)) > 255) {
            flash('Enter one or more eligible departments, separated by commas, or use * for all departments.', 'error');
            redirect('jobs');
        }
        $departmentsValue = in_array('*', $departments, true) ? '*' : implode(',', $departments);
        $companyId = $user['role'] === 'recruiter' ? (int) ($user['company_id'] ?? 0) : filter_var(request_string($_POST, 'company_id'), FILTER_VALIDATE_INT);
        if (!$companyId || $companyId === false) {
            flash('Choose a valid company for this posting.', 'error');
            redirect('jobs');
        }
        $companyCheck = $pdo->prepare('SELECT id FROM companies WHERE id = ?');
        $companyCheck->execute([$companyId]);
        if (!$companyCheck->fetch()) {
            flash('The selected company does not exist.', 'error');
            redirect('jobs');
        }
        $statement = $pdo->prepare(
            'INSERT INTO jobs (company_id, posted_by, title, description, location, min_cgpa, eligible_departments, graduation_year)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$companyId, (int) $user['id'], $title, $description, $location, $minCgpa, $departmentsValue, $graduationYear]);
        flash('Job posting created.');
        redirect('jobs');
    }

    if ($action === 'toggle_job') {
        $user = require_role(['admin', 'recruiter']);
        $jobId = filter_var(request_string($_POST, 'job_id'), FILTER_VALIDATE_INT);
        $newStatus = request_string($_POST, 'new_status');
        if (!$jobId || !in_array($newStatus, ['open', 'closed'], true)) {
            flash('Invalid job posting.', 'error');
            redirect('jobs');
        }
        $statement = $pdo->prepare("UPDATE jobs SET status = ? WHERE id = ? AND (? = 'admin' OR company_id = ?)");
        $statement->execute([$newStatus, $jobId, $user['role'], (int) ($user['company_id'] ?? 0)]);
        flash($statement->rowCount() ? 'Job posting updated.' : 'The job was not found or is outside your company.', $statement->rowCount() ? 'success' : 'error');
        redirect('jobs');
    }

    if ($action === 'apply') {
        $user = require_role(['student']);
        $profile = student_profile($pdo, (int) $user['id']);
        $jobId = filter_var(request_string($_POST, 'job_id'), FILTER_VALIDATE_INT);
        if (!$profile || !$jobId) {
            flash('Complete your student profile before applying.', 'error');
            redirect('profile');
        }
        $statement = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND status = 'open'");
        $statement->execute([$jobId]);
        $job = $statement->fetch();
        if (!$job || !job_is_eligible($profile, $job)) {
            flash('This job is unavailable or your profile does not meet its eligibility criteria.', 'error');
            redirect('jobs');
        }
        $existing = $pdo->prepare('SELECT id FROM applications WHERE job_id = ? AND student_id = ?');
        $existing->execute([$jobId, $profile['id']]);
        if ($existing->fetch()) {
            flash('You have already applied for this role.', 'error');
            redirect('applications');
        }
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO applications (job_id, student_id) VALUES (?, ?)');
            $insert->execute([$jobId, $profile['id']]);
            $applicationId = (int) $pdo->lastInsertId();
            $event = $pdo->prepare("INSERT INTO application_events (application_id, changed_by, old_status, new_status, note) VALUES (?, ?, NULL, 'applied', NULL)");
            $event->execute([$applicationId, (int) $user['id']]);
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Application submission failed: ' . $exception->getMessage());
            flash('Your application could not be submitted. It may already exist; refresh your applications list.', 'error');
            redirect('jobs');
        }
        flash('Application submitted successfully.');
        redirect('applications');
    }

    if ($action === 'update_application') {
        $user = require_role(['admin', 'recruiter']);
        $applicationId = filter_var(request_string($_POST, 'application_id'), FILTER_VALIDATE_INT);
        $newStatus = request_string($_POST, 'status');
        $notes = trim(request_string($_POST, 'notes'));
        $validStatuses = ['applied', 'shortlisted', 'interviewed', 'offered', 'rejected'];
        if (!$applicationId || !in_array($newStatus, $validStatuses, true) || strlen($notes) > 1000) {
            flash('Invalid application update.', 'error');
            redirect('applications');
        }
        $statement = $pdo->prepare(
            'SELECT a.id, a.status, j.company_id FROM applications a JOIN jobs j ON j.id = a.job_id WHERE a.id = ?'
        );
        $statement->execute([$applicationId]);
        $application = $statement->fetch();
        if (!$application || ($user['role'] === 'recruiter' && (int) $application['company_id'] !== (int) $user['company_id'])) {
            http_response_code(403);
            exit('You cannot update this application.');
        }
        $allowedTransitions = [
            'applied' => ['shortlisted', 'rejected'],
            'shortlisted' => ['interviewed', 'rejected'],
            'interviewed' => ['offered', 'rejected'],
            'offered' => [],
            'rejected' => [],
        ];
        if ($newStatus !== $application['status'] && !in_array($newStatus, $allowedTransitions[$application['status']], true)) {
            flash('Move candidates through applied → shortlisted → interviewed → offered, or reject them at any stage.', 'error');
            redirect('applications');
        }
        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE applications SET status = ?, recruiter_notes = ? WHERE id = ?');
            $update->execute([$newStatus, $notes ?: null, $applicationId]);
            if ($newStatus !== $application['status']) {
                $event = $pdo->prepare('INSERT INTO application_events (application_id, changed_by, old_status, new_status, note) VALUES (?, ?, ?, ?, ?)');
                $event->execute([$applicationId, (int) $user['id'], $application['status'], $newStatus, $notes ?: null]);
            }
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Application status update failed: ' . $exception->getMessage());
            flash('The application update failed. Please retry.', 'error');
            redirect('applications');
        }
        flash('Application status and notes saved.');
        redirect('applications');
    }

    http_response_code(400);
    exit('Unknown form action.');
}

$page = request_string($_GET, 'page') ?: ($currentUser ? 'dashboard' : 'login');
$validPages = ['login', 'dashboard', 'jobs', 'applications', 'profile'];
if (!in_array($page, $validPages, true)) {
    http_response_code(404);
    $page = 'dashboard';
}
if ($page !== 'login' && !$currentUser) {
    $page = 'login';
}
if ($currentUser && $page === 'login') {
    $page = 'dashboard';
}
if ($currentUser && $page === 'profile' && $currentUser['role'] !== 'student') {
    http_response_code(403);
    exit('Only student accounts have a placement profile.');
}

$profile = $currentUser && $currentUser['role'] === 'student' && $pdo
    ? student_profile($pdo, (int) $currentUser['id'])
    : null;
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page === 'login' ? 'Sign in' : ucfirst($page)) ?> · CampusPath</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if ($currentUser): ?>
    <aside class="sidebar">
        <a class="brand" href="index.php"><span class="brand-mark">C</span><span>CampusPath<small>Placement portal</small></span></a>
        <p class="nav-label">WORKSPACE</p>
        <nav>
            <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="index.php?page=dashboard"><span>▦</span> Dashboard</a>
            <a class="<?= $page === 'jobs' ? 'active' : '' ?>" href="index.php?page=jobs"><span>▤</span> Job postings</a>
            <a class="<?= $page === 'applications' ? 'active' : '' ?>" href="index.php?page=applications"><span>◷</span> Applications</a>
            <?php if ($currentUser['role'] === 'student'): ?>
                <a class="<?= $page === 'profile' ? 'active' : '' ?>" href="index.php?page=profile"><span>◉</span> My profile</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-bottom">
            <div class="user-chip"><span class="avatar"><?= e(strtoupper(substr($currentUser['name'], 0, 1))) ?></span><span><?= e($currentUser['name']) ?><small><?= e(ucfirst($currentUser['role'])) ?></small></span></div>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="logout-button" type="submit">Sign out <span>↗</span></button></form>
        </div>
    </aside>
<?php endif; ?>
<main class="<?= $currentUser ? 'main-content' : 'login-main' ?>">
    <?php if ($databaseError): ?><div class="alert error"><?= e($databaseError) ?></div><?php endif; ?>
    <?php if ($flashMessage): ?><div class="alert <?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div><?php endif; ?>

    <?php if ($page === 'login'): ?>
        <section class="login-layout">
            <div class="login-intro"><div class="brand light"><span class="brand-mark">C</span><span>CampusPath<small>Placement portal</small></span></div><p class="eyebrow">YOUR NEXT CHAPTER STARTS HERE</p><h1>Connecting campus talent with opportunity.</h1><p>One place to manage student profiles, employer roles, applications, and placement outcomes.</p><div class="login-illustration"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="illustration-card"><span>Placement cycle</span><strong>Ready for what's next?</strong><div class="progress"><i></i></div><small>Discover · Apply · Succeed</small></div></div></div>
            <div class="login-panel"><div class="mobile-brand brand"><span class="brand-mark">C</span><span>CampusPath<small>Placement portal</small></span></div><p class="eyebrow">WELCOME BACK</p><h2>Sign in to your account</h2><p class="muted">Use your campus or recruiter account to continue.</p>
                <form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="login">
                    <label>Email address<input type="email" name="email" autocomplete="username" required maxlength="190" placeholder="you@example.com"></label>
                    <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
                    <button class="full-button" type="submit">Sign in <span>→</span></button>
                </form>
                <div class="demo-hint"><strong>Demo accounts</strong><span>Officer: officer@example.com</span><span>Student: aarav@example.com</span><span>Recruiter: recruiter@example.com</span><small>Password for all: <code>password</code></small></div>
            </div>
        </section>
    <?php elseif (!$pdo): ?>
        <section class="page-heading"><p class="eyebrow">CAMPUS PLACEMENTS</p><h1>Placement workspace</h1></section>
    <?php elseif ($page === 'dashboard'): ?>
        <?php
        $role = $currentUser['role'];
        $applicationRows = [];
        if ($role === 'admin') {
            $metrics = [
                'Students' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
                'Open roles' => (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'open'")->fetchColumn(),
                'Applications' => (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn(),
                'Offers' => (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'offered'")->fetchColumn(),
            ];
            $statusRows = $pdo->query("SELECT status, COUNT(*) AS total FROM applications GROUP BY status ORDER BY FIELD(status, 'applied', 'shortlisted', 'interviewed', 'offered', 'rejected')")->fetchAll();
            $departmentRows = $pdo->query(
                "SELECT s.department, COUNT(a.id) AS applications,
                    SUM(CASE WHEN a.status = 'offered' THEN 1 ELSE 0 END) AS offers
                 FROM students s LEFT JOIN applications a ON a.student_id = s.id
                 GROUP BY s.department ORDER BY applications DESC, s.department"
            )->fetchAll();
            $statement = $pdo->query('SELECT a.id, a.status, a.recruiter_notes, a.applied_at, u.name AS student_name, s.roll_number, s.department, s.cgpa, j.title, c.name AS company_name FROM applications a JOIN students s ON s.id = a.student_id JOIN users u ON u.id = s.user_id JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id ORDER BY a.updated_at DESC LIMIT 6');
            $applicationRows = $statement->fetchAll();
            $roleLabel = 'Placement officer';
        } elseif ($role === 'recruiter') {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE company_id = ? AND status = 'open'");
            $statement->execute([(int) $currentUser['company_id']]);
            $metrics = ['Open roles' => (int) $statement->fetchColumn()];
            $statement = $pdo->prepare("SELECT status, COUNT(*) AS total FROM applications a JOIN jobs j ON j.id = a.job_id WHERE j.company_id = ? GROUP BY status ORDER BY FIELD(status, 'applied', 'shortlisted', 'interviewed', 'offered', 'rejected')");
            $statement->execute([(int) $currentUser['company_id']]);
            $statusRows = $statement->fetchAll();
            $statusCounts = array_column($statusRows, 'total', 'status');
            $metrics['Applications'] = array_sum(array_map('intval', $statusCounts));
            $metrics['Interviews'] = (int) ($statusCounts['interviewed'] ?? 0);
            $metrics['Offers'] = (int) ($statusCounts['offered'] ?? 0);
            $statement = $pdo->prepare('SELECT a.id, a.status, a.recruiter_notes, a.applied_at, u.name AS student_name, s.roll_number, s.department, s.cgpa, j.title, c.name AS company_name FROM applications a JOIN students s ON s.id = a.student_id JOIN users u ON u.id = s.user_id JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id WHERE j.company_id = ? ORDER BY a.updated_at DESC LIMIT 6');
            $statement->execute([(int) $currentUser['company_id']]);
            $applicationRows = $statement->fetchAll();
            $roleLabel = 'Recruiter';
        } else {
            $metrics = [
                'Eligible open roles' => 0,
                'Applications' => 0,
                'Interviews' => 0,
                'Offers' => 0,
            ];
            $openJobs = $pdo->query("SELECT j.*, c.name AS company_name FROM jobs j JOIN companies c ON c.id = j.company_id WHERE j.status = 'open' ORDER BY j.created_at DESC")->fetchAll();
            foreach ($openJobs as $job) {
                if ($profile && job_is_eligible($profile, $job)) {
                    $metrics['Eligible open roles']++;
                }
            }
            $statement = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE student_id = ?');
            $statement->execute([(int) ($profile['id'] ?? 0)]);
            $metrics['Applications'] = (int) $statement->fetchColumn();
            $statement = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE student_id = ? AND status = 'interviewed'");
            $statement->execute([(int) ($profile['id'] ?? 0)]);
            $metrics['Interviews'] = (int) $statement->fetchColumn();
            $statement = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE student_id = ? AND status = 'offered'");
            $statement->execute([(int) ($profile['id'] ?? 0)]);
            $metrics['Offers'] = (int) $statement->fetchColumn();
            $statement = $pdo->prepare('SELECT a.id, a.status, a.recruiter_notes, a.applied_at, u.name AS student_name, s.roll_number, s.department, s.cgpa, j.title, c.name AS company_name FROM applications a JOIN students s ON s.id = a.student_id JOIN users u ON u.id = s.user_id JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id WHERE a.student_id = ? ORDER BY a.updated_at DESC LIMIT 5');
            $statement->execute([(int) ($profile['id'] ?? 0)]);
            $applicationRows = $statement->fetchAll();
            $roleLabel = 'Student';
        }
        ?>
        <header class="topbar"><div><p class="eyebrow"><?= e(strtoupper($roleLabel)) ?> WORKSPACE</p><h1>Good day, <?= e(explode(' ', $currentUser['name'])[0]) ?> <span class="wave">✦</span></h1><p class="muted"><?= e(date('l, F j, Y')) ?> <span class="dot">·</span> Here's your placement snapshot.</p></div><a class="button-link" href="index.php?page=jobs">Explore opportunities <span>→</span></a></header>
        <?php if ($role === 'student' && !$profile): ?><div class="notice"><strong>Complete your profile to get started.</strong> Add your department, CGPA, and graduation year to check placement eligibility. <a href="index.php?page=profile">Complete profile →</a></div><?php endif; ?>
        <section class="metric-grid <?= count($metrics) === 1 ? 'single' : '' ?>"><?php foreach ($metrics as $label => $value): ?><article class="metric-card"><span><?= e($label) ?></span><strong><?= e($value) ?></strong><small><?= $label === 'Offers' ? 'Successful outcomes' : 'Across the placement cycle' ?></small></article><?php endforeach; ?></section>
        <?php if ($role === 'admin' || $role === 'recruiter'): ?>
            <section class="panel"><div class="section-heading"><div><p class="eyebrow">PIPELINE</p><h2>Application progress</h2></div><a class="text-link" href="index.php?page=applications">View all →</a></div><div class="pipeline"><?php foreach (['applied', 'shortlisted', 'interviewed', 'offered', 'rejected'] as $pipelineStatus): $count = 0; foreach ($statusRows as $statusRow) { if ($statusRow['status'] === $pipelineStatus) { $count = (int) $statusRow['total']; break; } } ?><div><span class="status status-<?= e($pipelineStatus) ?>"><?= e(ucfirst($pipelineStatus)) ?></span><strong><?= e($count) ?></strong></div><?php endforeach; ?></div></section>
        <?php endif; ?>
        <?php if ($role === 'admin'): ?><section class="panel"><div class="section-heading"><div><p class="eyebrow">PLACEMENT REPORT</p><h2>Outcomes by department</h2></div></div><?php if ($departmentRows): ?><div class="table-wrap"><table><thead><tr><th>Department</th><th>Applications</th><th>Offers</th></tr></thead><tbody><?php foreach ($departmentRows as $row): ?><tr><td><?= e($row['department']) ?></td><td><?= e($row['applications']) ?></td><td><?= e($row['offers']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state">Student department data will appear here as profiles are added.</div><?php endif; ?></section><?php endif; ?>
        <?php if ($role === 'admin'): $accountCompanies = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(); ?>
            <section class="panel form-panel"><div class="section-heading"><div><p class="eyebrow">ACCESS MANAGEMENT</p><h2>Create a student or recruiter account</h2></div></div>
                <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create_user">
                    <label>Full name<input name="name" maxlength="120" required></label><label>Email<input type="email" name="email" maxlength="190" required></label>
                    <label>Temporary password<input type="password" name="password" minlength="10" maxlength="72" required><small>At least 10 characters; share it with the user securely.</small></label>
                    <label>Role<select name="role" required><option value="student">Student</option><option value="recruiter">Recruiter</option></select></label>
                    <label class="span-all">Recruiter company <span class="muted">(select only for recruiter accounts)</span><select name="company_id"><option value="">Not applicable</option><?php foreach ($accountCompanies as $company): ?><option value="<?= (int) $company['id'] ?>"><?= e($company['name']) ?></option><?php endforeach; ?></select></label>
                    <div class="span-all"><button type="submit">Create account</button></div>
                </form>
            </section>
        <?php endif; ?>
        <section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= $role === 'student' ? 'MY ACTIVITY' : 'LATEST ACTIVITY' ?></p><h2><?= $role === 'student' ? 'Application updates' : 'Recent applications' ?></h2></div><a class="text-link" href="index.php?page=applications">See all →</a></div><?php render_application_table($applicationRows, $role !== 'student'); ?></section>
    <?php elseif ($page === 'jobs'): ?>
        <?php
        if ($currentUser['role'] === 'student') {
            $jobs = $pdo->query("SELECT j.*, c.name AS company_name FROM jobs j JOIN companies c ON c.id = j.company_id WHERE j.status = 'open' ORDER BY j.created_at DESC")->fetchAll();
            $statement = $pdo->prepare('SELECT job_id FROM applications WHERE student_id = ?');
            $statement->execute([(int) ($profile['id'] ?? 0)]);
            $appliedJobIds = array_map('intval', array_column($statement->fetchAll(), 'job_id'));
            $companies = [];
        } elseif ($currentUser['role'] === 'recruiter') {
            $statement = $pdo->prepare('SELECT j.*, c.name AS company_name FROM jobs j JOIN companies c ON c.id = j.company_id WHERE j.company_id = ? ORDER BY j.created_at DESC');
            $statement->execute([(int) $currentUser['company_id']]);
            $jobs = $statement->fetchAll();
            $appliedJobIds = [];
            $companies = [];
        } else {
            $jobs = $pdo->query('SELECT j.*, c.name AS company_name FROM jobs j JOIN companies c ON c.id = j.company_id ORDER BY j.created_at DESC')->fetchAll();
            $appliedJobIds = [];
            $companies = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll();
        }
        ?>
        <header class="topbar"><div><p class="eyebrow">OPPORTUNITIES</p><h1>Job postings</h1><p class="muted">Explore roles and manage employer opportunities.</p></div></header>
        <?php if ($currentUser['role'] === 'student' && !$profile): ?><div class="notice"><strong>Complete your profile before applying.</strong> Add your department, CGPA, and graduation year to see which roles fit. <a href="index.php?page=profile">Complete profile →</a></div><?php endif; ?>
        <?php if (in_array($currentUser['role'], ['admin', 'recruiter'], true)): ?>
            <section class="panel form-panel"><div class="section-heading"><div><p class="eyebrow">NEW OPPORTUNITY</p><h2>Post a role</h2></div></div><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create_job">
                <?php if ($currentUser['role'] === 'admin'): ?><label>Company<select name="company_id" required><option value="">Select a company</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>"><?= e($company['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <label>Job title<input name="title" required maxlength="150" placeholder="e.g. Graduate Software Engineer"></label><label>Location<input name="location" required maxlength="120" placeholder="City / Hybrid"></label>
                <label>Minimum CGPA<input type="number" name="min_cgpa" required min="0" max="10" step="0.01" value="0"></label><label>Eligible departments<input name="eligible_departments" required maxlength="255" value="*" placeholder="Computer Science, Information Technology"></label>
                <label>Graduation year <span class="muted">(optional)</span><input type="number" name="graduation_year" min="2000" max="2100" placeholder="Any year"></label>
                <label class="span-all">Role description<textarea name="description" rows="3" required maxlength="10000" placeholder="Responsibilities, skills, and selection process"></textarea></label>
                <div class="span-all"><button type="submit">Publish job <span>→</span></button></div>
            </form></section>
        <?php endif; ?>
        <section class="job-list"><?php render_job_cards($jobs, $currentUser['role'] === 'student' ? $profile : null, $appliedJobIds); ?></section>
    <?php elseif ($page === 'applications'): ?>
        <?php
        if ($currentUser['role'] === 'student') {
            $statement = $pdo->prepare('SELECT a.id, a.status, a.recruiter_notes, a.applied_at, u.name AS student_name, s.roll_number, s.department, s.cgpa, j.title, c.name AS company_name FROM applications a JOIN students s ON s.id = a.student_id JOIN users u ON u.id = s.user_id JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id WHERE a.student_id = ? ORDER BY a.updated_at DESC');
            $statement->execute([(int) ($profile['id'] ?? 0)]);
        } elseif ($currentUser['role'] === 'recruiter') {
            $statement = $pdo->prepare('SELECT a.id, a.status, a.recruiter_notes, a.applied_at, u.name AS student_name, s.roll_number, s.department, s.cgpa, j.title, c.name AS company_name FROM applications a JOIN students s ON s.id = a.student_id JOIN users u ON u.id = s.user_id JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id WHERE j.company_id = ? ORDER BY a.updated_at DESC');
            $statement->execute([(int) $currentUser['company_id']]);
        } else {
            $statement = $pdo->query('SELECT a.id, a.status, a.recruiter_notes, a.applied_at, u.name AS student_name, s.roll_number, s.department, s.cgpa, j.title, c.name AS company_name FROM applications a JOIN students s ON s.id = a.student_id JOIN users u ON u.id = s.user_id JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id ORDER BY a.updated_at DESC');
        }
        $applications = $statement->fetchAll();
        ?>
        <header class="topbar"><div><p class="eyebrow">SELECTION PIPELINE</p><h1>Applications</h1><p class="muted"><?= $currentUser['role'] === 'student' ? 'Track each application and recruiter update.' : 'Review candidates and record interviews, decisions, and offers.' ?></p></div></header>
        <section class="panel"><?php render_application_table($applications, $currentUser['role'] !== 'student'); ?></section>
    <?php elseif ($page === 'profile'): ?>
        <header class="topbar"><div><p class="eyebrow">STUDENT DETAILS</p><h1>My profile</h1><p class="muted">Your profile is used to assess job eligibility.</p></div></header>
        <section class="panel profile-panel"><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile">
            <label>Full name<input value="<?= e($currentUser['name']) ?>" disabled></label><label>Campus email<input value="<?= e($currentUser['email']) ?>" disabled></label>
            <label>Roll number<input name="roll_number" maxlength="40" required value="<?= e($profile['roll_number'] ?? '') ?>" placeholder="CSE2026-014"></label><label>Department<input name="department" maxlength="100" required value="<?= e($profile['department'] ?? '') ?>" placeholder="Computer Science"></label>
            <label>Graduation year<input type="number" name="graduation_year" min="2000" max="2100" required value="<?= e($profile['graduation_year'] ?? '') ?>"></label><label>CGPA (out of 10)<input type="number" name="cgpa" min="0" max="10" step="0.01" required value="<?= e($profile['cgpa'] ?? '') ?>"></label>
            <label>Phone<input type="tel" name="phone" maxlength="30" value="<?= e($profile['phone'] ?? '') ?>" placeholder="Optional"></label><label class="span-all">Skills<input name="skills" maxlength="500" value="<?= e($profile['skills'] ?? '') ?>" placeholder="PHP, SQL, Java, communication"></label>
            <div class="span-all"><button type="submit">Save profile <span>→</span></button></div>
        </form></section>
    <?php endif; ?>
    <footer class="footer">CampusPath <span>·</span> College placement management</footer>
</main>
</body>
</html>

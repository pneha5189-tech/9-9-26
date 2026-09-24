<?php
declare(strict_types=1);

session_start();

$database = __DIR__ . DIRECTORY_SEPARATOR . 'placement.sqlite';
$pdo = new PDO('sqlite:' . $database);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ('admin', 'student'))
    );
    CREATE TABLE IF NOT EXISTS students (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        roll_number TEXT NOT NULL UNIQUE,
        course TEXT NOT NULL,
        graduation_year INTEGER NOT NULL,
        phone TEXT DEFAULT '',
        cgpa REAL NOT NULL DEFAULT 0,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS companies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        industry TEXT NOT NULL,
        location TEXT NOT NULL,
        website TEXT DEFAULT '',
        contact_email TEXT DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS drives (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_id INTEGER NOT NULL,
        job_title TEXT NOT NULL,
        package_lpa REAL NOT NULL,
        eligibility_cgpa REAL NOT NULL DEFAULT 0,
        drive_date TEXT NOT NULL,
        description TEXT DEFAULT '',
        status TEXT NOT NULL DEFAULT 'Open' CHECK(status IN ('Open', 'Closed')),
        FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS applications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        drive_id INTEGER NOT NULL,
        student_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'Applied' CHECK(status IN ('Applied', 'Shortlisted', 'Selected', 'Rejected')),
        applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(drive_id, student_id),
        FOREIGN KEY(drive_id) REFERENCES drives(id) ON DELETE CASCADE,
        FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
    )"
);

function seed(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
        return;
    }

    $user = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
    $user->execute(['Placement Administrator', 'admin@college.test', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
    $user->execute(['Demo Student', 'student@college.test', password_hash('student123', PASSWORD_DEFAULT), 'student']);
    $studentUserId = (int) $pdo->lastInsertId();
    $student = $pdo->prepare('INSERT INTO students (user_id, roll_number, course, graduation_year, phone, cgpa) VALUES (?, ?, ?, ?, ?, ?)');
    $student->execute([$studentUserId, 'CS2025001', 'B.Tech Computer Science', 2026, '9876543210', 8.4]);

    $company = $pdo->prepare('INSERT INTO companies (name, industry, location, website, contact_email) VALUES (?, ?, ?, ?, ?)');
    $company->execute(['TechNova Solutions', 'Information Technology', 'Bengaluru', 'https://example.com', 'careers@example.com']);
    $companyId = (int) $pdo->lastInsertId();
    $drive = $pdo->prepare('INSERT INTO drives (company_id, job_title, package_lpa, eligibility_cgpa, drive_date, description) VALUES (?, ?, ?, ?, ?, ?)');
    $drive->execute([$companyId, 'Software Engineer', 8.5, 7.0, date('Y-m-d', strtotime('+14 days')), 'Build and maintain scalable web applications.']);
}
seed($pdo);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $page = 'dashboard'): never
{
    header('Location: ?page=' . urlencode($page));
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function requireLogin(): void
{
    if (!isset($_SESSION['user'])) {
        redirect('login');
    }
}

function requireAdmin(): void
{
    requireLogin();
    if ($_SESSION['user']['role'] !== 'admin') {
        flash('Administrator access is required.', 'error');
        redirect();
    }
}

function verifyCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid request token.');
    }
}

$page = $_GET['page'] ?? (isset($_SESSION['user']) ? 'dashboard' : 'login');
$action = $_POST['action'] ?? '';

if ($action === 'login') {
    verifyCsrf();
    $statement = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $statement->execute([trim((string) ($_POST['email'] ?? ''))]);
    $user = $statement->fetch();
    if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password'])) {
        unset($user['password']);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        redirect();
    }
    flash('Invalid email or password.', 'error');
    redirect('login');
}

if ($action === 'register') {
    verifyCsrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $rollNumber = trim((string) ($_POST['roll_number'] ?? ''));
    $course = trim((string) ($_POST['course'] ?? ''));
    $graduationYear = (int) ($_POST['graduation_year'] ?? 0);
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $cgpa = (float) ($_POST['cgpa'] ?? 0);

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6 ||
        $rollNumber === '' || $course === '' || $graduationYear < 2000 || $cgpa < 0 || $cgpa > 10) {
        flash('Enter valid details. Password must be at least 6 characters and CGPA must be between 0 and 10.', 'error');
        redirect('register');
    }

    try {
        $pdo->beginTransaction();
        $userStatement = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, "student")');
        $userStatement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();
        $studentStatement = $pdo->prepare('INSERT INTO students (user_id, roll_number, course, graduation_year, phone, cgpa) VALUES (?, ?, ?, ?, ?, ?)');
        $studentStatement->execute([$userId, $rollNumber, $course, $graduationYear, $phone, $cgpa]);
        $pdo->commit();
        flash('Registration successful. You can now sign in.');
        redirect('login');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception->getCode() === '23000') {
            flash('The email or roll number is already registered.', 'error');
            redirect('register');
        }
        throw $exception;
    }
}

if ($action === 'logout') {
    verifyCsrf();
    session_destroy();
    redirect('login');
}

if ($action === 'add_company') {
    requireAdmin();
    verifyCsrf();
    $statement = $pdo->prepare('INSERT INTO companies (name, industry, location, website, contact_email) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([trim($_POST['name']), trim($_POST['industry']), trim($_POST['location']), trim($_POST['website']), trim($_POST['contact_email'])]);
    flash('Company added successfully.');
    redirect('companies');
}

if ($action === 'add_drive') {
    requireAdmin();
    verifyCsrf();
    $statement = $pdo->prepare('INSERT INTO drives (company_id, job_title, package_lpa, eligibility_cgpa, drive_date, description) VALUES (?, ?, ?, ?, ?, ?)');
    $statement->execute([(int) $_POST['company_id'], trim($_POST['job_title']), (float) $_POST['package_lpa'], (float) $_POST['eligibility_cgpa'], $_POST['drive_date'], trim($_POST['description'])]);
    flash('Placement drive published.');
    redirect('drives');
}

if ($action === 'apply') {
    requireLogin();
    if ($_SESSION['user']['role'] !== 'student') {
        flash('Only students can apply for drives.', 'error');
        redirect('drives');
    }
    verifyCsrf();
    $student = $pdo->prepare('SELECT id FROM students WHERE user_id = ?');
    $student->execute([$_SESSION['user']['id']]);
    $studentId = $student->fetchColumn();
    $drive = $pdo->prepare('SELECT id FROM drives WHERE id = ? AND status = "Open"');
    $drive->execute([(int) $_POST['drive_id']]);
    if (!$studentId || !$drive->fetchColumn()) {
        flash('This drive is not available.', 'error');
    } else {
        try {
            $statement = $pdo->prepare('INSERT INTO applications (drive_id, student_id) VALUES (?, ?)');
            $statement->execute([(int) $_POST['drive_id'], $studentId]);
            flash('Application submitted.');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                flash('You have already applied for this drive.', 'error');
            } else {
                throw $exception;
            }
        }
    }
    redirect('drives');
}

if ($action === 'update_application') {
    requireAdmin();
    verifyCsrf();
    $statement = $pdo->prepare('UPDATE applications SET status = ? WHERE id = ?');
    $statement->execute([$_POST['status'], (int) $_POST['application_id']]);
    flash('Application status updated.');
    redirect('applications');
}

if ($page !== 'login' && $page !== 'register') {
    requireLogin();
}
$user = $_SESSION['user'] ?? ['role' => null];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$stats = [
    'students' => (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'companies' => (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
    'drives' => (int) $pdo->query('SELECT COUNT(*) FROM drives WHERE status = "Open"')->fetchColumn(),
    'applications' => (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn(),
];

if ($user['role'] === 'student') {
    $studentQuery = $pdo->prepare('SELECT * FROM students WHERE user_id = ?');
    $studentQuery->execute([$user['id']]);
    $student = $studentQuery->fetch();
}

$companies = $pdo->query('SELECT * FROM companies ORDER BY name')->fetchAll();
$drives = $pdo->query('SELECT drives.*, companies.name AS company_name FROM drives JOIN companies ON companies.id = drives.company_id ORDER BY drive_date')->fetchAll();
$applications = $pdo->query('SELECT applications.*, drives.job_title, companies.name AS company_name, students.roll_number, users.name AS student_name FROM applications JOIN drives ON drives.id = applications.drive_id JOIN companies ON companies.id = drives.company_id JOIN students ON students.id = applications.student_id JOIN users ON users.id = students.user_id ORDER BY applications.applied_at DESC')->fetchAll();
$myApplications = [];
if ($user['role'] === 'student') {
    $statement = $pdo->prepare('SELECT applications.*, drives.job_title, companies.name AS company_name FROM applications JOIN drives ON drives.id = applications.drive_id JOIN companies ON companies.id = drives.company_id WHERE applications.student_id = ? ORDER BY applications.applied_at DESC');
    $statement->execute([$student['id']]);
    $myApplications = $statement->fetchAll();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(in_array($page, ['login', 'register'], true) ? ucfirst($page) : 'Placement Portal') ?></title>
    <style>
        :root { --navy:#172554; --blue:#2563eb; --light:#f8fafc; --muted:#64748b; --line:#e2e8f0; --green:#15803d; --red:#b91c1c; }
        * { box-sizing:border-box; } body { margin:0; font-family:Inter,Segoe UI,Arial,sans-serif; background:var(--light); color:#1e293b; }
        .login { min-height:100vh; display:grid; place-items:center; background:linear-gradient(135deg,#172554,#2563eb); }
        .login-card { width:min(430px,92vw); background:white; border-radius:18px; padding:34px; box-shadow:0 20px 50px #0f172a55; } h1 { margin:0 0 8px; color:var(--navy); } h2 { margin-top:0; } .muted { color:var(--muted); }
        .layout { min-height:100vh; display:flex; } aside { width:245px; background:var(--navy); color:white; padding:25px 16px; } aside h2 { padding:0 12px 20px; font-size:20px; } nav a { display:block; color:#cbd5e1; padding:12px; border-radius:8px; text-decoration:none; margin:4px 0; } nav a.active, nav a:hover { background:#ffffff1a; color:white; }
        main { flex:1; padding:30px; max-width:1400px; } header { display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; } header h1 { font-size:28px; } .user { color:var(--muted); } .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:16px; margin-bottom:25px; } .card { background:white; border:1px solid var(--line); border-radius:12px; padding:20px; } .metric { font-size:32px; font-weight:700; color:var(--blue); margin-top:8px; } .toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        table { width:100%; border-collapse:collapse; background:white; border:1px solid var(--line); border-radius:10px; overflow:hidden; } th,td { padding:13px 14px; text-align:left; border-bottom:1px solid var(--line); } th { background:#f1f5f9; font-size:13px; text-transform:uppercase; color:var(--muted); } tr:last-child td { border-bottom:0; }
        label { display:block; font-size:13px; font-weight:600; margin:13px 0 6px; } input,select,textarea { width:100%; padding:10px 11px; border:1px solid #cbd5e1; border-radius:7px; font:inherit; } textarea { min-height:90px; resize:vertical; } button,.button { border:0; border-radius:7px; padding:10px 15px; background:var(--blue); color:white; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; } button:hover,.button:hover { filter:brightness(.92); } .button.secondary { background:#e2e8f0; color:#1e293b; } .button.danger { background:var(--red); } .form-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:0 16px; } .full { grid-column:1/-1; } .notice { padding:12px 15px; border-radius:7px; margin-bottom:18px; background:#dcfce7; color:var(--green); } .notice.error { background:#fee2e2; color:var(--red); } .pill { display:inline-block; padding:4px 9px; border-radius:99px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:600; } .pill.Selected { background:#dcfce7; color:var(--green); } .pill.Rejected { background:#fee2e2; color:var(--red); } .empty { text-align:center; padding:35px; color:var(--muted); }
        @media(max-width:760px) { aside { width:70px; padding:15px 8px; } aside h2 { font-size:0; } aside h2:first-letter { font-size:22px; } nav a { font-size:0; text-align:center; } nav a::first-letter { font-size:20px; } main { padding:18px 12px; } .form-grid { grid-template-columns:1fr; } .full { grid-column:auto; } table { display:block; overflow-x:auto; white-space:nowrap; } }
    </style>
</head>
<body>
<?php if (in_array($page, ['login', 'register'], true)): ?>
    <div class="login"><div class="login-card">
        <h1>CampusPlacements</h1><p class="muted">College Placement Management System</p>
        <?php if ($flash): ?><div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($page === 'register'): ?>
        <h2>Student registration</h2>
        <form method="post">
            <input type="hidden" name="action" value="register"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <label>Full name</label><input name="name" required autofocus>
            <label>Email</label><input type="email" name="email" required>
            <label>Password</label><input type="password" name="password" minlength="6" required>
            <label>Roll number</label><input name="roll_number" required>
            <label>Course</label><input name="course" placeholder="B.Tech Computer Science" required>
            <label>Graduation year</label><input type="number" name="graduation_year" min="<?= date('Y') ?>" max="<?= date('Y') + 10 ?>" required>
            <label>Phone</label><input type="tel" name="phone">
            <label>CGPA</label><input type="number" name="cgpa" min="0" max="10" step="0.01" required>
            <button type="submit" style="width:100%;margin-top:20px">Create student account</button>
        </form>
        <p class="muted" style="text-align:center"><a href="?page=login">Already registered? Sign in</a></p>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <label>Email</label><input type="email" name="email" required autofocus>
            <label>Password</label><input type="password" name="password" required>
            <button type="submit" style="width:100%;margin-top:20px">Sign in</button>
        </form>
        <p class="muted" style="font-size:12px;margin-top:22px">Demo admin: admin@college.test / admin123<br>Demo student: student@college.test / student123</p>
        <p style="text-align:center"><a href="?page=register">New student? Register here</a></p>
        <?php endif; ?>
    </div></div>
<?php else: ?>
    <div class="layout">
        <aside><h2>CampusPlacements</h2><nav>
            <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard">⌂ Dashboard</a>
            <a class="<?= $page === 'drives' ? 'active' : '' ?>" href="?page=drives">▣ Placement Drives</a>
            <?php if ($user['role'] === 'admin'): ?>
                <a class="<?= $page === 'students' ? 'active' : '' ?>" href="?page=students">♙ Students</a>
                <a class="<?= $page === 'companies' ? 'active' : '' ?>" href="?page=companies">▤ Companies</a>
                <a class="<?= $page === 'applications' ? 'active' : '' ?>" href="?page=applications">✓ Applications</a>
            <?php else: ?><a class="<?= $page === 'my-applications' ? 'active' : '' ?>" href="?page=my-applications">✓ My Applications</a><?php endif; ?>
        </nav></aside>
        <main>
            <header><h1><?= e(ucwords(str_replace('-', ' ', $page))) ?></h1><div class="user"><?= e($user['name']) ?> · <?= e(ucfirst($user['role'])) ?> <form method="post" style="display:inline"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><button class="button secondary" type="submit">Log out</button></form></div></header>
            <?php if ($flash): ?><div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <div class="grid"><?php foreach ($stats as $label => $value): ?><div class="card"><span class="muted"><?= e(ucfirst($label)) ?></span><div class="metric"><?= $value ?></div></div><?php endforeach; ?></div>
                <div class="card"><h2>Welcome, <?= e($user['name']) ?></h2><p class="muted">Manage campus recruitment, company relationships, placement drives, and applications from one place.</p></div>
            <?php elseif ($page === 'students' && $user['role'] === 'admin'): ?>
                <?php $rows = $pdo->query('SELECT students.*, users.name, users.email FROM students JOIN users ON users.id = students.user_id ORDER BY students.roll_number')->fetchAll(); ?>
                <div class="card"><table><tr><th>Name</th><th>Roll number</th><th>Course</th><th>Graduation</th><th>CGPA</th><th>Phone</th></tr><?php foreach ($rows as $row): ?><tr><td><?= e($row['name']) ?><br><span class="muted"><?= e($row['email']) ?></span></td><td><?= e($row['roll_number']) ?></td><td><?= e($row['course']) ?></td><td><?= e((string)$row['graduation_year']) ?></td><td><?= e((string)$row['cgpa']) ?></td><td><?= e($row['phone']) ?></td></tr><?php endforeach; ?></table></div>
            <?php elseif ($page === 'companies' && $user['role'] === 'admin'): ?>
                <div class="card"><h2>Add company</h2><form method="post" class="form-grid"><input type="hidden" name="action" value="add_company"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><div><label>Name</label><input name="name" required></div><div><label>Industry</label><input name="industry" required></div><div><label>Location</label><input name="location" required></div><div><label>Contact email</label><input type="email" name="contact_email"></div><div class="full"><label>Website</label><input type="url" name="website"></div><div class="full"><button>Add company</button></div></form></div><br><div class="card"><table><tr><th>Company</th><th>Industry</th><th>Location</th><th>Contact</th></tr><?php foreach ($companies as $company): ?><tr><td><strong><?= e($company['name']) ?></strong><br><span class="muted"><?= e($company['website']) ?></span></td><td><?= e($company['industry']) ?></td><td><?= e($company['location']) ?></td><td><?= e($company['contact_email']) ?></td></tr><?php endforeach; ?></table></div>
            <?php elseif ($page === 'drives'): ?>
                <?php if ($user['role'] === 'admin'): ?><div class="card"><h2>Publish placement drive</h2><form method="post" class="form-grid"><input type="hidden" name="action" value="add_drive"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><div><label>Company</label><select name="company_id" required><?php foreach ($companies as $company): ?><option value="<?= $company['id'] ?>"><?= e($company['name']) ?></option><?php endforeach; ?></select></div><div><label>Job title</label><input name="job_title" required></div><div><label>Package (LPA)</label><input type="number" name="package_lpa" min="0" step=".1" required></div><div><label>Minimum CGPA</label><input type="number" name="eligibility_cgpa" min="0" max="10" step=".1" required></div><div><label>Drive date</label><input type="date" name="drive_date" min="<?= date('Y-m-d') ?>" required></div><div class="full"><label>Description</label><textarea name="description"></textarea></div><div class="full"><button>Publish drive</button></div></form></div><br><?php endif; ?>
                <div class="card"><table><tr><th>Role</th><th>Company</th><th>Package</th><th>Eligibility</th><th>Date</th><th>Status</th><?php if ($user['role'] === 'student'): ?><th></th><?php endif; ?></tr><?php foreach ($drives as $drive): ?><tr><td><strong><?= e($drive['job_title']) ?></strong><br><span class="muted"><?= e($drive['description']) ?></span></td><td><?= e($drive['company_name']) ?></td><td>₹<?= e(number_format((float)$drive['package_lpa'], 1)) ?> LPA</td><td>CGPA <?= e((string)$drive['eligibility_cgpa']) ?></td><td><?= e(date('d M Y', strtotime($drive['drive_date']))) ?></td><td><span class="pill"><?= e($drive['status']) ?></span></td><?php if ($user['role'] === 'student'): ?><td><?php if ($drive['status'] === 'Open' && $student['cgpa'] >= $drive['eligibility_cgpa']): ?><form method="post"><input type="hidden" name="action" value="apply"><input type="hidden" name="drive_id" value="<?= $drive['id'] ?>"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><button>Apply</button></form><?php else: ?><span class="muted">Not eligible</span><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></table></div>
            <?php elseif ($page === 'applications' && $user['role'] === 'admin'): ?>
                <div class="card"><table><tr><th>Student</th><th>Company / role</th><th>Applied</th><th>Status</th><th>Update</th></tr><?php foreach ($applications as $application): ?><tr><td><?= e($application['student_name']) ?><br><span class="muted"><?= e($application['roll_number']) ?></span></td><td><?= e($application['company_name']) ?><br><?= e($application['job_title']) ?></td><td><?= e(date('d M Y', strtotime($application['applied_at']))) ?></td><td><span class="pill <?= e($application['status']) ?>"><?= e($application['status']) ?></span></td><td><form method="post"><input type="hidden" name="action" value="update_application"><input type="hidden" name="application_id" value="<?= $application['id'] ?>"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><select name="status" onchange="this.form.submit()"><?php foreach (['Applied','Shortlisted','Selected','Rejected'] as $status): ?><option <?= $application['status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></form></td></tr><?php endforeach; ?></table></div>
            <?php elseif ($page === 'my-applications'): ?>
                <div class="card"><table><tr><th>Company</th><th>Role</th><th>Applied</th><th>Status</th></tr><?php foreach ($myApplications as $application): ?><tr><td><?= e($application['company_name']) ?></td><td><?= e($application['job_title']) ?></td><td><?= e(date('d M Y', strtotime($application['applied_at']))) ?></td><td><span class="pill <?= e($application['status']) ?>"><?= e($application['status']) ?></span></td></tr><?php endforeach; ?><?php if (!$myApplications): ?><tr><td colspan="4" class="empty">You have not applied to any drives yet.</td></tr><?php endif; ?></table></div>
            <?php endif; ?>
        </main>
    </div>
<?php endif; ?>
</body>
</html>

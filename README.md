# CampusPath College Placement Management System

The primary interface is a **React** single-page app, with a PHP/MySQL API and a standalone PHP interface. This is a student-level full-stack project; it can run locally without Composer.

It has separate placement-officer, student, and recruiter workspaces with eligibility-aware job applications and an auditable selection pipeline.

## Requirements

- PHP 8.1 or later with the PDO MySQL (`pdo_mysql`) extension
- MySQL 8.0 or later
- Node.js 20.19 or later and npm (to run the React frontend)
- A web browser

No Composer dependencies are required. The interface uses local system fonts and does not need external assets.

## Local setup

1. Start MySQL and load the schema and sample records from the repository root:

   ```sh
   mysql -u root -p < database/schema.sql
   ```

   You can also import `database/schema.sql` using a local tool such as phpMyAdmin. The script creates the `placement_system` database, tables, demo accounts, a student profile, two companies, and an open software-engineering role.

2. Configure database access. The defaults are `127.0.0.1`, database `placement_system`, user `root`, and an empty password. Override them with `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` environment variables when needed. For example, in PowerShell:

   ```powershell
   $env:DB_HOST = "127.0.0.1"
   $env:DB_NAME = "placement_system"
   $env:DB_USER = "root"
   $env:DB_PASSWORD = "your-local-password"
   ```

3. Start the PHP API from the repository root:

   ```sh
   php -S 127.0.0.1:8000
   ```

4. In a second terminal, start the React frontend:

   ```sh
   cd frontend
   npm install
   npm run dev
   ```

5. Open the Vite URL shown in the terminal (usually [http://localhost:5173](http://localhost:5173)). Vite proxies `/api.php` to the PHP server. Keep both development servers running. To build the React frontend for production, run `npm run build` in `frontend/`; deployment should serve the generated `frontend/dist` assets and route `/api.php` to PHP.

The standalone PHP interface is also available at [http://127.0.0.1:8000](http://127.0.0.1:8000).

### Demo accounts

All three seeded accounts use the password `password`. Change or remove these demo credentials before using the application with real data. The demo database includes Aarav's application at **Applied**, ready for the recruiter workflow below.

| Role | Email |
|---|---|
| Placement officer / admin | `officer@example.com` |
| Student | `aarav@example.com` |
| Company recruiter | `recruiter@example.com` |

## Real-life placement example

Northstar Software is visiting campus for graduate hiring. Its recruiter posts a **Graduate Software Engineer** opening for Computer Science and Information Technology students graduating in 2026 with at least a 7.0 CGPA. Aarav's seeded profile (Computer Science, 2026, 8.4 CGPA) meets those requirements, and his sample application appears as **Applied**. The recruiter opens Applications, shortlists Aarav, then marks him **Interviewed** and adds a note such as “Technical interview: November 12, 10:00 AM.” After the interview, the recruiter records **Offered** or **Rejected**. Aarav sees the updated status and note in his account, while the placement officer follows the pipeline and department offer report to coordinate the campus drive.

## Features

- **Placement officer:** overall student, open-role, application, and offer metrics; application pipeline; outcomes by department; view all applications; post roles for a company; open or close postings.
- **Student:** maintain placement profile and eligibility details; browse open roles; apply once to eligible roles; track status and recruiter notes.
- **Recruiter:** post and manage roles for their company; view only their company's candidates; shortlist, record interview and offer/rejection outcomes, and save notes.
- **Eligibility:** applications require a student profile, the minimum CGPA, an eligible department (`*` means any department), and—when specified—the exact graduation year.
- **Audit trail:** application status changes are stored with the actor, previous and new status, note, and timestamp in `application_events`.
- **Account provisioning:** placement officers can create student and company recruiter accounts. Student profiles are completed by the student after sign-in; recruiters are bound to one company.

## Security notes

- Database access uses PDO prepared statements.
- Passwords are checked with `password_verify`; the seed data contains bcrypt hashes, not plaintext passwords.
- Sign-in regenerates the session ID; session cookies are HTTP-only and use `SameSite=Lax`.
- Every state-changing form and API request, including sign-in and sign-out, includes a session CSRF token.
- Role checks and recruiter-company ownership checks protect mutations and application lists.
- User-controlled content is HTML-escaped, and profile, job, and application fields are validated server-side.
- The built-in PHP server is for local development only. Use HTTPS, replace the demo accounts, and configure an appropriately restricted database user before any shared deployment.
- The placement officer creates accounts and provides the initial password through a separate secure channel; password reset and email delivery are not included in this local student project.

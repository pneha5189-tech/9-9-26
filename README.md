# College Placement Management System

A self-contained PHP application for managing college recruitment activities. It uses SQLite and requires no framework or external database server.

## Run locally

1. Install PHP 8.1+ with the `pdo_sqlite` extension.
2. From this directory, run `php -S localhost:8000`.
3. Open <http://localhost:8000> in a browser.

The SQLite database is created automatically as `placement.sqlite` on first run.

### Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Administrator | `admin@college.test` | `admin123` |
| Student | `student@college.test` | `student123` |

Administrators can manage companies, publish drives, view students, and update application statuses. Students can self-register from the login page, view eligible drives, apply, and track their applications.
import { useCallback, useEffect, useMemo, useState } from 'react';

const navigation = [
  { id: 'dashboard', label: 'Dashboard', icon: '▦' },
  { id: 'jobs', label: 'Job postings', icon: '▤' },
  { id: 'applications', label: 'Applications', icon: '◷' },
];

const statusNames = {
  applied: 'Applied',
  shortlisted: 'Shortlisted',
  interviewed: 'Interviewed',
  offered: 'Offered',
  rejected: 'Rejected',
};

const statusOptions = {
  applied: ['applied', 'shortlisted', 'rejected'],
  shortlisted: ['shortlisted', 'interviewed', 'rejected'],
  interviewed: ['interviewed', 'offered', 'rejected'],
  offered: ['offered'],
  rejected: ['rejected'],
};

function eligible(student, job) {
  if (!student || Number(student.cgpa) < Number(job.min_cgpa)) return false;
  if (job.graduation_year && Number(student.graduation_year) !== Number(job.graduation_year)) return false;
  const departments = String(job.eligible_departments).split(',').map((item) => item.trim().toLowerCase());
  return departments.includes('*') || departments.includes(String(student.department).trim().toLowerCase());
}

async function request(url, options = {}) {
  const response = await fetch(url, {
    credentials: 'same-origin',
    ...options,
    headers: {
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
  });
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || 'The request could not be completed.');
  return result;
}

function Brand({ light = false }) {
  return (
    <a className={`brand${light ? ' brand-light' : ''}`} href="#" onClick={(event) => event.preventDefault()}>
      <span className="brand-mark">C</span>
      <span>CampusPath<small>Placement portal</small></span>
    </a>
  );
}

function Login({ onLogin, busy, error }) {
  const [email, setEmail] = useState('officer@example.com');
  const [password, setPassword] = useState('password');
  return (
    <main className="login-page">
      <section className="login-layout">
        <div className="login-intro">
          <Brand light />
          <p className="eyebrow">YOUR NEXT CHAPTER STARTS HERE</p>
          <h1>Connecting campus talent with opportunity.</h1>
          <p>One place to manage student profiles, employer roles, applications, and placement outcomes.</p>
          <div className="login-art" aria-hidden="true">
            <div className="orbit orbit-one" /><div className="orbit orbit-two" />
            <div className="art-card"><span>Placement cycle</span><strong>Ready for what's next?</strong><div className="progress"><i /></div><small>Discover · Apply · Succeed</small></div>
          </div>
        </div>
        <div className="login-panel">
          <div className="mobile-brand"><Brand /></div>
          <p className="eyebrow">WELCOME BACK</p>
          <h2>Sign in to your account</h2>
          <p className="muted">Use your campus or recruiter account to continue.</p>
          {error && <div className="alert error" role="alert">{error}</div>}
          <form className="form-stack" onSubmit={(event) => { event.preventDefault(); onLogin(email, password); }}>
            <label>Email address<input type="email" autoComplete="username" required maxLength="190" value={email} onChange={(event) => setEmail(event.target.value)} /></label>
            <label>Password<input type="password" autoComplete="current-password" required value={password} onChange={(event) => setPassword(event.target.value)} /></label>
            <button className="full-button" type="submit" disabled={busy}>{busy ? 'Signing in…' : 'Sign in'} <span>→</span></button>
          </form>
          <div className="demo-hint"><strong>Demo accounts</strong><span>Officer: officer@example.com</span><span>Student: aarav@example.com</span><span>Recruiter: recruiter@example.com</span><small>Password for all: <code>password</code></small></div>
        </div>
      </section>
    </main>
  );
}

function Status({ value }) {
  return <span className={`status status-${value}`}>{statusNames[value] || value}</span>;
}

function ApplicationTable({ rows, canManage, onUpdate, busyId }) {
  if (!rows.length) return <div className="empty-state">No applications found.</div>;
  return (
    <div className="table-wrap">
      <table>
        <thead><tr><th>Student</th><th>Role / company</th><th>Applied</th><th>Status</th>{canManage && <th>Interview / selection update</th>}</tr></thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id}>
              <td><strong>{row.student_name}</strong><small>{row.roll_number} · {row.department} · CGPA {row.cgpa}</small></td>
              <td>{row.title}<small>{row.company_name}</small></td>
              <td>{new Date(`${row.applied_at.replace(' ', 'T')}Z`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}</td>
              <td><Status value={row.status} />{row.recruiter_notes && <small>{row.recruiter_notes}</small>}</td>
              {canManage && <td><form className="status-form" onSubmit={(event) => {
                event.preventDefault();
                const formData = new FormData(event.currentTarget);
                onUpdate(row.id, formData.get('status'), formData.get('notes'));
              }}>
                <select name="status" aria-label="New application status" defaultValue={row.status}>{statusOptions[row.status].map((status) => <option key={status} value={status}>{statusNames[status]}</option>)}</select>
                <input name="notes" maxLength="1000" placeholder="Interview notes (optional)" defaultValue={row.recruiter_notes || ''} />
                <button type="submit" disabled={busyId === row.id}>{busyId === row.id ? 'Saving…' : 'Save'}</button>
              </form></td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function JobCard({ job, user, profile, appliedIds, onApply, onToggle, busyId }) {
  const isEligible = user.role === 'student' && eligible(profile, job);
  return (
    <article className="job-card">
      <div className="job-heading"><div><p className="eyebrow">{job.company_name}</p><h3>{job.title}</h3></div><Status value={job.status} /></div>
      <p className="job-description">{job.description}</p>
      <div className="job-meta"><span>{job.location}</span><span>Minimum CGPA: {job.min_cgpa}</span><span>Departments: {job.eligible_departments === '*' ? 'All' : job.eligible_departments}</span><span>Graduation year: {job.graduation_year || 'Any'}</span></div>
      {user.role === 'student' && (isEligible
        ? appliedIds.includes(Number(job.id))
          ? <span className="success-text">Application submitted</span>
          : <button onClick={() => onApply(job.id)} disabled={busyId === job.id}>{busyId === job.id ? 'Applying…' : 'Apply now'}</button>
        : <span className="muted small-text">{profile ? 'Your profile does not meet the listed criteria.' : 'Complete your profile before applying.'}</span>)}
      {user.role !== 'student' && <button className="button-secondary" onClick={() => onToggle(job.id, job.status === 'open' ? 'closed' : 'open')} disabled={busyId === job.id}>{busyId === job.id ? 'Saving…' : job.status === 'open' ? 'Close posting' : 'Reopen posting'}</button>}
    </article>
  );
}

function ProfileForm({ profile, onSave, busy }) {
  return (
    <section className="panel profile-panel">
      <form className="form-grid" onSubmit={(event) => {
        event.preventDefault();
        const values = Object.fromEntries(new FormData(event.currentTarget));
        onSave(values);
      }}>
        <label>Full name<input value={profile.name} disabled /></label><label>Campus email<input value={profile.email} disabled /></label>
        <label>Roll number<input name="roll_number" maxLength="40" required defaultValue={profile.roll_number || ''} placeholder="CSE2026-014" /></label>
        <label>Department<input name="department" maxLength="100" required defaultValue={profile.department || ''} placeholder="Computer Science" /></label>
        <label>Graduation year<input type="number" name="graduation_year" min="2000" max="2100" required defaultValue={profile.graduation_year || ''} /></label>
        <label>CGPA (out of 10)<input type="number" name="cgpa" min="0" max="10" step="0.01" required defaultValue={profile.cgpa || ''} /></label>
        <label>Phone<input type="tel" name="phone" maxLength="30" defaultValue={profile.phone || ''} placeholder="Optional" /></label>
        <label className="span-all">Skills<input name="skills" maxLength="500" defaultValue={profile.skills || ''} placeholder="PHP, SQL, Java, communication" /></label>
        <div className="span-all"><button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save profile'} <span>→</span></button></div>
      </form>
    </section>
  );
}

function JobForm({ user, companies, onCreate, busy }) {
  return (
    <section className="panel form-panel">
      <div className="section-heading"><div><p className="eyebrow">NEW OPPORTUNITY</p><h2>Post a role</h2></div></div>
      <form className="form-grid" onSubmit={(event) => {
        event.preventDefault();
        onCreate(Object.fromEntries(new FormData(event.currentTarget)));
        event.currentTarget.reset();
      }}>
        {user.role === 'admin' && <label>Company<select name="company_id" required defaultValue=""><option value="" disabled>Select a company</option>{companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}</select></label>}
        <label>Job title<input name="title" required maxLength="150" placeholder="e.g. Graduate Software Engineer" /></label>
        <label>Location<input name="location" required maxLength="120" placeholder="City / Hybrid" /></label>
        <label>Minimum CGPA<input type="number" name="min_cgpa" required min="0" max="10" step="0.01" defaultValue="0" /></label>
        <label>Eligible departments<input name="eligible_departments" required maxLength="255" defaultValue="*" placeholder="Computer Science, Information Technology" /></label>
        <label>Graduation year <span className="muted">(optional)</span><input type="number" name="graduation_year" min="2000" max="2100" placeholder="Any year" /></label>
        <label className="span-all">Role description<textarea name="description" rows="3" required maxLength="10000" placeholder="Responsibilities, skills, and selection process" /></label>
        <div className="span-all"><button type="submit" disabled={busy}>{busy ? 'Publishing…' : 'Publish job'} <span>→</span></button></div>
      </form>
    </section>
  );
}

function AccountForm({ companies, onCreate, busy }) {
  return (
    <section className="panel form-panel">
      <div className="section-heading"><div><p className="eyebrow">ACCESS MANAGEMENT</p><h2>Create a student or recruiter account</h2></div></div>
      <form className="form-grid" onSubmit={(event) => {
        event.preventDefault();
        onCreate(Object.fromEntries(new FormData(event.currentTarget)));
        event.currentTarget.reset();
      }}>
        <label>Full name<input name="name" maxLength="120" required /></label><label>Email<input type="email" name="email" maxLength="190" required /></label>
        <label>Temporary password<input type="password" name="password" minLength="10" maxLength="72" required /><small>At least 10 characters; share securely.</small></label>
        <label>Role<select name="role" required defaultValue="student"><option value="student">Student</option><option value="recruiter">Recruiter</option></select></label>
        <label className="span-all">Recruiter company <span className="muted">(select for recruiter accounts)</span><select name="company_id" defaultValue=""><option value="">Not applicable</option>{companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}</select></label>
        <div className="span-all"><button type="submit" disabled={busy}>{busy ? 'Creating…' : 'Create account'}</button></div>
      </form>
    </section>
  );
}

function App() {
  const [user, setUser] = useState(null);
  const [data, setData] = useState(null);
  const [page, setPage] = useState('dashboard');
  const [csrfToken, setCsrfToken] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [busyId, setBusyId] = useState(null);
  const [notice, setNotice] = useState(null);
  const [loginError, setLoginError] = useState('');

  const loadWorkspace = useCallback(async () => {
    const session = await request('/api.php?action=session');
    setCsrfToken(session.csrfToken);
    setUser(session.user);
    if (session.user) {
      setData(await request('/api.php?resource=bootstrap'));
    }
  }, []);

  const refresh = useCallback(async () => {
    const workspace = await request('/api.php?resource=bootstrap');
    setData(workspace);
    setUser(workspace.user);
    return workspace;
  }, []);

  useEffect(() => {
    loadWorkspace().catch((error) => {
      if (error.message !== 'Sign in to continue.') setNotice({ type: 'error', text: error.message });
    }).finally(() => setLoading(false));
  }, [loadWorkspace]);

  const act = useCallback(async (action, payload = {}) => {
    const result = await request(`/api.php?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(payload),
    });
    setNotice({ type: 'success', text: result.message || 'Changes saved.' });
    if (action !== 'logout') await refresh();
    return result;
  }, [csrfToken, refresh]);

  async function signIn(email, password) {
    setBusy(true);
    setLoginError('');
    try {
      const result = await act('login', { email, password });
      setCsrfToken(result.csrfToken);
    } catch (error) {
      setLoginError(error.message);
    } finally {
      setBusy(false);
    }
  }

  async function signOut() {
    setBusy(true);
    try {
      await act('logout');
      setUser(null);
      setData(null);
      setPage('dashboard');
      const session = await request('/api.php?action=session');
      setCsrfToken(session.csrfToken);
    } catch (error) {
      setNotice({ type: 'error', text: error.message });
    } finally {
      setBusy(false);
    }
  }

  async function perform(action, payload, id = null) {
    setBusyId(id || action);
    try {
      await act(action, payload);
    } catch (error) {
      setNotice({ type: 'error', text: error.message });
    } finally {
      setBusyId(null);
    }
  }

  const appliedIds = useMemo(() => (data?.applications || []).map((application) => Number(application.job_id)), [data]);

  if (loading) return <main className="loading-screen"><Brand /><p>Loading your placement workspace…</p></main>;
  if (!user || !data) return <Login onLogin={signIn} busy={busy} error={loginError || notice?.text} />;

  const isStudent = user.role === 'student';
  const canManage = !isStudent;
  const roleLabel = user.role === 'admin' ? 'Placement officer' : user.role === 'recruiter' ? 'Recruiter' : 'Student';
  const pageTitles = {
    dashboard: ['CAMPUS PLACEMENTS', 'Placement workspace', "Here's your placement snapshot."],
    jobs: ['OPPORTUNITIES', 'Job postings', 'Explore roles and manage employer opportunities.'],
    applications: ['SELECTION PIPELINE', 'Applications', isStudent ? 'Track each application and recruiter update.' : 'Review candidates and record interviews, decisions, and offers.'],
    profile: ['STUDENT DETAILS', 'My profile', 'Your profile is used to assess job eligibility.'],
  };
  const title = pageTitles[page] || pageTitles.dashboard;

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <Brand />
        <p className="nav-label">WORKSPACE</p>
        <nav>{navigation.map((item) => <button key={item.id} className={page === item.id ? 'active' : ''} onClick={() => setPage(item.id)}><span>{item.icon}</span>{item.label}</button>)}{isStudent && <button className={page === 'profile' ? 'active' : ''} onClick={() => setPage('profile')}><span>◉</span>My profile</button>}</nav>
        <div className="sidebar-bottom">
          <div className="user-chip"><span className="avatar">{user.name[0]}</span><span>{user.name}<small>{roleLabel}</small></span></div>
          <button className="logout-button" onClick={signOut} disabled={busy}>Sign out <span>↗</span></button>
        </div>
      </aside>
      <main className="main-content">
        {notice && <div className={`alert ${notice.type}`} role="status">{notice.text}<button className="alert-close" onClick={() => setNotice(null)} aria-label="Dismiss message">×</button></div>}
        {page === 'dashboard' ? (
          <>
            <header className="topbar"><div><p className="eyebrow">{roleLabel.toUpperCase()} WORKSPACE</p><h1>Good day, {user.name.split(' ')[0]} <span className="wave">✦</span></h1><p className="muted">{new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })} · Here's your placement snapshot.</p></div><button className="button-link" onClick={() => setPage('jobs')}>Explore opportunities <span>→</span></button></header>
            {isStudent && !data.profile && <div className="notice"><strong>Complete your profile to get started.</strong> Add your department, CGPA, and graduation year to check placement eligibility. <button className="text-button" onClick={() => setPage('profile')}>Complete profile →</button></div>}
            <section className={`metric-grid${Object.keys(data.dashboard.metrics).length === 1 ? ' single' : ''}`}>{Object.entries(data.dashboard.metrics).map(([label, value]) => <article className="metric-card" key={label}><span>{label}</span><strong>{value}</strong><small>{label === 'Offers' ? 'Successful outcomes' : 'Across the placement cycle'}</small></article>)}</section>
            {canManage && <section className="panel"><div className="section-heading"><div><p className="eyebrow">PIPELINE</p><h2>Application progress</h2></div><button className="text-button" onClick={() => setPage('applications')}>View all →</button></div><div className="pipeline">{Object.keys(statusNames).map((status) => <div key={status}><Status value={status} /><strong>{data.dashboard.pipeline[status] || 0}</strong></div>)}</div></section>}
            {user.role === 'admin' && <section className="panel"><div className="section-heading"><div><p className="eyebrow">PLACEMENT REPORT</p><h2>Outcomes by department</h2></div></div>{data.dashboard.departmentReports.length ? <div className="table-wrap"><table><thead><tr><th>Department</th><th>Applications</th><th>Offers</th></tr></thead><tbody>{data.dashboard.departmentReports.map((row) => <tr key={row.department}><td>{row.department}</td><td>{row.applications}</td><td>{row.offers}</td></tr>)}</tbody></table></div> : <div className="empty-state">Student department data will appear here as profiles are added.</div>}</section>}
            {user.role === 'admin' && <AccountForm companies={data.companies} busy={busyId === 'create_user'} onCreate={(values) => perform('create_user', values)} />}
            <section className="panel"><div className="section-heading"><div><p className="eyebrow">{isStudent ? 'MY ACTIVITY' : 'LATEST ACTIVITY'}</p><h2>{isStudent ? 'Application updates' : 'Recent applications'}</h2></div><button className="text-button" onClick={() => setPage('applications')}>See all →</button></div><ApplicationTable rows={data.dashboard.recentApplications} canManage={canManage} busyId={busyId} onUpdate={(id, status, notes) => perform('update_application', { application_id: id, status, notes }, id)} /></section>
          </>
        ) : (
          <>
            <header className="topbar"><div><p className="eyebrow">{title[0]}</p><h1>{title[1]}</h1><p className="muted">{title[2]}</p></div></header>
            {page === 'profile' && isStudent && <ProfileForm profile={{ name: user.name, email: user.email, ...(data.profile || {}) }} busy={busyId === 'save_profile'} onSave={(values) => perform('save_profile', values)} />}
            {page === 'jobs' && <>
              {isStudent && !data.profile && <div className="notice"><strong>Complete your profile before applying.</strong> Add your department, CGPA, and graduation year to check which roles fit. <button className="text-button" onClick={() => setPage('profile')}>Complete profile →</button></div>}
              {canManage && <JobForm user={user} companies={data.companies} busy={busyId === 'create_job'} onCreate={(values) => perform('create_job', values)} />}
              <section className="job-list">{data.jobs.length ? data.jobs.map((job) => <JobCard key={job.id} job={job} user={user} profile={data.profile} appliedIds={appliedIds} busyId={busyId} onApply={(id) => perform('apply', { job_id: id }, id)} onToggle={(id, status) => perform('toggle_job', { job_id: id, status }, id)} />) : <div className="empty-state">No job postings to show yet.</div>}</section>
            </>}
            {page === 'applications' && <section className="panel"><ApplicationTable rows={data.applications} canManage={canManage} busyId={busyId} onUpdate={(id, status, notes) => perform('update_application', { application_id: id, status, notes }, id)} /></section>}
          </>
        )}
        <footer className="footer">CampusPath <span>·</span> College placement management</footer>
      </main>
    </div>
  );
}

export default App;

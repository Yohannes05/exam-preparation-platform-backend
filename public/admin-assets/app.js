/* Admin panel SPA — vanilla JS, no build step.
 * Talks to the Laravel API (/api/*) with a Sanctum bearer token. */
(() => {
    'use strict';

    // ------------------------------------------------------------------
    // State & tiny helpers
    // ------------------------------------------------------------------
    const state = {
        token: localStorage.getItem('admin_token') || null,
        user: null,
    };

    const refCache = new Map(); // resource key -> array of records (for selects)

    const $ = (sel, el = document) => el.querySelector(sel);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
    const getPath = (obj, path) => path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);
    const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
    const trunc = (s, n = 60) => {
        s = String(s ?? '');
        return s.length > n ? s.slice(0, n - 1) + '…' : s;
    };
    const debounce = (fn, ms = 300) => {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    };
    const el = (html) => {
        const t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstElementChild;
    };

    class ApiError extends Error {
        constructor(message, errors, status) {
            super(message);
            this.errors = errors || null;
            this.status = status;
        }
    }

    async function api(path, opts = {}) {
        const headers = { Accept: 'application/json', ...(opts.headers || {}) };
        if (state.token) headers.Authorization = 'Bearer ' + state.token;

        let body = opts.body;
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }

        const res = await fetch('/api' + path, { method: opts.method || 'GET', headers, body });
        const data = await res.json().catch(() => ({}));

        if (res.status === 401) {
            state.token = null;
            localStorage.removeItem('admin_token');
            state.user = null;
            loginView();
            throw new ApiError(data.message || 'Unauthenticated.', null, 401);
        }
        if (!res.ok) {
            throw new ApiError(data.message || `Request failed (${res.status})`, data.errors, res.status);
        }
        return data;
    }

    function toast(msg, kind = 'ok') {
        const holder = $('#toast-holder');
        const t = el(`<div class="toast ${kind === 'err' ? 'err' : ''}">${esc(msg)}</div>`);
        holder.appendChild(t);
        setTimeout(() => t.remove(), 3500);
    }

    function confirmDialog(message) {
        return new Promise((resolve) => {
            const backdrop = el(`
                <div class="modal-backdrop">
                    <div class="modal" style="max-width:420px">
                        <h2>Are you sure?</h2>
                        <p class="muted">${esc(message)}</p>
                        <div class="modal-actions">
                            <button class="btn secondary" data-act="no">Cancel</button>
                            <button class="btn danger" data-act="yes">Delete</button>
                        </div>
                    </div>
                </div>`);
            backdrop.addEventListener('click', (e) => {
                const act = e.target.closest('[data-act]')?.dataset.act;
                if (act || e.target === backdrop) {
                    backdrop.remove();
                    resolve(act === 'yes');
                }
            });
            document.body.appendChild(backdrop);
        });
    }

    // ------------------------------------------------------------------
    // Reference data (select options)
    // ------------------------------------------------------------------
    async function getRef(resource) {
        if (!refCache.has(resource)) {
            const res = await api(`/v1/${resource}?per_page=100`);
            refCache.set(resource, res.data);
        }
        return refCache.get(resource);
    }
    const invalidateRef = (resource) => refCache.delete(resource);

    // ------------------------------------------------------------------
    // Resource registry (mirrors config/platform.php)
    // ------------------------------------------------------------------
    const activeBadge = (v) => (v
        ? '<span class="badge green">Active</span>'
        : '<span class="badge">Inactive</span>');

    const RESOURCES = {
        grades: {
            label: 'Grades',
            columns: [
                { label: '#', path: 'id', width: '50px' },
                { label: 'Name', path: 'name' },
                { label: 'Level', path: 'level' },
                { label: 'Description', path: (r) => esc(trunc(r.description, 50)) },
                { label: 'Status', path: (r) => activeBadge(r.is_active) },
            ],
            fields: [
                { name: 'name', label: 'Name', type: 'text', required: true },
                { name: 'level', label: 'Level (1–12)', type: 'number', required: true },
                { name: 'description', label: 'Description', type: 'textarea', full: true },
                { name: 'is_active', label: 'Active', type: 'checkbox', default: true },
            ],
        },
        subjects: {
            label: 'Subjects',
            columns: [
                { label: '#', path: 'id', width: '50px' },
                { label: 'Name', path: 'name' },
                { label: 'Grade', path: (r) => esc(r.grade?.name ?? '—') },
                { label: 'Order', path: 'order', width: '70px' },
                { label: 'Status', path: (r) => activeBadge(r.is_active) },
            ],
            fields: [
                { name: 'grade_id', label: 'Grade', type: 'select', resource: 'grades', required: true },
                { name: 'name', label: 'Name', type: 'text', required: true },
                { name: 'description', label: 'Description', type: 'textarea', full: true },
                { name: 'order', label: 'Sort order', type: 'number' },
                { name: 'is_active', label: 'Active', type: 'checkbox', default: true },
            ],
        },
        chapters: {
            label: 'Chapters',
            columns: [
                { label: '#', path: 'id', width: '50px' },
                { label: 'Title', path: 'title' },
                { label: 'Subject', path: (r) => esc(r.subject?.name ?? '—') },
                { label: 'Order', path: 'order', width: '70px' },
                { label: 'Status', path: (r) => activeBadge(r.is_active) },
            ],
            fields: [
                { name: 'subject_id', label: 'Subject', type: 'select', resource: 'subjects', required: true },
                { name: 'title', label: 'Title', type: 'text', required: true },
                { name: 'description', label: 'Description', type: 'textarea', full: true },
                { name: 'order', label: 'Sort order', type: 'number' },
                { name: 'is_active', label: 'Active', type: 'checkbox', default: true },
            ],
        },
        topics: {
            label: 'Topics',
            columns: [
                { label: '#', path: 'id', width: '50px' },
                { label: 'Title', path: 'title' },
                { label: 'Chapter', path: (r) => esc(r.chapter?.title ?? '—') },
                { label: 'Order', path: 'order', width: '70px' },
            ],
            fields: [
                { name: 'chapter_id', label: 'Chapter', type: 'select', resource: 'chapters', required: true },
                { name: 'title', label: 'Title', type: 'text', required: true },
                { name: 'description', label: 'Description', type: 'textarea', full: true },
                { name: 'order', label: 'Sort order', type: 'number' },
            ],
        },
        notes: {
            label: 'Notes',
            columns: [
                { label: '#', path: 'id', width: '50px' },
                { label: 'Title', path: 'title' },
                { label: 'Grade', path: (r) => esc(r.grade?.name ?? '—') },
                { label: 'Subject', path: (r) => esc(r.subject?.name ?? '—') },
                { label: 'Chapter', path: (r) => esc(r.chapter?.title ?? '—') },
                { label: 'Order', path: 'order', width: '70px' },
            ],
            fields: [
                { name: 'grade_id', label: 'Grade', type: 'select', resource: 'grades', required: true },
                { name: 'subject_id', label: 'Subject', type: 'select', resource: 'subjects', required: true, dependsOn: 'grade_id', filterKey: 'grade_id' },
                { name: 'chapter_id', label: 'Chapter', type: 'select', resource: 'chapters', required: true, dependsOn: 'subject_id', filterKey: 'subject_id' },
                { name: 'topic_id', label: 'Topic (optional)', type: 'select', resource: 'topics', dependsOn: 'chapter_id', filterKey: 'chapter_id' },
                { name: 'title', label: 'Title', type: 'text', required: true },
                { name: 'content', label: 'Content', type: 'textarea', required: true, full: true },
                { name: 'order', label: 'Sort order', type: 'number' },
            ],
        },
        questions: {
            label: 'Questions',
            columns: [
                { label: '#', path: 'id', width: '50px' },
                { label: 'Question', path: (r) => esc(trunc(r.question_text, 60)) },
                { label: 'Subject', path: (r) => esc(r.subject?.name ?? '—') },
                { label: 'Chapter', path: (r) => esc(r.chapter?.title ?? '—') },
                { label: 'Difficulty', path: (r) => `<span class="badge ${r.difficulty === 'easy' ? 'green' : r.difficulty === 'hard' ? 'red' : 'amber'}">${esc(r.difficulty)}</span>` },
                { label: 'Status', path: (r) => activeBadge(r.is_active) },
            ],
            fields: [
                { name: 'grade_id', label: 'Grade', type: 'select', resource: 'grades', required: true },
                { name: 'subject_id', label: 'Subject', type: 'select', resource: 'subjects', required: true, dependsOn: 'grade_id', filterKey: 'grade_id' },
                { name: 'chapter_id', label: 'Chapter', type: 'select', resource: 'chapters', required: true, dependsOn: 'subject_id', filterKey: 'subject_id' },
                { name: 'topic_id', label: 'Topic (optional)', type: 'select', resource: 'topics', dependsOn: 'chapter_id', filterKey: 'chapter_id' },
                { name: 'question_text', label: 'Question text', type: 'textarea', required: true, full: true },
                { name: 'question_type', label: 'Type', type: 'select', choices: [['multiple_choice', 'Multiple choice'], ['true_false', 'True / False']], required: true, default: 'multiple_choice' },
                { name: 'difficulty', label: 'Difficulty', type: 'select', choices: [['easy', 'Easy'], ['medium', 'Medium'], ['hard', 'Hard']], required: true, default: 'medium' },
                { name: 'source', label: 'Source', type: 'text' },
                { name: 'year', label: 'Year', type: 'number' },
                { name: 'explanation', label: 'Explanation', type: 'textarea', full: true },
                { name: 'is_active', label: 'Active', type: 'checkbox', default: true },
                { name: 'options', label: 'Options', type: 'options', full: true },
            ],
        },
        exams: {
            label: 'Exams',
            columns: [
                { label: '#', path: 'id', width: '50px' },
                { label: 'Title', path: 'title' },
                { label: 'Type', path: (r) => `<span class="badge ${r.type === 'mock' ? 'blue' : 'amber'}">${esc(r.type === 'mock' ? 'Mock' : 'Chapter test')}</span>` },
                { label: 'Grade', path: (r) => esc(r.grade?.name ?? '—') },
                { label: 'Subject', path: (r) => esc(r.subject?.name ?? '—') },
                { label: 'Questions', path: 'question_count', width: '80px' },
                { label: 'Pass mark', path: (r) => esc(r.pass_mark + '%'), width: '90px' },
                { label: 'Status', path: (r) => activeBadge(r.is_active) },
            ],
            fields: [
                { name: 'title', label: 'Title', type: 'text', required: true, full: true },
                { name: 'type', label: 'Type', type: 'select', choices: [['chapter_test', 'Chapter test'], ['mock', 'Mock exam']], required: true, default: 'chapter_test' },
                { name: 'grade_id', label: 'Grade', type: 'select', resource: 'grades', required: true },
                { name: 'subject_id', label: 'Subject', type: 'select', resource: 'subjects', required: true, dependsOn: 'grade_id', filterKey: 'grade_id' },
                { name: 'chapter_id', label: 'Chapter (optional)', type: 'select', resource: 'chapters', dependsOn: 'subject_id', filterKey: 'subject_id' },
                { name: 'question_count', label: 'Question count', type: 'number', required: true, default: 10 },
                { name: 'time_limit_minutes', label: 'Time limit (minutes)', type: 'number', required: true, default: 20 },
                { name: 'pass_mark', label: 'Pass mark (%)', type: 'number', required: true, default: 50 },
                { name: 'distribution', label: 'Distribution (optional JSON)', type: 'json', full: true, hint: 'e.g. {"difficulties": {"easy": 3, "medium": 5, "hard": 2}}' },
                { name: 'is_active', label: 'Active', type: 'checkbox', default: true },
            ],
        },
        announcements: {
            label: 'Announcements',
            columns: [
                { label: '#', path: 'id', width: '50px' },
                { label: 'Title', path: 'title' },
                { label: 'Audience', path: (r) => `<span class="badge blue">${esc(r.audience)}</span>` },
                { label: 'Grade', path: (r) => esc(r.grade?.name ?? 'All') },
                { label: 'Published', path: (r) => (r.published_at ? `<span class="badge green">${esc(fmtDate(r.published_at))}</span>` : '<span class="badge">Draft</span>') },
            ],
            fields: [
                { name: 'title', label: 'Title', type: 'text', required: true, full: true },
                { name: 'body', label: 'Body', type: 'textarea', required: true, full: true },
                { name: 'audience', label: 'Audience', type: 'select', choices: [['all', 'All students'], ['grade', 'Specific grade']], required: true, default: 'all' },
                { name: 'grade_id', label: 'Grade', type: 'select', resource: 'grades', showIf: (data) => data.audience === 'grade' },
                { name: 'is_published', label: 'Published', type: 'checkbox', default: false },
            ],
        },
    };

    const resourceKeys = Object.keys(RESOURCES);

    // ------------------------------------------------------------------
    // Shell / navigation
    // ------------------------------------------------------------------
    const NAV = [
        { section: 'Overview' },
        { hash: '#/dashboard', label: 'Dashboard' },
        { section: 'People' },
        { hash: '#/students', label: 'Students' },
        { hash: '#/results', label: 'Results' },
        { section: 'Content' },
        ...['grades', 'subjects', 'chapters', 'topics', 'notes', 'questions'].map((k) => ({
            hash: `#/content/${k}`, label: RESOURCES[k].label,
        })),
        { section: 'Assessment' },
        { hash: '#/content/exams', label: 'Exams' },
        { section: 'Communication' },
        { hash: '#/content/announcements', label: 'Announcements' },
    ];

    function renderShell() {
        const items = NAV.map((n) => n.section
            ? `<div class="nav-section">${esc(n.section)}</div>`
            : `<a class="nav-item" href="${n.hash}" data-hash="${n.hash}">${esc(n.label)}</a>`).join('');

        $('#app').innerHTML = `
            <div class="shell">
                <aside class="sidebar">
                    <div class="brand">📚 Study Bot Admin</div>
                    ${items}
                    <div class="nav-user">
                        <div class="who">${esc(state.user?.name ?? '')}</div>
                        <div class="muted">${esc(state.user?.email ?? '')}</div>
                        <button class="btn secondary small" id="logout-btn">Log out</button>
                    </div>
                </aside>
                <main class="main" id="main"></main>
            </div>`;
        $('#logout-btn').addEventListener('click', doLogout);
        setActiveNav(location.hash || '#/dashboard');
    }

    function setActiveNav(hash) {
        document.querySelectorAll('.sidebar .nav-item').forEach((a) => {
            a.classList.toggle('active', a.dataset.hash === hash);
        });
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------
    function loginView(error) {
        $('#app').innerHTML = `
            <div class="login-wrap">
                <form class="login-card" id="login-form">
                    <h1>Admin Panel</h1>
                    <p class="sub">Sign in to manage content, students and exams.</p>
                    ${error ? `<div class="alert error">${esc(error)}</div>` : ''}
                    <div class="form-row">
                        <label>Email</label>
                        <input type="email" name="email" required autofocus>
                    </div>
                    <div class="form-row">
                        <label>Password<span class="req">*</span></label>
                        <div class="input-wrap">
                            <input type="password" name="password" id="password-field" required>
                            <button type="button" class="toggle-eye btn secondary small" id="password-toggle" aria-label="Show/hide password">
                                <svg class="eye-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                    <button class="btn" type="submit" style="width:100%">Sign in</button>
                </form>
            </div>`;

        $('#password-toggle').addEventListener('click', () => {
            const show = $('#password-field').type === 'password';
            $('#password-field').type = show ? 'text' : 'password';
            $('#password-toggle').querySelector('.eye-svg').innerHTML = show
                ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.45 18.45 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
                : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        });

        $('#login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            try {
                const res = await api('/login', {
                    method: 'POST',
                    body: { email: fd.get('email'), password: fd.get('password') },
                });
                state.token = res.token;
                state.user = res.user;
                localStorage.setItem('admin_token', res.token);
                renderShell();
                location.hash = '#/dashboard';
                navigate();
            } catch (err) {
                if (err.status !== 401) loginView(err.message);
            }
        });
    }

    async function doLogout() {
        try {
            await api('/logout');
        } catch {
            /* ignore network errors; still log out client-side */
        }
        state.token = null;
        state.user = null;
        localStorage.removeItem('admin_token');
        renderShell();
        location.hash = '#/login';
        navigate();
    }

    // Force any in-flight view updates after a logout so the login form is
    // re-rendered instead of a stale shell or a route that still targets the
    // previous authenticated view.
    if (typeof loginView === 'function') loginView();
    if (typeof renderShell === 'function') renderShell();
    location.hash = '#/login';
    if (typeof navigate === 'function') navigate();

    // ------------------------------------------------------------------
    // Router
    // ------------------------------------------------------------------
    const routes = [
        { re: /^#\/dashboard$/, view: dashboardView },
        { re: /^#\/students$/, view: studentsView },
        { re: /^#\/students\/(\d+)$/, view: studentDetailView },
        { re: /^#\/results$/, view: resultsView },
        { re: /^#\/results\/(\d+)$/, view: resultDetailView },
        { re: /^#\/content\/(\w+)$/, view: resourceListView },
    ];

    function navigate() {
        const hash = location.hash || '#/dashboard';
        setActiveNav(hash);
        const main = $('#main');
        if (!main) return;

        for (const r of routes) {
            const m = hash.match(r.re);
            if (m) {
                r.view(main, ...m.slice(1)).catch((err) => {
                    if (err instanceof ApiError && err.status === 401) return; // login already shown
                    main.innerHTML = `<div class="alert error">Failed to load page: ${esc(err.message)}</div>`;
                });
                return;
            }
        }
        location.hash = '#/dashboard';
    }

    const topbar = (title, actions = '') => `
        <div class="topbar">
            <h1>${esc(title)}</h1>
            <div class="actions">${actions}</div>
        </div>`;

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------
    async function dashboardView(main) {
        main.innerHTML = topbar('Dashboard') + '<div class="muted">Loading…</div>';
        const d = await api('/dashboard');

        const stat = (label, value) => `<div class="stat"><div class="label">${esc(label)}</div><div class="value">${esc(value)}</div></div>`;
        const bar = (pct, cls = '') => {
            const p = Math.max(0, Math.min(100, Number(pct) || 0));
            return `<div class="bar-track"><div class="bar-fill ${p >= 70 ? 'green' : p >= 40 ? 'amber' : 'red'} ${cls}" style="width:${p}%"></div></div>`;
        };

        main.innerHTML = topbar('Dashboard') + `
            <div class="stat-grid">
                ${stat('Students', d.students.total)}
                ${stat('Active students', d.students.active)}
                ${stat('New this week', d.students.new_this_week)}
                ${stat('Questions answered', d.activity.questions_answered)}
                ${stat('Overall accuracy', d.activity.accuracy + '%')}
                ${stat('Chapter tests completed', d.activity.chapter_tests_completed)}
                ${stat('Mock exams completed', d.activity.mock_exams_completed)}
                ${stat('Questions in bank', d.content.questions)}
                ${stat('Announcements', d.content.announcements)}
            </div>
            <div class="card">
                <h2>Popular subjects</h2>
                ${d.popular_subjects.length === 0 ? '<div class="empty">No activity yet.</div>' : `
                <table class="data">
                    <thead><tr><th>Subject</th><th style="width:90px">Answers</th><th style="width:260px">Accuracy</th></tr></thead>
                    <tbody>
                        ${d.popular_subjects.map((s) => `
                            <tr>
                                <td>${esc(s.name)}</td>
                                <td>${esc(s.answers)}</td>
                                <td>${bar(s.accuracy)}<span class="small muted">${esc(s.accuracy)}%</span></td>
                            </tr>`).join('')}
                    </tbody>
                </table>`}
            </div>
            <div class="card">
                <h2>Most difficult questions <span class="muted small">(min. 3 attempts)</span></h2>
                ${d.difficult_questions.length === 0 ? '<div class="empty">Not enough data yet.</div>' : `
                <table class="data">
                    <thead><tr><th>Question</th><th style="width:90px">Attempts</th><th style="width:260px">Accuracy</th></tr></thead>
                    <tbody>
                        ${d.difficult_questions.map((q) => `
                            <tr>
                                <td>${esc(trunc(q.question_text, 90))}</td>
                                <td>${esc(q.attempts)}</td>
                                <td>${bar(q.accuracy)}<span class="small muted">${esc(q.accuracy)}%</span></td>
                            </tr>`).join('')}
                    </tbody>
                </table>`}
            </div>`;
    }

    // ------------------------------------------------------------------
    // Students
    // ------------------------------------------------------------------
    async function studentsView(main) {
        main.innerHTML = topbar('Students') + '<div class="muted">Loading…</div>';
        const grades = await getRef('grades').catch(() => []);

        main.innerHTML = topbar('Students') + `
            <div class="toolbar">
                <input type="text" id="student-search" placeholder="Search name or username…">
                <select id="student-grade">
                    <option value="">All grades</option>
                    ${grades.map((g) => `<option value="${g.id}">${esc(g.name)}</option>`).join('')}
                </select>
            </div>
            <div id="students-table"><div class="muted">Loading…</div></div>`;

        const load = async (page = 1) => {
            const params = new URLSearchParams({ page: String(page) });
            const search = $('#student-search')?.value.trim();
            const grade = $('#student-grade')?.value;
            if (search) params.set('search', search);
            if (grade) params.set('grade_id', grade);

            const res = await api('/students?' + params);
            const rows = res.data.map((s) => `
                <tr>
                    <td>${esc(s.display_name)}</td>
                    <td class="muted">${esc(s.telegram_username ? '@' + s.telegram_username : '—')}</td>
                    <td>${esc(s.grade?.name ?? '—')}</td>
                    <td>${esc(s.question_attempts_count ?? 0)}</td>
                    <td>${s.accuracy == null ? '<span class="muted">—</span>' : esc(s.accuracy) + '%'}</td>
                    <td>${s.is_active ? '<span class="badge green">Active</span>' : '<span class="badge">Disabled</span>'}</td>
                    <td class="actions"><a href="#/students/${s.id}"><button class="btn secondary small">View</button></a></td>
                </tr>`).join('');

            $('#students-table').innerHTML = res.data.length === 0
                ? '<div class="empty">No students found.</div>'
                : `
                <table class="data">
                    <thead><tr><th>Student</th><th>Telegram</th><th>Grade</th><th>Answered</th><th>Accuracy</th><th>Status</th><th></th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
                ${pager(res, load)}`;
        };

        $('#student-search').addEventListener('input', debounce(() => load(1)));
        $('#student-grade').addEventListener('change', () => load(1));
        await load(1);
    }

    function pager(res, load) {
        if (res.last_page <= 1) return `<div class="pagination muted">Total: ${res.total}</div>`;
        return `
            <div class="pagination">
                <button class="btn secondary small" ${res.current_page <= 1 ? 'disabled' : ''} data-page="${res.current_page - 1}">‹ Prev</button>
                <span>Page ${res.current_page} of ${res.last_page} · ${res.total} total</span>
                <button class="btn secondary small" ${res.current_page >= res.last_page ? 'disabled' : ''} data-page="${res.current_page + 1}">Next ›</button>
            </div>`;
    }

    function bindPager(container, load) {
        container.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-page]');
            if (btn && !btn.disabled) load(Number(btn.dataset.page));
        });
    }

    async function studentDetailView(main, id) {
        main.innerHTML = topbar('Student') + '<div class="muted">Loading…</div>';
        const [{ student, progress, mistakes, attempts }, grades] = await Promise.all([
            api(`/students/${id}`),
            getRef('grades').catch(() => []),
        ]);

        const stat = (label, value) => `<div class="stat"><div class="label">${esc(label)}</div><div class="value">${esc(value)}</div></div>`;
        const bar = (pct) => {
            const p = Math.max(0, Math.min(100, Number(pct) || 0));
            return `<div class="bar-track"><div class="bar-fill ${p >= 70 ? 'green' : p >= 40 ? 'amber' : 'red'}" style="width:${p}%"></div></div>`;
        };

        main.innerHTML = topbar(student.display_name, `<a href="#/students"><button class="btn secondary small">← Back to students</button></a>`) + `
            <div class="stat-grid">
                ${stat('Answered', progress.questions_answered)}
                ${stat('Correct', progress.correct)}
                ${stat('Wrong', progress.wrong)}
                ${stat('Accuracy', progress.accuracy + '%')}
                ${stat('Chapter tests', progress.tests_completed)}
                ${stat('Mock exams', progress.mock_exams_completed)}
                ${stat('Open mistakes', progress.open_mistakes)}
            </div>

            <div class="form-grid">
                <div class="card">
                    <h2>Profile</h2>
                    <table class="data" style="margin-bottom:14px">
                        <tbody>
                            <tr><td class="muted" style="width:120px">Telegram</td><td>${esc(student.telegram_username ? '@' + student.telegram_username : '—')} <span class="muted">(#${esc(student.telegram_id)})</span></td></tr>
                            <tr><td class="muted">Joined</td><td>${esc(fmtDate(student.created_at))}</td></tr>
                            <tr><td class="muted">Last seen</td><td>${esc(fmtDate(student.last_seen_at))}</td></tr>
                        </tbody>
                    </table>
                    <div class="form-row">
                        <label>Grade</label>
                        <select id="edit-grade">
                            <option value="">— none —</option>
                            ${grades.map((g) => `<option value="${g.id}" ${student.grade_id === g.id ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-row">
                        <label><input type="checkbox" id="edit-active" ${student.is_active ? 'checked' : ''} style="width:auto"> Active</label>
                    </div>
                    <button class="btn" id="save-student">Save changes</button>
                </div>
                <div class="card">
                    <h2>By subject</h2>
                    ${progress.by_subject.length === 0 ? '<div class="empty">No answers yet.</div>' : `
                        <table class="data">
                            <thead><tr><th>Subject</th><th style="width:70px">Ans.</th><th style="width:180px">Accuracy</th></tr></thead>
                            <tbody>
                                ${progress.by_subject.map((s) => `
                                    <tr><td>${esc(s.name)}</td><td>${esc(s.answered)}</td>
                                    <td>${bar(s.accuracy)}<span class="small muted">${esc(s.accuracy)}%</span></td></tr>`).join('')}
                            </tbody>
                        </table>`}
                    ${progress.weak_topics.length ? '<h2 style="margin-top:16px">Weak topics</h2><ul>' + progress.weak_topics.map((t) => `<li>${esc(t.title)} — <span class="muted">${esc(t.accuracy)}% over ${esc(t.answered)} answers</span></li>`).join('') + '</ul>' : ''}
                </div>
            </div>

            <div class="card">
                <h2>Recent exam attempts</h2>
                ${attempts.length === 0 ? '<div class="empty">No attempts yet.</div>' : `
                <table class="data">
                    <thead><tr><th>Exam</th><th>Status</th><th>Score</th><th>Result</th><th>Started</th></tr></thead>
                    <tbody>
                        ${attempts.map((a) => `
                            <tr>
                                <td>${esc(a.exam?.title ?? '—')}</td>
                                <td>${a.status === 'completed' ? '<span class="badge blue">Completed</span>' : '<span class="badge amber">In progress</span>'}</td>
                                <td>${a.status === 'completed' ? esc(a.score) + '%' : '—'}</td>
                                <td>${a.status === 'completed' ? (a.passed ? '<span class="badge green">Passed</span>' : '<span class="badge red">Failed</span>') : '—'}</td>
                                <td class="muted">${esc(fmtDate(a.started_at))}</td>
                            </tr>`).join('')}
                    </tbody>
                </table>`}
            </div>

            <div class="card">
                <h2>Mistake book <span class="muted small">(unmastered)</span></h2>
                ${mistakes.length === 0 ? '<div class="empty">No open mistakes — nice!</div>' : `
                <table class="data">
                    <thead><tr><th>Question</th><th>Subject</th><th>Wrong ×</th><th>Right ×</th><th>Last wrong</th></tr></thead>
                    <tbody>
                        ${mistakes.map((m) => `
                            <tr>
                                <td>${esc(trunc(m.question?.question_text, 70))}</td>
                                <td>${esc(m.question?.subject?.name ?? '—')}</td>
                                <td>${esc(m.wrong_count)}</td>
                                <td>${esc(m.right_count)}</td>
                                <td class="muted">${esc(fmtDate(m.last_wrong_at))}</td>
                            </tr>`).join('')}
                    </tbody>
                </table>`}
            </div>`;

        $('#save-student').addEventListener('click', async () => {
            try {
                const gradeId = $('#edit-grade').value;
                await api(`/students/${id}`, {
                    method: 'PATCH',
                    body: { grade_id: gradeId === '' ? null : Number(gradeId), is_active: $('#edit-active').checked },
                });
                toast('Student updated.');
                studentDetailView(main, id);
            } catch (err) {
                toast(err.message, 'err');
            }
        });
    }

    // ------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------
    async function resultsView(main) {
        main.innerHTML = topbar('Results') + '<div class="muted">Loading…</div>';

        main.innerHTML = topbar('Results') + `
            <div class="toolbar">
                <select id="result-type">
                    <option value="">All exam types</option>
                    <option value="chapter_test">Chapter tests</option>
                    <option value="mock">Mock exams</option>
                </select>
            </div>
            <div id="results-table"><div class="muted">Loading…</div></div>`;

        const load = async (page = 1) => {
            const params = new URLSearchParams({ page: String(page) });
            const type = $('#result-type')?.value;
            if (type) params.set('type', type);

            const res = await api('/results?' + params);
            const rows = res.data.map((r) => `
                <tr>
                    <td>${esc(r.student?.display_name ?? '—')}</td>
                    <td>${esc(r.exam?.title ?? '—')} <span class="badge ${r.exam?.type === 'mock' ? 'blue' : 'amber'}">${esc(r.exam?.type === 'mock' ? 'Mock' : 'Test')}</span></td>
                    <td><strong>${esc(r.score)}%</strong></td>
                    <td>${r.passed ? '<span class="badge green">Passed</span>' : '<span class="badge red">Failed</span>'}</td>
                    <td>${esc(r.correct_answers)}/${esc(r.total_questions)}</td>
                    <td class="muted">${esc(fmtDate(r.completed_at))}</td>
                    <td class="actions"><a href="#/results/${r.id}"><button class="btn secondary small">View</button></a></td>
                </tr>`).join('');

            $('#results-table').innerHTML = res.data.length === 0
                ? '<div class="empty">No completed attempts yet.</div>'
                : `
                <table class="data">
                    <thead><tr><th>Student</th><th>Exam</th><th>Score</th><th>Outcome</th><th>Correct</th><th>Completed</th><th></th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
                ${pager(res, load)}`;
        };

        bindPager($('#results-table'), load);
        $('#result-type').addEventListener('change', () => load(1));
        await load(1);
    }

    async function resultDetailView(main, id) {
        main.innerHTML = topbar('Result') + '<div class="muted">Loading…</div>';
        const { attempt, breakdown } = await api(`/results/${id}`);

        main.innerHTML = topbar(`Result · ${attempt.student?.display_name ?? '#' + attempt.id}`,
            `<a href="#/results"><button class="btn secondary small">← Back to results</button></a>`) + `
            <div class="stat-grid">
                <div class="stat"><div class="label">Exam</div><div class="value" style="font-size:16px">${esc(attempt.exam?.title ?? '—')}</div></div>
                <div class="stat"><div class="label">Score</div><div class="value">${esc(attempt.score)}%</div></div>
                <div class="stat"><div class="label">Outcome</div><div class="value" style="font-size:16px">${attempt.passed ? '<span class="badge green">Passed</span>' : '<span class="badge red">Failed</span>'}</div></div>
                <div class="stat"><div class="label">Correct</div><div class="value">${esc(attempt.correct_answers)}/${esc(attempt.total_questions)}</div></div>
                <div class="stat"><div class="label">Time spent</div><div class="value" style="font-size:18px">${Math.round((attempt.time_spent_seconds ?? 0) / 60)} min</div></div>
                <div class="stat"><div class="label">Completed</div><div class="value" style="font-size:14px">${esc(fmtDate(attempt.completed_at))}</div></div>
            </div>
            <div class="card">
                <h2>Question breakdown</h2>
                ${breakdown.map((b, i) => `
                    <div style="border-top:${i ? '1px solid var(--line)' : '0'};padding:14px 0">
                        <div style="display:flex;gap:8px;align-items:baseline">
                            <strong>${i + 1}.</strong>
                            <span>${esc(b.question.question_text)}</span>
                            ${b.is_correct ? '<span class="badge green">Correct</span>' : '<span class="badge red">Wrong</span>'}
                        </div>
                        <div style="margin:8px 0 0 26px">
                            ${b.options.map((o) => {
                                const chosen = o.id === b.chosen_option_id;
                                const correct = o.is_correct;
                                return `<div class="small" style="padding:2px 0">
                                    ${correct ? '✅' : chosen ? '❌' : '·'}
                                    <strong>${esc(o.label)}.</strong> ${esc(o.text)}
                                    ${correct ? ' <span class="muted">(correct answer)</span>' : chosen ? ' <span class="muted">(chosen)</span>' : ''}
                                </div>`;
                            }).join('')}
                            ${b.question.explanation ? `<div class="small muted" style="margin-top:6px">ℹ️ ${esc(b.question.explanation)}</div>` : ''}
                        </div>
                    </div>`).join('')}
            </div>`;
    }

    // ------------------------------------------------------------------
    // Generic resource CRUD
    // ------------------------------------------------------------------
    async function resourceListView(main, key) {
        const res = RESOURCES[key];
        if (!res) { location.hash = '#/dashboard'; return; }

        main.innerHTML = topbar(res.label, `<button class="btn" id="new-btn">+ New ${esc(res.label.replace(/s$/, ''))}</button>`)
            + `
            <div class="toolbar">
                <input type="text" id="search-box" placeholder="Search…">
                <div class="spacer"></div>
            </div>
            <div id="resource-table"><div class="muted">Loading…</div></div>`;

        $('#new-btn').addEventListener('click', () => openResourceModal(key, null, () => load(currentPage)));

        const load = async (page = 1) => {
            currentPage = page;
            const params = new URLSearchParams({ page: String(page) });
            const search = $('#search-box')?.value.trim();
            if (search) params.set('search', search);

            const data = await api(`/v1/${key}?` + params);
            const rows = data.data.map((row) => `
                <tr>
                    ${res.columns.map((c) => `<td>${typeof c.path === 'function' ? c.path(row) : esc(getPath(row, c.path) ?? '—')}</td>`).join('')}
                    <td class="actions">
                        <button class="btn secondary small" data-edit="${row.id}">Edit</button>
                        <button class="btn danger small" data-del="${row.id}">Delete</button>
                    </td>
                </tr>`).join('');

            $('#resource-table').innerHTML = data.data.length === 0
                ? '<div class="empty">Nothing here yet — create the first one.</div>'
                : `
                <table class="data">
                    <thead><tr>${res.columns.map((c) => `<th${c.width ? ` style="width:${c.width}"` : ''}>${esc(c.label)}</th>`).join('')}<th style="width:130px"></th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
                ${pager(data, load)}`;
        };

        let currentPage = 1;
        bindPager($('#resource-table'), load);

        $('#search-box').addEventListener('input', debounce(() => load(1)));

        $('#resource-table').addEventListener('click', async (e) => {
            const editId = e.target.closest('[data-edit]')?.dataset.edit;
            const delId = e.target.closest('[data-del]')?.dataset.del;

            if (editId) {
                try {
                    const record = await api(`/v1/${key}/${editId}`);
                    openResourceModal(key, record, () => load(currentPage));
                } catch (err) { toast(err.message, 'err'); }
            }

            if (delId) {
                if (await confirmDialog('This will permanently delete the record.')) {
                    try {
                        await api(`/v1/${key}/${delId}`, { method: 'DELETE' });
                        invalidateRef(key);
                        toast('Deleted.');
                        load(currentPage);
                    } catch (err) { toast(err.message, 'err'); }
                }
            }
        });

        await load(1);
    }

    async function openResourceModal(key, record, onSaved) {
        const res = RESOURCES[key];
        const isEdit = record != null;

        const backdrop = el(`
            <div class="modal-backdrop">
                <div class="modal">
                    <h2>${isEdit ? 'Edit' : 'New'} ${esc(res.label.replace(/s$/, ''))}</h2>
                    <div class="form-errors"></div>
                    <form class="resource-form"><div class="form-grid"></div></form>
                    <div class="modal-actions">
                        <button class="btn secondary" data-act="cancel">Cancel</button>
                        <button class="btn" data-act="save">${isEdit ? 'Save changes' : 'Create'}</button>
                    </div>
                </div>
            </div>`);

        const grid = $('.form-grid', backdrop);
        const inputs = new Map(); // name -> element

        for (const f of res.fields) {
            const row = el(`<div class="form-row ${f.full ? 'full' : ''}" data-field="${f.name}"></div>`);
            const current = isEdit ? getPath(record, f.name) : undefined;

            if (f.type !== 'checkbox') {
                row.appendChild(el(`<label>${esc(f.label)}${f.required ? ' *' : ''}</label>`));
            }

            let input;
            switch (f.type) {
                case 'number':
                    input = el(`<input type="number" name="${f.name}">`);
                    if (current !== undefined && current !== null) input.value = current;
                    else if (!isEdit && f.default !== undefined) input.value = f.default;
                    break;

                case 'textarea':
                    input = el(`<textarea name="${f.name}"></textarea>`);
                    input.value = current ?? '';
                    break;

                case 'select': {
                    input = el(`<select name="${f.name}"></select>`);
                    if (f.choices) {
                        input.innerHTML = `<option value="">— select —</option>`
                            + f.choices.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('');
                        input.value = current ?? (!isEdit && f.default !== undefined ? f.default : '');
                    } else {
                        input.innerHTML = `<option value="">Loading…</option>`;
                        // filled asynchronously below
                    }
                    break;
                }

                case 'checkbox':
                    input = el(`<input type="checkbox" name="${f.name}" style="width:auto">`);
                    input.checked = isEdit ? !!current : !!f.default;
                    row.appendChild(input);
                    row.appendChild(el(`<span style="margin-left:6px">${esc(f.label)}</span>`));
                    break;

                case 'json': {
                    input = el(`<textarea name="${f.name}" placeholder='${esc(f.hint ?? '{}')}'></textarea>`);
                    input.value = current ? JSON.stringify(current, null, 2) : '';
                    break;
                }

                case 'options': {
                    input = buildOptionsEditor(isEdit ? (record.options ?? []) : null);
                    break;
                }

                default: // text
                    input = el(`<input type="text" name="${f.name}">`);
                    input.value = current ?? '';
            }

            if (f.type !== 'checkbox') row.appendChild(input);
            if (f.hint && f.type !== 'json') row.appendChild(el(`<div class="hint">${esc(f.hint)}</div>`));

            grid.appendChild(row);
            inputs.set(f.name, input);
        }

        // Populate resource-backed selects and wire dependent filtering.
        for (const f of res.fields) {
            if (f.type !== 'select' || f.choices) continue;
            const input = inputs.get(f.name);
            try {
                const items = await getRef(f.resource);
                const fill = () => {
                    let list = items;
                    if (f.dependsOn) {
                        const parentVal = inputs.get(f.dependsOn)?.value;
                        list = parentVal ? items.filter((it) => String(getPath(it, f.filterKey)) === parentVal) : [];
                    }
                    const prev = input.value;
                    input.innerHTML = `<option value="">— select —</option>`
                        + list.map((it) => `<option value="${it.id}">${esc(it.name ?? it.title ?? '#' + it.id)}</option>`).join('');
                    if (list.some((it) => String(it.id) === prev)) input.value = prev;
                };
                fill();
                if (f.dependsOn) inputs.get(f.dependsOn).addEventListener('change', fill);
            } catch (err) {
                input.innerHTML = `<option value="">(failed to load)</option>`;
            }
        }

        // Announcements: show grade select only when audience = grade.
        for (const f of res.fields) {
            if (!f.showIf) continue;
            const row = grid.querySelector(`[data-field="${f.name}"]`);
            const apply = () => { row.style.display = f.showIf(collectSimple(inputs)) ? '' : 'none'; };
            res.fields.filter((x) => x.type === 'select' && x.choices).forEach((x) => {
                inputs.get(x.name)?.addEventListener('change', apply);
            });
            apply();
        }

        const submit = async () => {
            let data;
            try {
                data = collectPayload(res, inputs);
            } catch (err) {
                $('.form-errors', backdrop).innerHTML = `<div class="alert error">${esc(err.message)}</div>`;
                return;
            }
            try {
                if (isEdit) await api(`/v1/${key}/${record.id}`, { method: 'PUT', body: data });
                else await api(`/v1/${key}`, { method: 'POST', body: data });
                invalidateRef(key);
                toast(isEdit ? 'Saved.' : 'Created.');
                backdrop.remove();
                onSaved();
            } catch (err) {
                const holder = $('.form-errors', backdrop);
                if (err.errors) {
                    holder.innerHTML = `<div class="alert error"><strong>${esc(err.message)}</strong><ul>${
                        Object.entries(err.errors).map(([f, msgs]) => `<li>${esc(f)}: ${esc(msgs.join(', '))}</li>`).join('')
                    }</ul></div>`;
                } else {
                    holder.innerHTML = `<div class="alert error">${esc(err.message)}</div>`;
                }
                $('.modal', backdrop).scrollIntoView({ block: 'nearest' });
            }
        };

        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop || e.target.closest('[data-act="cancel"]')) backdrop.remove();
            if (e.target.closest('[data-act="save"]')) submit();
        });
        backdrop.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                submit();
            }
        });

        document.body.appendChild(backdrop);
        const first = grid.querySelector('input, select, textarea');
        if (first) first.focus();
    }

    function collectSimple(inputs) {
        const out = {};
        for (const [name, input] of inputs) {
            out[name] = input.type === 'checkbox' ? input.checked : input.value;
        }
        return out;
    }

    function collectPayload(res, inputs) {
        const data = {};
        for (const f of res.fields) {
            const input = inputs.get(f.name);
            if (f.type === 'options') {
                data[f.name] = collectOptions(input);
                continue;
            }
            if (f.type === 'json') {
                const raw = input.value.trim();
                if (raw === '') { data[f.name] = null; continue; }
                try {
                    data[f.name] = JSON.parse(raw);
                } catch {
                    throw Object.assign(new Error('Distribution must be valid JSON.'), { status: 0 });
                }
                continue;
            }
            if (f.type === 'checkbox') { data[f.name] = input.checked; continue; }

            let v = input.value;
            if (v === '') {
                // Required fields: send as-is so the API reports a 422.
                // Optional selects/numbers: null so foreign keys stay clean.
                if (!f.required && (f.type === 'number' || (f.type === 'select' && (f.resource || f.choices)))) v = null;
            } else if (f.type === 'number') {
                v = Number(v);
            }
            data[f.name] = v;
        }
        return data;
    }

    function buildOptionsEditor(existing) {
        const wrap = el(`
            <div class="options-editor" style="border:1px solid var(--line);border-radius:8px;padding:10px">
                <div class="rows"></div>
                <div style="display:flex;gap:8px;margin-top:8px">
                    <button type="button" class="btn secondary small add-row">+ Add option</button>
                    <button type="button" class="btn secondary small tf-preset">True/False preset</button>
                </div>
            </div>`);
        const rowsEl = $('.rows', wrap);

        const addRow = (label = '', text = '', isCorrect = false) => {
            const row = el(`
                <div class="opt-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center">
                    <input type="text" class="opt-label" placeholder="A" maxlength="4" style="width:60px" value="${esc(label)}">
                    <input type="text" class="opt-text" placeholder="Option text" value="${esc(text)}">
                    <label class="small" style="white-space:nowrap"><input type="radio" class="opt-correct" style="width:auto" ${isCorrect ? 'checked' : ''}> correct</label>
                    <button type="button" class="btn secondary small rm-row">✕</button>
                </div>`);
            $('.rm-row', row).addEventListener('click', () => {
                if (rowsEl.children.length > 2) row.remove();
                else toast('A question needs at least 2 options.', 'err');
            });
            $('.opt-correct', row).addEventListener('change', () => {
                // single-correct radio behavior across rows
                rowsEl.querySelectorAll('.opt-correct').forEach((r) => { if (r !== $('.opt-correct', row)) r.checked = false; });
            });
            rowsEl.appendChild(row);
        };

        const labels = ['A', 'B'];
        if (existing && existing.length) {
            existing.forEach((o) => addRow(o.label, o.text, !!o.is_correct));
        } else {
            labels.forEach((l) => addRow(l, '', l === 'A'));
        }

        $('.add-row', wrap).addEventListener('click', () => {
            const used = new Set([...rowsEl.querySelectorAll('.opt-label')].map((i) => i.value));
            const next = ['A', 'B', 'C', 'D', 'E', 'F'].find((l) => !used.has(l)) ?? 'X';
            addRow(next, '', false);
        });
        $('.tf-preset', wrap).addEventListener('click', () => {
            rowsEl.innerHTML = '';
            addRow('A', 'True', true);
            addRow('B', 'False', false);
        });

        return wrap;
    }

    function collectOptions(editor) {
        const rows = [...editor.querySelectorAll('.opt-row')];
        const options = rows.map((row) => ({
            label: $('.opt-label', row).value.trim(),
            text: $('.opt-text', row).value,
            is_correct: $('.opt-correct', row).checked,
        }));

        if (options.length < 2) throw Object.assign(new Error('A question needs at least 2 options.'), { status: 0 });
        if (options.some((o) => !o.label || !o.text.trim())) throw Object.assign(new Error('Every option needs a label and text.'), { status: 0 });
        if (options.filter((o) => o.is_correct).length !== 1) throw Object.assign(new Error('Exactly one option must be marked correct.'), { status: 0 });
        return options;
    }

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------
    async function boot() {
        if (!state.token) { loginView(); return; }
        try {
            state.user = await api('/me');
            renderShell();
            navigate();
        } catch {
            /* 401 already switched to loginView */
        }
    }

    window.addEventListener('hashchange', navigate);
    boot();
})();

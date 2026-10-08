<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') · {{ config('app.name', 'Laravel') }}</title>
    <script>try { var savedTheme = localStorage.getItem('admin-theme'); if (savedTheme) document.documentElement.dataset.theme = savedTheme; } catch (e) {}</script>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin-modern.css') }}">
</head>
<body>
<div class="app">
    <aside class="app-sidebar" id="sidebar">
        <div class="app-sidebar__brand">
            <svg width="28" height="28" viewBox="0 0 28 26" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="28" height="26" rx="6" fill="#2563eb"/>
                <path d="M9 7 L14 7 L14 13 L9 13 Z" fill="#fff"/>
                <rect x="10.6" y="10.6" width="3" height="5" rx="1.4" fill="#fff"/>
                <rect x="14" y="8" width="3" height="9" rx="1.4" fill="#fff"/>
                <rect x="17.4" y="10.6" width="3" height="5" rx="1.4" fill="#fff"/>
                <path d="M14 5 L14 9" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
                <path d="M9 11 L14 15" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
            <div>
                <div class="app-sidebar__brand-text">Ethiopian Exam Prep</div>
                <div class="app-sidebar__brand-sub">Admin Dashboard</div>
            </div>
        </div>
        <nav class="app-sidebar__nav">
            <div class="nav-section">Overview</div>
            <a class="nav-link{{ request()->is('/') || request()->is('admin') || request()->is('admin/dashboard') ? ' active' : '' }}" href="{{ route('admin') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><path d="M3 17l4-4 4 4 8-8"/><path d="M16 7h5v5"/></svg>
                Dashboard
            </a>
            <div class="nav-section">Students</div>
            <a class="nav-link{{ request()->is('admin/students') || request()->is('admin/students/*') ? ' active' : '' }}" href="{{ route('admin.students') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Students
            </a>
            <a class="nav-link{{ request()->is('admin/results') || request()->is('admin/results/*') ? ' active' : '' }}" href="{{ route('admin.results') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="12" x2="8" y2="12"/><line x1="16" y1="16" x2="8" y2="16"/></svg>
                Results
            </a>
            <div class="nav-section">Learning content</div>
            <a class="nav-link{{ request()->is('admin/grades') || request()->is('admin/grades/*') ? ' active' : '' }}" href="{{ route('admin.grades') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><path d="M5 12h14"/></svg>
                Grades
            </a>
            <a class="nav-link{{ request()->is('admin/subjects') || request()->is('admin/subjects/*') ? ' active' : '' }}" href="{{ route('admin.subjects') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 3h20v7H6a2 2 0 0 1 0-4h12a2 2 0 0 1 0 4Z"/><path d="M6 22V10"/><path d="M10 22V10"/><path d="M14 22V10"/><path d="M18 22V10"/></svg>
                Subjects
            </a>
            <a class="nav-link{{ request()->is('admin/chapters') || request()->is('admin/chapters/*') ? ' active' : '' }}" href="{{ route('admin.chapters') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6 19.5A2.5 2.5 0 0 0 8.5 17H4"/><path d="M4 12.5A2.5 2.5 0 0 1 6.5 10H20"/><path d="M6 12.5A2.5 2.5 0 0 0 8.5 10H4"/><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20"/><path d="M6 5.5A2.5 2.5 0 0 0 8.5 3H4"/></svg>
                Chapters
            </a>
            <a class="nav-link{{ request()->is('admin/topics') || request()->is('admin/topics/*') ? ' active' : '' }}" href="{{ route('admin.topics') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22V8"/><path d="M12 8L8 16l4 8 4-8z"/><circle cx="12" cy="2" r="1"/></svg>
                Topics
            </a>
            <a class="nav-link{{ request()->is('admin/notes') || request()->is('admin/notes/*') ? ' active' : '' }}" href="{{ route('admin.notes') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6 19.5A2.5 2.5 0 0 0 8.5 17H4"/><path d="M4 12.5A2.5 2.5 0 0 1 6.5 10H20"/><path d="M6 12.5A2.5 2.5 0 0 0 8.5 10H4"/><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20"/><path d="M6 5.5A2.5 2.5 0 0 0 8.5 3H4"/></svg>
                Notes
            </a>
            <a class="nav-link{{ request()->is('admin/questions') || request()->is('admin/questions/*') ? ' active' : '' }}" href="{{ route('admin.questions') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
                Questions
            </a>
            <a class="nav-link{{ request()->is('admin/import-questions') ? ' active' : '' }}" href="{{ route('admin.import') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 15V3m0 0L7 8m5-5 5 5"/><path d="M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>
                Import questions
            </a>
            <a class="nav-link{{ request()->is('admin/exams') || request()->is('admin/exams/*') ? ' active' : '' }}" href="{{ route('admin.exams') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>
                Exams
            </a>
            <div class="nav-section">Billing</div>
            <a class="nav-link{{ request()->is('admin/payments') ? ' active' : '' }}" href="{{ route('admin.payments') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></svg>
                Payments
            </a>
            <div class="nav-section">Communication</div>
            <a class="nav-link{{ request()->is('admin/announcements') || request()->is('admin/announcements/*') ? ' active' : '' }}" href="{{ route('admin.announcements') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20.5V4"/><path d="M12 4h.01"/><path d="M4.93 4.93l14.14 14.14"/><path d="M19.07 4.93l-14.14 14.14"/></svg>
                Notices
            </a>
        </nav>
        <div class="app-sidebar__bottom">
            <div class="sidebar-footnote">Ethiopian Exam Prep<br><span>Content and student management</span></div>
        </div>
    </aside>

    <div class="app-page">
        <header class="app-header">
            <button type="button" class="app-header__toggle" id="menu-toggle" aria-label="Toggle navigation">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="app-header__heading">
                <h1 class="app-header__title">@yield('page-title', 'Admin')</h1>
                @hasSection('page-description')<p class="app-header__description">@yield('page-description')</p>@endif
            </div>
            <div class="app-header__actions">
                <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Switch color theme" title="Switch color theme">
                    <svg class="theme-icon theme-icon--light" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
                    <svg class="theme-icon theme-icon--dark" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 15.5A8.5 8.5 0 0 1 8.5 3.5a8.6 8.6 0 1 0 12 12Z"/></svg>
                </button>
                @auth
                <div class="user-chip">
                    <span class="admin-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>
                    <span>{{ Auth::user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost btn-sm">Log out</button>
                    </form>
                </div>
                @endauth
            </div>
        </header>
        <main class="content">
            @yield('content')
        </main>
    </div>
</div>

<div class="toast-holder" id="toast-host" aria-live="polite"></div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>

<script src="{{ asset('js/api.js') }}"></script>
<script>
(function () {
    var toggle = document.getElementById('menu-toggle');
    var overlay = document.getElementById('sidebar-overlay');
    if (toggle) {
        toggle.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-open');
        });
    }
    if (overlay) {
        overlay.addEventListener('click', function () {
            document.body.classList.remove('sidebar-open');
        });
    }
    var themeButton = document.getElementById('theme-toggle');
    var savedTheme = localStorage.getItem('admin-theme');
    if (savedTheme) document.documentElement.dataset.theme = savedTheme;
    if (themeButton) {
        themeButton.addEventListener('click', function () {
            var nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = nextTheme;
            localStorage.setItem('admin-theme', nextTheme);
        });
    }
})();
</script>
@stack('scripts')
</body>
</html>

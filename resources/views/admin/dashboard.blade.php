@extends('admin.layout')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-description', 'Manage your learning content and see how students are using the bot.')

@section('content')
<section class="dashboard-welcome">
    <div>
        <span class="eyebrow">ADMIN OVERVIEW</span>
        <h2>Welcome back, {{ Auth::user()->name ?? 'Admin' }}</h2>
        <p>Keep courses organized, publish new learning material, and manage student access.</p>
    </div>
    <a class="btn btn-primary" href="/admin/questions?action=create">＋ Add a question</a>
</section>

<section class="dashboard-stats" aria-label="Platform summary">
    <a class="dashboard-stat" href="/admin/students">
        <span class="dashboard-stat__label">Registered students</span>
        <strong id="stat-students">—</strong>
        <small id="stat-students-hint">Loading…</small>
    </a>
    <a class="dashboard-stat dashboard-stat--accent" href="/admin/students">
        <span class="dashboard-stat__label">Activated accounts</span>
        <strong id="stat-activated">—</strong>
        <small>Currently active subscriptions</small>
    </a>
    <a class="dashboard-stat" href="/admin/questions">
        <span class="dashboard-stat__label">Questions answered</span>
        <strong id="stat-answered">—</strong>
        <small id="stat-answered-hint">Loading…</small>
    </a>
    <a class="dashboard-stat" href="/admin/results">
        <span class="dashboard-stat__label">Exams completed</span>
        <strong id="stat-exams">—</strong>
        <small id="stat-exams-hint">Loading…</small>
    </a>
    <a class="dashboard-stat" href="/admin/students">
        <span class="dashboard-stat__label">Invite link registrations</span>
        <strong id="stat-referrals">—</strong>
        <small id="stat-referrals-hint">Loading…</small>
    </a>
</section>

<section class="dashboard-section">
    <div class="section-heading">
        <div><h2>Quick actions</h2><p>Start common admin tasks from here.</p></div>
    </div>
    <div class="quick-actions">
        <a href="/admin/grades?action=create"><span class="quick-action__icon">＋</span><span><b>Add a grade</b><small>Create a grade students can choose.</small></span><span class="quick-action__arrow">›</span></a>
        <a href="/admin/subjects?action=create"><span class="quick-action__icon">▤</span><span><b>Add a subject</b><small>Organize a course under a grade.</small></span><span class="quick-action__arrow">›</span></a>
        <a href="/admin/chapters?action=create"><span class="quick-action__icon">▣</span><span><b>Add a chapter</b><small>Set its number and access rule.</small></span><span class="quick-action__arrow">›</span></a>
        <a href="/admin/students"><span class="quick-action__icon">♙</span><span><b>Manage students</b><small>Review accounts and activations.</small></span><span class="quick-action__arrow">›</span></a>
    </div>
</section>

<section class="dashboard-section">
    <div class="section-heading">
        <div><h2>Learning content</h2><p>Open a section to review, add, or update content shown in Telegram.</p></div>
    </div>
    <div id="dashboard-inventory" class="inventory-grid"></div>
</section>

<section class="dashboard-section">
    <div class="section-heading">
        <div><h2>Students and activity</h2><p>Review registered students and completed exam attempts.</p></div>
    </div>
    <div id="dashboard-operations" class="inventory-grid"></div>
</section>

<section class="dashboard-section dashboard-insights">
    <div class="insight-panel">
        <div class="section-heading"><div><h2>Popular subjects</h2><p>Based on student answers.</p></div></div>
        <div id="popular-subjects" class="insight-list"></div>
    </div>
    <div class="insight-panel">
        <div class="section-heading"><div><h2>Questions students find difficult</h2><p>Questions with at least three attempts.</p></div></div>
        <div id="difficult-questions" class="insight-list"></div>
    </div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    httpFetch('/api/dashboard')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var s = data.students || {};
            document.getElementById('stat-students').textContent = s.total ?? '—';
            document.getElementById('stat-students-hint').textContent = s.new_this_week ? s.new_this_week + ' new this week' : '';
            document.getElementById('stat-activated').textContent = s.activated ?? '—';
            var referrals = data.referrals || {};
            document.getElementById('stat-referrals').textContent = referrals.total ?? '—';
            document.getElementById('stat-referrals-hint').textContent = (referrals.qualified ?? 0) + ' joined the channel and qualified';

            var a = data.activity || {};
            document.getElementById('stat-answered').textContent = a.questions_answered ?? '—';
            var acc = a.accuracy;
            document.getElementById('stat-answered-hint').textContent = acc != null ? acc + '% average accuracy' : 'Loading…';

            var ex = (a.mock_exams_completed || 0) + (a.chapter_tests_completed || 0);
            document.getElementById('stat-exams').textContent = ex;
            document.getElementById('stat-exams-hint').textContent = (a.mock_exams_completed || 0) + ' mock exams · ' + (a.chapter_tests_completed || 0) + ' chapter tests';

            var inventory = data.inventory || {};
            var inventoryCards = [
                ['grades', 'Grades', '/admin/grades', 'visible in Telegram'],
                ['subjects', 'Subjects', '/admin/subjects', 'visible in Telegram'],
                ['chapters', 'Chapters', '/admin/chapters', 'visible in Telegram'],
                ['notes', 'Notes', '/admin/notes', 'reachable in Telegram'],
                ['questions', 'Questions', '/admin/questions', 'usable in Telegram'],
                ['topics', 'Topics', '/admin/topics', 'under active chapters'],
                ['exams', 'Exams', '/admin/exams', 'active records']
            ];
            var operationsCards = [
                ['students', 'Students', '/admin/students', 'registered accounts'],
                ['results', 'Results', '/admin/results', 'completed attempts']
            ];
            function renderInventory(hostId, cards) {
              var inventoryHost = document.getElementById(hostId);
              inventoryHost.replaceChildren();
              cards.forEach(function (entry) {
                var item = inventory[entry[0]] || {};
                var link = document.createElement('a');
                link.href = entry[2];
                link.className = 'inventory-card';

                var label = document.createElement('div');
                label.className = 'inventory-card__name';
                label.textContent = entry[1];
                link.appendChild(label);

                var count = document.createElement('div');
                count.className = 'inventory-card__count';
                count.textContent = item.total ?? '—';
                link.appendChild(count);

                var status = document.createElement('div');
                status.className = 'inventory-card__available';
                status.textContent = (item.available ?? '—') + ' ' + entry[3];
                link.appendChild(status);

                var description = document.createElement('div');
                description.className = 'inventory-card__description';
                description.textContent = item.description || '';
                link.appendChild(description);

                inventoryHost.appendChild(link);
              });
            }
            renderInventory('dashboard-inventory', inventoryCards);
            renderInventory('dashboard-operations', operationsCards);

            var pop = document.getElementById('popular-subjects');
            var popular = data.popular_subjects || [];
            pop.innerHTML = popular.length ? popular.map(function (s) {
                var acc = s.accuracy;
                return '<div class="flex items-center justify-between text-sm">' +
                    '<span class="text-gray-700">' + s.name + '</span>' +
                    '<span class="text-gray-500">' + s.answers + ' answers · ' + (acc != null ? acc + '%' : '—') + '</span>' +
                    '</div>';
            }).join('') : '<p class="empty-state">Student answer data will appear here.</p>';

            var diff = document.getElementById('difficult-questions');
            var difficult = data.difficult_questions || [];
            diff.innerHTML = difficult.length ? difficult.map(function (q) {
                var acc = q.accuracy;
                return '<div class="text-sm">' +
                    '<div class="text-gray-700">' + (q.question_text.length > 110 ? q.question_text.slice(0, 110) + '…' : q.question_text) + '</div>' +
                    '<div class="text-xs text-red-500">' + (acc != null ? acc + '% correct over ' + q.attempts + ' attempts' : '—') + '</div>' +
                    '</div>';
            }).join('') : '<p class="empty-state">No questions have enough attempts yet.</p>';
        })
        .catch(function (e) {
            document.getElementById('popular-subjects').innerHTML = '<div class="text-sm text-gray-500">' + e.message + '</div>';
        });
});
</script>
@endpush

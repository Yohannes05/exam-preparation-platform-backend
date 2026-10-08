@extends('admin.layout')

@section('title', 'Results')
@section('page-title', 'Results')
@section('page-description', 'Review completed chapter tests and mock exam results.')

@section('content')
<div class="resource-toolbar">
    <div class="resource-toolbar__filters">
        <select id="type-filter" class="input max-w-[200px]">
            <option value="">All exam types</option>
            <option value="chapter_test">Chapter tests</option>
            <option value="mock">Mock exams</option>
        </select>
        <span class="text-xs text-gray-500" id="result-count">0 attempts</span>
    </div>
</div>

<div class="admin-notice results-hint" id="results-hint" hidden></div>

<div class="table-panel">
<div class="table-panel__heading"><div><h2>Exam results</h2><p>Open an attempt to see the student’s answers and score.</p></div></div>
<div class="table-wrap">
    <table id="results-table">
        <thead>
            <tr>
                <th>Student</th>
                <th>Exam</th>
                <th>Type</th>
                <th>Score</th>
                <th>Result</th>
                <th>Completed</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
</div>

<div class="pagination" id="pagination"></div>

<div id="attempt-detail"></div>

<section class="table-panel practice-results-panel">
    <div class="table-panel__heading"><div><h2>Practice answers</h2><p>Recent individual practice questions answered in the bot.</p></div><span class="record-count" id="practice-count">Loading…</span></div>
    <div class="table-wrap"><table id="practice-table"><thead><tr><th>Student</th><th>Subject / chapter</th><th>Question</th><th>Answer</th><th>Outcome</th><th>Date</th></tr></thead><tbody><tr><td colspan="6" class="empty">Loading practice answers…</td></tr></tbody></table></div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var state = {
        rows: [],
        meta: { page: 1, last: 1, total: 0 },
        page: 1,
        type: '',
        loading: false,
        error: '',
        detail: null
    };

    var typeFilter = document.getElementById('type-filter');

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[c];
        });
    }

    function load(page) {
        state.loading = true;
        state.error = '';
        state.page = page || state.page;
        var params = { page: state.page, per_page: 15 };
        if (state.type) params.type = state.type;
        httpFetch('/api/results?' + new URLSearchParams(params))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.rows = data.data;
                state.meta = { page: data.current_page, last: data.last_page, total: data.total };
                document.getElementById('result-count').textContent = state.meta.total + ' attempts';
                var hint = document.getElementById('results-hint');
                hint.hidden = state.meta.total > 0;
                hint.textContent = state.meta.total === 0 ? 'No completed chapter tests or mock exams yet. Practice answers are listed below; an exam result appears here after a student completes a chapter test or mock exam in the bot.' : '';
            })
            .catch(function (e) {
                state.error = e.message;
            })
            .finally(function () {
                state.loading = false;
                renderTable();
                renderPagination();
            });
    }

    function loadPractice() {
        var tbody = document.querySelector('#practice-table tbody');
        httpFetch('/api/results/practice?per_page=15')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var rows = data.data || [];
                document.getElementById('practice-count').textContent = data.total + (data.total === 1 ? ' answer' : ' answers');
                if (!rows.length) {
                    tbody.innerHTML = '<tr><td colspan="6" class="empty">No practice answers have been recorded yet.</td></tr>';
                    return;
                }
                tbody.innerHTML = rows.map(function (item) {
                    var student = item.student || {};
                    var question = item.question || {};
                    var option = (question.options || []).find(function (choice) { return choice.id === item.chosen_option_id; });
                    var chapter = question.chapter ? 'Chapter ' + (question.chapter.order || '—') + ' · ' + question.chapter.title : '—';
                    var outcome = item.is_correct ? '<span class="badge badge-success">Correct</span>' : '<span class="badge badge-danger">Incorrect</span>';
                    return '<tr><td><b>' + escapeHtml(student.display_name || student.first_name || ('Student #' + item.student_id)) + '</b></td>' +
                        '<td>' + escapeHtml([question.subject && question.subject.name, chapter].filter(Boolean).join(' · ')) + '</td>' +
                        '<td class="result-question-cell">' + escapeHtml(question.question_text || 'Question unavailable') + '</td>' +
                        '<td>' + escapeHtml(option ? option.label + ') ' + option.text : 'No answer') + '</td><td>' + outcome + '</td>' +
                        '<td>' + escapeHtml(item.answered_at ? new Date(item.answered_at).toLocaleString() : '—') + '</td></tr>';
                }).join('');
            })
            .catch(function (e) {
                tbody.innerHTML = '<tr><td colspan="6" class="empty">' + escapeHtml(e.message) + '</td></tr>';
                document.getElementById('practice-count').textContent = 'Unavailable';
            });
    }

    function renderTable() {
        var tbody = document.querySelector('#results-table tbody');
        if (state.loading) {
            tbody.innerHTML = '<tr><td colspan="7"><div class="empty">Loading…</div></td></tr>';
            return;
        }
        if (state.error) {
            tbody.innerHTML = '<tr><td colspan="7"><div class="empty">' + escapeHtml(state.error) + '</div></td></tr>';
            return;
        }
        if (state.rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7"><div class="empty">No completed attempts yet.</div></td></tr>';
            return;
        }
        tbody.innerHTML = state.rows.map(function (a) {
            var type = a.exam ? (a.exam.type === 'mock' ? 'Mock' : 'Test') : '—';
            var score = a.score + '%';
            var passed = a.passed ?
                '<span class="badge badge-success">Passed</span>' :
                '<span class="badge badge-danger">Failed</span>';
            var date = a.completed_at ? new Date(a.completed_at).toLocaleString() : '—';
            return '<tr class="row-' + a.id + '">' +
                '<td class="font-medium text-gray-900">' + escapeHtml(a.student ? (a.student.display_name || a.student.first_name || '#'+a.student_id) : '—') + '</td>' +
                '<td class="font-medium">' + escapeHtml(a.exam ? a.exam.title : '—') + '</td>' +
                '<td class="text-gray-600">' + type + '</td>' +
                '<td class="font-medium">' + score + '</td>' +
                '<td>' + passed + '</td>' +
                '<td class="text-gray-500">' + escapeHtml(date) + '</td>' +
                '<td>' +
                '<button class="btn btn-ghost btn-sm breakdown-btn" data-id="' + a.id + '">Breakdown</button>' +
                '</td></tr>';
        }).join('');
        tbody.querySelectorAll('.breakdown-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { openDetail(parseInt(btn.dataset.id, 10)); });
        });
    }

    function renderPagination() {
        var pg = document.getElementById('pagination');
        pg.innerHTML = '';
        if (state.meta.last > 1) {
            var prev = document.createElement('button');
            prev.className = 'btn btn-secondary btn-sm';
            prev.textContent = '← Previous';
            prev.disabled = state.page <= 1;
            prev.addEventListener('click', function () { state.page--; load(); });
            pg.appendChild(prev);
            var count = document.createElement('span');
            count.textContent = 'Page ' + state.meta.page + ' of ' + state.meta.last;
            pg.appendChild(count);
            var next = document.createElement('button');
            next.className = 'btn btn-secondary btn-sm';
            next.textContent = 'Next →';
            next.disabled = state.page >= state.meta.last;
            next.addEventListener('click', function () { state.page++; load(); });
            pg.appendChild(next);
        }
    }

    function openDetail(id) {
        state.error = '';
        httpFetch('/api/results/' + id)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.detail = data;
                showDetail(data);
            })
            .catch(function (e) { showToast('error', e.message); });
    }

    function showDetail(data) {
        var wrap = document.createElement('div');
        wrap.className = 'modal-backdrop';
        wrap.innerHTML =
            '<div class="modal">' +
            '<div class="modal-head"><h3>' + escapeHtml(data.attempt.exam ? data.attempt.exam.title : 'Attempt') + '</h3>' +
            '<button class="btn-ghost btn-sm" id="detail-close">✕</button></div>' +
            '<div class="modal-body">' +
            '<p class="text-sm text-gray-500">' +
            escapeHtml(data.attempt.student ? (data.attempt.student.first_name || 'Student #' + data.attempt.student_id) : 'Student') +
            ' · score ' + data.attempt.score + '% · ' + data.attempt.correct_answers + '/' + data.attempt.total_questions +
            '</p>' +
            '<div class="max-h-[60vh] space-y-4 overflow-y-auto">' +
            (data.breakdown && data.breakdown.length ? ''
                : '<p class="text-sm text-gray-400">No per-question data for this attempt.</p>') +
            (data.breakdown || []).map(function (item, i) {
                return '<div class="rounded-md border p-4 ' + (item.is_correct ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50') + '">' +
                    '<div class="mb-2 text-sm font-medium text-gray-900">' + (i + 1) + '. ' + escapeHtml(item.question.question_text) + '</div>' +
                    '<div class="space-y-1 text-sm">' +
                    (item.options || []).map(function (opt) {
                        var chosen = item.chosen_option_id === opt.id;
                        var cls = opt.is_correct
                            ? 'font-medium text-green-700'
                            : chosen
                                ? 'text-red-600 line-through'
                                : 'text-gray-500';
                        return '<div class="' + cls + '">' + escapeHtml(opt.label) + ') ' + escapeHtml(opt.text) +
                            (opt.is_correct ? ' ✓' : '') +
                            (chosen && !opt.is_correct ? ' — student answer' : '') + '</div>';
                    }).join('') +
                    '</div>' +
                    (item.question.explanation ? '<p class="mt-2 text-xs text-gray-500">💡 ' + escapeHtml(item.question.explanation) + '</p>' : '') +
                    '</div>';
            }).join('') +
            '</div>' +
            '</div>' +
            '<div class="modal-foot"><button class="btn btn-secondary" id="detail-close2">Close</button></div>' +
            '</div>';
        document.body.appendChild(wrap);

        document.getElementById('detail-close').addEventListener('click', closeDetail);
        document.getElementById('detail-close2').addEventListener('click', closeDetail);
        wrap.addEventListener('click', function (event) {
            if (event.target === wrap) closeDetail();
        });

        function closeDetail() {
            state.detail = null;
            document.body.removeChild(wrap);
        }
    }

    typeFilter.addEventListener('change', function () {
        state.type = this.value;
        state.page = 1;
        load(1);
    });

    load(1);
    loadPractice();
});
</script>
@endpush

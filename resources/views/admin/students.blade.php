@extends('admin.layout')

@section('title', 'Students')
@section('page-title', 'Students')
@section('page-description', 'Review students, monitor progress, and manage account activation.')

@section('content')
<div class="resource-toolbar">
    <div class="resource-search">
        <span aria-hidden="true">⌕</span>
        <input type="search" placeholder="Search name or @username…" id="search" class="input w-full">
    </div>
    <div class="resource-toolbar__filters"><span class="record-count" id="student-count">Loading students…</span></div>
</div>

<div class="table-panel">
<div class="table-panel__heading"><div><h2>Student accounts</h2><p>Activation changes apply immediately in the bot.</p></div></div>
<div class="table-wrap">
    <table id="students-table">
        <thead>
            <tr>
                <th>Student</th>
                <th>Grade</th>
                <th>Answered</th>
                <th>Accuracy</th>
                <th>Telegram</th>
                <th>Invited</th>
                <th>Activation</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
</div>

<div class="pagination" id="pagination"></div>

<div id="student-detail"></div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var state = {
        rows: [],
        meta: { page: 1, last: 1, total: 0 },
        page: 1,
        search: '',
        editing: null,
        form: {},
        loading: false,
        error: '',
        grades: [],
        modal: null
    };

    var searchInput = document.getElementById('search');

    function loadGrades() {
        return httpFetch('/api/v1/grades?per_page=100')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.grades = data.data || [];
                if (state.editing !== null) renderModalGradeSelect();
            })
            .catch(function () {});
    }

    function load(page) {
        state.loading = true;
        state.error = '';
        var params = { page: page || state.page, per_page: 15 };
        if (state.search) params.search = state.search;
        httpFetch('/api/students?' + new URLSearchParams(params))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.rows = data.data;
                state.meta = { page: data.current_page, last: data.last_page, total: data.total };
                document.getElementById('student-count').textContent = state.meta.total + (state.meta.total === 1 ? ' student' : ' students') + ' · refreshes every 15 seconds';
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

    function renderTable() {
        var tbody = document.querySelector('#students-table tbody');
        if (state.loading) {
            tbody.innerHTML = '<tr><td colspan="8"><div class="empty">Loading…</div></td></tr>';
            return;
        }
        if (state.error) {
            tbody.innerHTML = '<tr><td colspan="8"><div class="empty">' + state.error + '</div></td></tr>';
            return;
        }
        if (state.rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8"><div class="empty">No students yet — they register through the Telegram bot.</div></td></tr>';
            return;
        }
        tbody.innerHTML = state.rows.map(function (s) {
            var grade = s.grade ? s.grade.name : '—';
            var acc = s.accuracy == null ? '<span class="text-gray-400">—</span>' :
                '<span class="' + (s.accuracy >= 70 ? 'text-green-600' : s.accuracy >= 40 ? 'text-yellow-600' : 'text-red-600') + '">' + s.accuracy + '%</span>';
            var tele = s.telegram_username ? '@' + s.telegram_username : String(s.telegram_id);
            var activated = s.activated_until && new Date(s.activated_until) > new Date();
            var activation = activated
                ? '<span class="badge badge-success">Activated</span><small class="activation-date">Until ' + new Date(s.activated_until).toLocaleDateString() + '</small>'
                : '<span class="badge badge-gray">Free account</span>';
            return '<tr class="row-' + s.id + '">' +
                '<td class="font-medium text-gray-900">' + (s.first_name || s.last_name ? (s.first_name + ' ' + s.last_name).trim() : 'Student #' + s.id) + '</td>' +
                '<td>' + grade + '</td>' +
                '<td>' + (s.question_attempts_count || s.answered || 0) + '</td>' +
                '<td>' + acc + '</td>' +
                '<td class="text-gray-500">' + tele + '</td>' +
                '<td><b>' + (s.referrals_count || 0) + '</b><small class="activation-date">' + (s.qualified_referrals_count || 0) + ' qualified</small></td>' +
                '<td class="activation-cell">' + activation + '</td>' +
                '<td><div class="student-actions">' +
                '<button class="btn btn-primary btn-sm activate-btn" data-id="' + s.id + '" title="' + (activated ? 'Extend activation by 30 days' : 'Activate for 30 days') + '">' + (activated ? '＋ Extend 30 days' : 'Activate 30 days') + '</button>' +
                (activated ? '<button class="btn btn-danger btn-sm revoke-btn" data-id="' + s.id + '">Revoke</button>' : '') +
                '<button class="btn btn-secondary btn-sm edit-btn" data-id="' + s.id + '">Edit</button>' +
                '</div></td></tr>';
        }).join('');
        tbody.querySelectorAll('.edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { openEdit(parseInt(btn.dataset.id, 10)); });
        });
        tbody.querySelectorAll('.activate-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { activateStudent(parseInt(btn.dataset.id, 10)); });
        });
        tbody.querySelectorAll('.revoke-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { revokeStudent(parseInt(btn.dataset.id, 10)); });
        });
    }

    function activateStudent(id) {
        httpFetch('/api/students/' + id, {
            method: 'PATCH',
            body: JSON.stringify({ activate_for_days: 30 })
        }).then(function () {
            load();
            showToast('success', 'Student activated for 30 days');
        }).catch(function (e) { showToast('error', e.message); });
    }

    function revokeStudent(id) {
        if (!window.confirm('Revoke this student’s current activation?')) return;
        httpFetch('/api/students/' + id, {
            method: 'PATCH',
            body: JSON.stringify({ revoke_activation: true })
        }).then(function () {
            load();
            showToast('success', 'Student activation revoked');
        }).catch(function (e) { showToast('error', e.message); });
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

    function openCreate() {
        state.editing = null;
        state.form = { grade_id: '', is_active: true };
        state.error = '';
        showModal();
    }

    function openEdit(id) {
        state.editing = id;
        state.error = '';
        httpFetch('/api/students/' + id)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var student = data.student || data;
                state.form = { grade_id: student.grade_id || '', is_active: student.is_active };
                showModal();
            })
            .catch(function (e) { state.error = e.message; });
    }

    function removeRow(id) {
        if (!window.confirm('Delete this student?')) return;
        httpFetch('/api/students/' + id, { method: 'DELETE' })
            .then(function (r) { return r.json(); })
            .then(function () { load(); showToast('success', 'Deleted'); })
            .catch(function (e) { state.error = e.message; });
    }

    function showModal() {
        var wrap = document.createElement('div');
        wrap.className = 'modal-backdrop';
        wrap.innerHTML =
            '<div class="modal">' +
            '<div class="modal-head"><h3>' + (state.editing === null ? 'New student' : 'Edit student') + '</h3>' +
            '<button type="button" class="btn-ghost btn-sm" id="modal-close" aria-label="Close dialog">✕</button></div>' +
            '<form class="modal-body" id="modal-form">' +
            '<div class="form-grid form-grid--2">' +
            '<div class="form-group"><label>Grade</label>' +
            '<select class="input" id="f-grade_id"><option value="">— select —</option></select></div>' +
            '<label class="flex items-center gap-2 text-sm text-gray-700">' +
            '<input type="checkbox" id="f-is_active" class="rounded border-gray-300"> Active' +
            '</label>' +
            '</div>' +
            (state.error ? '<div class="error" style="margin-top:12px;">' + state.error + '</div>' : '') +
            '<div class="modal-foot">' +
            '<button type="button" class="btn btn-secondary" id="modal-close2">Cancel</button>' +
            '<button type="submit" class="btn btn-primary" id="modal-save">Save</button>' +
            '</div>' +
            '</form>' +
            '</div>';
        document.body.appendChild(wrap);
        state.modal = wrap;

        document.getElementById('modal-close').addEventListener('click', closeModal);
        document.getElementById('modal-close2').addEventListener('click', closeModal);
        document.getElementById('modal-form').addEventListener('submit', save);

        var sel = document.getElementById('f-grade_id');
        state.grades.forEach(function (g) {
            var opt = document.createElement('option');
            opt.value = g.id;
            opt.textContent = g.name;
            sel.appendChild(opt);
        });
        var chk = document.getElementById('f-is_active');
        chk.checked = state.form.is_active === true;
    }

    function save(e) {
        e.preventDefault();
        state.error = '';
        var payload = {
            grade_id: document.getElementById('f-grade_id').value,
            is_active: document.getElementById('f-is_active').checked
        };
        var wasEditing = state.editing !== null;
        var url = '/api/students/' + state.editing;
        httpFetch(url, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function () {
            closeModal();
            load();
            showToast('success', wasEditing ? 'Student updated' : 'Student created');
        })
        .catch(function (e) {
            state.error = e.message;
            showToast('error', e.message);
        });
    }

    function closeModal() {
        state.editing = null;
        state.form = {};
        state.error = '';
        if (state.modal && state.modal.parentNode) state.modal.parentNode.removeChild(state.modal);
        state.modal = null;
    }

    function showToast(type, message) {
        var holder = document.querySelector('.toast-holder');
        if (!holder) return;
        var t = document.createElement('div');
        t.className = 'toast toast-' + (type || 'info');
        t.textContent = message || 'Done';
        holder.appendChild(t);
        setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 4000);
    }

    var searchTimer;
    searchInput.addEventListener('input', function () {
        state.search = this.value;
        state.page = 1;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { load(1); }, 220);
    });

    window.setInterval(function () {
        if (document.visibilityState === 'visible' && state.editing === null && !state.loading) {
            load(state.page);
        }
    }, 15000);

    document.addEventListener('click', function (e) {
        if (state.modal && e.target === state.modal) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && state.modal) closeModal();
    });

    var logoutBtns = document.querySelectorAll('#logout-btn, #logout-btn2');
    logoutBtns.forEach(function (b) {
        b.addEventListener('click', function () {
            httpFetch('/api/logout', { method: 'POST' })
                .then(function () { localStorage.removeItem('exam_admin_token'); localStorage.removeItem('exam_admin_user'); window.location.href = '/login'; })
                .catch(function () { window.location.href = '/login'; });
        });
    });

    loadGrades().then(function () { load(1); });
});
</script>
@endpush

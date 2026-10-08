@extends('admin.layout')

@section('title', 'Payments')
@section('page-title', 'Payments')
@section('page-description', 'Review receipt photos and activate accounts after approval.')

@section('content')
<div class="admin-notice admin-notice--info">
    Receipts sent directly to <b>@J0519</b> do not appear here. Activate those students from <a href="{{ route('admin.students') }}">Students</a>. Receipts sent to the bot appear in this review list.
</div>

<div class="resource-toolbar">
    <div class="resource-toolbar__filters">
        <label class="field-label" for="payment-filter">Status</label>
        <select id="payment-filter" class="input">
            <option value="">All receipts</option><option value="pending">Needs review</option><option value="approved">Approved</option><option value="rejected">Rejected</option>
        </select>
        <span class="record-count" id="payment-count">Loading receipts…</span>
    </div>
</div>

<div class="payment-layout">
    <section class="table-panel">
        <div class="table-panel__heading"><div><h2>Payment receipts</h2><p>Select a receipt to review its details.</p></div></div>
        <div class="table-wrap">
            <table id="payments-table">
                <thead><tr><th>Student</th><th>Grade</th><th>Method</th><th>Amount</th><th>Submitted</th><th>Status</th></tr></thead>
                <tbody><tr><td colspan="6" class="empty">Loading receipts…</td></tr></tbody>
            </table>
        </div>
        <div class="pagination" id="payment-pagination"></div>
    </section>
    <aside class="payment-detail" id="payment-detail">
        <div class="payment-detail__empty"><span>▧</span><b>Select a receipt</b><p>Receipt image and student details will appear here.</p></div>
    </aside>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var state = { rows: [], selected: null, page: 1, lastPage: 1, total: 0, status: '', loading: false };
    var tableBody = document.querySelector('#payments-table tbody');
    var detail = document.getElementById('payment-detail');

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[char];
        });
    }

    function statusBadge(status) {
        var cls = status === 'approved' ? 'badge-success' : status === 'rejected' ? 'badge-danger' : 'badge-warning';
        var label = status === 'pending' ? 'Needs review' : status.charAt(0).toUpperCase() + status.slice(1);
        return '<span class="badge ' + cls + '">' + label + '</span>';
    }

    function load(page) {
        state.page = page || state.page;
        state.loading = true;
        tableBody.innerHTML = '<tr><td colspan="6" class="empty">Loading receipts…</td></tr>';
        var params = { page: state.page, per_page: 15 };
        if (state.status) params.status = state.status;
        httpFetch('/api/payments?' + new URLSearchParams(params))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.rows = data.data || [];
                state.lastPage = data.last_page || 1;
                state.total = data.total || 0;
                document.getElementById('payment-count').textContent = state.total + (state.total === 1 ? ' receipt' : ' receipts');
                if (!state.rows.some(function (row) { return row.id === state.selected; })) state.selected = state.rows[0]?.id ?? null;
                renderRows();
                renderPagination();
                renderDetail();
            })
            .catch(function (error) {
                tableBody.innerHTML = '<tr><td colspan="6" class="empty">' + escapeHtml(error.message) + '</td></tr>';
            })
            .finally(function () { state.loading = false; });
    }

    function renderRows() {
        if (!state.rows.length) {
            tableBody.innerHTML = '<tr><td colspan="6" class="empty">No receipts match this filter.</td></tr>';
            return;
        }
        tableBody.innerHTML = state.rows.map(function (payment) {
            var student = payment.student || {};
            var name = student.display_name || student.first_name || ('Student #' + student.id);
            var grade = student.grade ? student.grade.name : '—';
            var date = payment.created_at ? new Date(payment.created_at).toLocaleString() : '—';
            return '<tr class="payment-row' + (payment.id === state.selected ? ' is-selected' : '') + '" data-id="' + payment.id + '" tabindex="0">' +
                '<td><b>' + escapeHtml(name) + '</b><small class="table-subline">' + escapeHtml(student.telegram_username ? '@' + student.telegram_username : student.telegram_id) + '</small></td>' +
                '<td>' + escapeHtml(grade) + '</td><td>' + escapeHtml(payment.method) + '</td>' +
                '<td class="amount-cell">' + Number(payment.amount).toLocaleString() + ' birr</td><td>' + escapeHtml(date) + '</td><td>' + statusBadge(payment.status) + '</td></tr>';
        }).join('');
        tableBody.querySelectorAll('.payment-row').forEach(function (row) {
            var select = function () { state.selected = Number(row.dataset.id); renderRows(); renderDetail(); };
            row.addEventListener('click', select);
            row.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(); } });
        });
    }

    function renderPagination() {
        var host = document.getElementById('payment-pagination');
        host.innerHTML = '';
        if (state.lastPage < 2) return;
        var previous = document.createElement('button');
        previous.className = 'btn btn-secondary btn-sm'; previous.textContent = '← Previous'; previous.disabled = state.page <= 1;
        previous.addEventListener('click', function () { load(state.page - 1); });
        var label = document.createElement('span'); label.textContent = 'Page ' + state.page + ' of ' + state.lastPage;
        var next = document.createElement('button');
        next.className = 'btn btn-secondary btn-sm'; next.textContent = 'Next →'; next.disabled = state.page >= state.lastPage;
        next.addEventListener('click', function () { load(state.page + 1); });
        host.append(previous, label, next);
    }

    function renderDetail() {
        var payment = state.rows.find(function (row) { return row.id === state.selected; });
        if (!payment) {
            detail.innerHTML = '<div class="payment-detail__empty"><span>▧</span><b>Select a receipt</b><p>Receipt image and student details will appear here.</p></div>';
            return;
        }
        var student = payment.student || {};
        var name = student.display_name || student.first_name || ('Student #' + student.id);
        var activeUntil = payment.valid_until ? new Date(payment.valid_until).toLocaleDateString() : null;
        detail.innerHTML = '<div class="payment-detail__header"><div><span class="eyebrow">RECEIPT #' + payment.id + '</span><h2>' + escapeHtml(name) + '</h2></div>' + statusBadge(payment.status) + '</div>' +
            '<div class="receipt-preview"><img src="/api/payments/' + payment.id + '/receipt" alt="Receipt submitted by ' + escapeHtml(name) + '" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><p hidden>Receipt preview unavailable. The student may have sent this directly to @J0519 instead of the bot.</p></div>' +
            '<dl class="payment-facts"><div><dt>Grade</dt><dd>' + escapeHtml(student.grade ? student.grade.name : 'Not selected') + '</dd></div>' +
            '<div><dt>Telegram</dt><dd>' + escapeHtml(student.telegram_username ? '@' + student.telegram_username : student.telegram_id) + '</dd></div>' +
            '<div><dt>Payment method</dt><dd>' + escapeHtml(payment.method) + '</dd></div>' +
            '<div><dt>Amount</dt><dd>' + Number(payment.amount).toLocaleString() + ' birr</dd></div>' +
            '<div><dt>Submitted</dt><dd>' + escapeHtml(payment.created_at ? new Date(payment.created_at).toLocaleString() : '—') + '</dd></div>' +
            (activeUntil ? '<div><dt>Activation until</dt><dd>' + escapeHtml(activeUntil) + '</dd></div>' : '') +
            (payment.review_reason ? '<div><dt>Review note</dt><dd>' + escapeHtml(payment.review_reason) + '</dd></div>' : '') + '</dl>' +
            (payment.status === 'pending' ? '<div class="payment-review"><label class="field-label" for="reject-reason">Reason if rejecting</label><select class="input" id="reject-reason"><option>Receipt is unclear</option><option>Amount does not match</option><option>Receipt appears already used</option><option>Payment could not be confirmed</option></select><div class="actions"><button class="btn btn-primary" id="approve-payment">Approve · activate 30 days</button><button class="btn btn-danger" id="reject-payment">Reject receipt</button></div></div>' : '<p class="payment-handled">This receipt has been reviewed.</p>');

        var approve = document.getElementById('approve-payment');
        var reject = document.getElementById('reject-payment');
        if (approve) approve.addEventListener('click', function () { review('approve', payment.id); });
        if (reject) reject.addEventListener('click', function () { review('reject', payment.id); });
    }

    function review(action, id) {
        var body = action === 'reject' ? { reason: document.getElementById('reject-reason').value } : {};
        var message = action === 'approve' ? 'Approve the receipt and activate this account for 30 days?' : 'Reject this receipt and send the reason to the student?';
        if (!window.confirm(message)) return;
        httpFetch('/api/payments/' + id + '/' + action, { method: 'POST', body: body })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                showToast('success', action === 'approve'
                    ? 'Payment approved. Account active until ' + new Date(data.activated_until).toLocaleDateString() + '.'
                    : 'Receipt rejected and student notified.');
                load(state.page);
            })
            .catch(function (error) { showToast('error', error.message); });
    }

    document.getElementById('payment-filter').addEventListener('change', function () {
        state.status = this.value; state.page = 1; state.selected = null; load(1);
    });
    load(1);
});
</script>
@endpush

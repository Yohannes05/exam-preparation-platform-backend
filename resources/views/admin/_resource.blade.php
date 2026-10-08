@extends('admin.layout')

@section('title', $title)
@section('page-title', $title)
@section('page-description', $pageDescription)

@section('content')
<div class="resource-toolbar">
    <div class="resource-search">
        <span aria-hidden="true">⌕</span>
        <input
            type="search"
            id="search"
            placeholder="{{ $searchPlaceholder }}"
            value="{{ request('search', '') }}"
            class="input"
            @if($searchable) autofocus @endif
        >
    </div>
    <div class="resource-toolbar__filters">
        @foreach($filters as $f)
            <select class="input max-w-[180px]" name="{{ $f['name'] }}" id="filter-{{ $f['name'] }}" @if(isset($f['source'])) data-source="{{ $f['source'] }}" @endif>
                <option value="">All {{ $f['label'] ?? $f['name'] }}</option>
                @foreach($f['options'] ?? [] as $option)
                    <option value="{{ $option['value'] }}" {{ request($f['name']) == $option['value'] ? 'selected' : '' }}>
                        {{ $option['label'] ?? $option['value'] }}
                    </option>
                @endforeach
            </select>
        @endforeach
        <button class="btn btn-primary" id="new-btn">＋ Add {{ $title }}</button>
    </div>
</div>

<div class="table-panel">
<div class="table-panel__heading"><div><h2>{{ $title }}</h2><p>Search, filter, edit, or add {{ strtolower($title) }} records.</p></div><span class="record-count" id="record-count">Loading…</span></div>
<div class="table-wrap">
    <table id="table-{{ $resource }}">
        <thead>
            <tr>
                @foreach($columns as $c)
                    <th>{{ $c['label'] }}</th>
                @endforeach
                <th>Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
</div>

<div class="pagination" id="pagination-{{ $resource }}"></div>

<div id="modal-{{ $resource }}"></div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var state = {
        rows: [],
        meta: { page: 1, last: 1, total: 0 },
        page: 1,
        search: '{{ request('search', '') }}',
        filterValues: {},
        editing: null,
        form: {},
        formErrors: {},
        saving: false,
        saveAndContinue: false,
        refData: {},
        loading: false,
        error: '',
        resource: '{{ $resource }}'
    };

    @foreach($filters as $f)
        state.filterValues['{{ $f['name'] }}'] = '{{ request($f['name'], '') }}';
    @endforeach

    var title = @json($title);
    var fields = @json($fields);
    var columns = @json($columns);
    var filters = @json($filters);
    var searchable = {{ $searchable ? 'true' : 'false' }};
    var formGuidance = {
        grades: 'Create a grade level students can choose when they start the bot.',
        subjects: 'Choose a grade and subject language. Subjects can contain many units; create and number them from the Chapters page after saving.',
        chapters: 'Add the chapter first. Write its lesson content on the Notes page, then return here to publish all notes as one Telegram article and upload the chapter PDF.',
        topics: 'Choose the numbered chapter, then set the topic order within it.',
        notes: 'Write and edit lesson content here. After saving, go to Chapters and publish the chapter article. Upload its PDF from the Chapters page.',
        questions: 'Choose where the question belongs, then add the question, answer choices, and one correct answer.',
        exams: 'Set the exam type and timing. Leave Chapter empty for an exam covering a whole subject.',
        announcements: 'Write the message and choose who can see it. Publish it when it is ready.'
    };

    var refs = {};
    var refSources = [];
    fields.forEach(function (f) { if (f.source) refSources.push(f.source); });
    filters.forEach(function (f) { if (f.source) refSources.push(f.source); });
    refSources = refSources.filter(function (v, i, a) { return a.indexOf(v) === i; });

    function loadRefs() {
        if (Object.keys(refs).length) return Promise.resolve();
        state.loading = true;
        state.error = '';
        return Promise.all(refSources.map(function (s) {
            return httpFetch('/api/v1/' + s + '?per_page=100').then(function (r) { return r.json(); }).then(function (d) {
                refs[s] = d.data || [];
            });
        })).catch(function () {}).finally(function () { state.loading = false; });
    }

    function refreshFilterDropdowns() {
        document.querySelectorAll('.resource-toolbar__filters [data-source]').forEach(function (select) {
            var name = select.name;
            var config = filters.find(function (item) { return item.name === name; });
            if (!config) return;
            var items = (refs[config.source] || []).slice();
            var gradeId = state.filterValues.grade_id || '';
            var subjectId = state.filterValues.subject_id || '';
            var chapterId = state.filterValues.chapter_id || '';

            if (name === 'subject_id' && gradeId) {
                items = items.filter(function (item) { return String(item.grade_id) === String(gradeId); });
            } else if (name === 'chapter_id') {
                if (subjectId) {
                    items = items.filter(function (item) { return String(item.subject_id) === String(subjectId); });
                } else if (gradeId) {
                    var gradeSubjects = (refs.subjects || []).filter(function (subject) {
                        return String(subject.grade_id) === String(gradeId);
                    }).map(function (subject) { return String(subject.id); });
                    items = items.filter(function (item) { return gradeSubjects.indexOf(String(item.subject_id)) !== -1; });
                }
            } else if (name === 'topic_id' && chapterId) {
                items = items.filter(function (item) { return String(item.chapter_id) === String(chapterId); });
            }

            var selected = state.filterValues[name] || '';
            if (selected && !items.some(function (item) { return String(item.id) === String(selected); })) {
                selected = '';
                state.filterValues[name] = '';
            }
            select.innerHTML = '<option value="">All ' + escapeHtml(config.label || name) + '</option>' +
                items.map(function (item) {
                    return '<option value="' + item.id + '">' + escapeHtml(refLabel(item)) + '</option>';
                }).join('');
            select.value = selected;
        });
    }

    function load(page) {
        state.loading = true;
        state.error = '';
        var params = { page: page || state.page, per_page: 15 };
        if (searchable) params.search = state.search;
        Object.keys(state.filterValues).forEach(function (k) {
            if (state.filterValues[k] !== '') params[k] = state.filterValues[k];
        });
        return httpFetch('/api/v1/{{ $resource }}?' + new URLSearchParams(params))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.rows = data.data;
                state.meta = { page: data.current_page, last: data.last_page, total: data.total };
                var count = document.getElementById('record-count');
                if (count) count.textContent = state.meta.total + (state.meta.total === 1 ? ' record' : ' records');
            })
            .catch(function (e) {
                state.error = e.message;
            })
            .finally(function () {
                state.loading = false;
                render();
            });
    }

    function render() {
        var tbody = document.querySelector('#table-' + state.resource + ' tbody');
        var pg = document.getElementById('pagination-' + state.resource);
        if (!tbody) return;

        if (state.loading) {
            tbody.innerHTML = '<tr><td colspan="' + (columns.length + 1) + '"><div class="empty">Loading…</div></td></tr>';
            return;
        }
        if (state.error) {
            tbody.innerHTML = '<tr><td colspan="' + (columns.length + 1) + '"><div class="empty">' + state.error + '</div></td></tr>';
            return;
        }
        if (state.rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="' + (columns.length + 1) + '"><div class="empty">' + escapeHtml('{{ $emptyText }}') + '</div></td></tr>';
            return;
        }
        tbody.innerHTML = state.rows.map(function (row) {
            var cells = columns.map(function (c) {
                if (c.render) {
                    var html = typeof c.render === 'function' ? c.render(row) : renderColumn(c, row);
                    return '<td>' + (html || '—') + '</td>';
                }
                var value = row[c.key];
                if (value === null || value === undefined || value === '') return '<td>—</td>';
                return '<td>' + escapeHtml(String(value)) + '</td>';
            }).join('');
            return '<tr class="row-' + row.id + '">' + cells + '<td>' +
                (state.resource === 'subjects' ? '<a class="btn btn-secondary btn-sm" href="/admin/chapters?action=create&subject_id=' + row.id + '">＋ Add unit</a> ' : '') +
                (state.resource === 'chapters' ? '<a class="btn btn-secondary btn-sm" href="/admin/notes?chapter_id=' + row.id + '">Manage notes</a> <a class="btn btn-secondary btn-sm" href="/admin/notes?action=create&chapter_id=' + row.id + '">＋ Add note</a> ' + (Number(row.notes_count) ? '<button class="btn btn-secondary btn-sm publish-notes-btn" data-id="' + row.id + '">Publish article</button>' : '<button class="btn btn-secondary btn-sm" disabled title="Add notes first">Add notes first</button>') + ' <button class="btn btn-secondary btn-sm upload-chapter-pdf-btn" data-id="' + row.id + '">Upload PDF</button> <input type="file" accept="application/pdf" class="chapter-pdf-input" data-id="' + row.id + '" hidden>' : '') +
                '<button class="btn btn-ghost btn-sm edit-btn" data-id="' + row.id + '">Edit</button>' +
                '<button class="btn btn-ghost btn-sm btn-danger delete-btn" data-id="' + row.id + '">Delete</button>' +
                '</td></tr>';
        }).join('');

        tbody.querySelectorAll('.edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { openEdit(parseInt(btn.dataset.id, 10)); });
        });
        tbody.querySelectorAll('.delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { removeRow(parseInt(btn.dataset.id, 10)); });
        });
        tbody.querySelectorAll('.publish-notes-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.disabled = true; btn.textContent = 'Publishing…';
                httpFetch('/api/chapters/' + btn.dataset.id + '/publish-notes', { method: 'POST' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { showToast('success', 'Chapter article published.'); window.open(d.url, '_blank', 'noopener'); load(state.page); })
                    .catch(function (e) { showToast('error', e.message); btn.disabled = false; btn.textContent = 'Publish notes'; });
            });
        });
        tbody.querySelectorAll('.upload-chapter-pdf-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { tbody.querySelector('.chapter-pdf-input[data-id="' + btn.dataset.id + '"]').click(); });
        });
        tbody.querySelectorAll('.chapter-pdf-input').forEach(function (input) {
            input.addEventListener('change', function () {
                if (!input.files || !input.files[0]) return;
                var form = new FormData(); form.append('pdf', input.files[0]);
                httpFetch('/api/chapters/' + input.dataset.id + '/pdf', { method: 'POST', body: form })
                    .then(function (r) { return r.json(); })
                    .then(function () { showToast('success', 'Chapter PDF uploaded.'); load(state.page); })
                    .catch(function (e) { showToast('error', e.message); })
                    .finally(function () { input.value = ''; });
            });
        });
        tbody.querySelectorAll('.remove-chapter-pdf-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm('Remove this chapter PDF?')) return;
                httpFetch('/api/chapters/' + btn.dataset.id + '/pdf', { method: 'DELETE' })
                    .then(function () { showToast('success', 'Chapter PDF removed.'); load(state.page); })
                    .catch(function (e) { showToast('error', e.message); });
            });
        });

        if (pg) {
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
    }

    function openCreate() {
        state.editing = null;
        state.form = {};
        var query = new URLSearchParams(window.location.search);
        ['grade_id', 'subject_id', 'chapter_id', 'topic_id'].forEach(function (key) {
            if (state.filterValues[key]) state.form[key] = state.filterValues[key];
            if (!state.form[key] && query.get(key)) state.form[key] = query.get(key);
        });
        if (state.resource === 'notes' && state.form.chapter_id) {
            var chosenChapter = (refs.chapters || []).find(function (item) { return String(item.id) === String(state.form.chapter_id); });
            if (chosenChapter) {
                state.form.subject_id = chosenChapter.subject_id;
                var chosenSubject = (refs.subjects || []).find(function (item) { return String(item.id) === String(chosenChapter.subject_id); });
                if (chosenSubject) state.form.grade_id = chosenSubject.grade_id;
            }
        }
        state.saveAndContinue = false;
        state.formErrors = {};
        state.error = '';
        renderForm();
    }

    function openEdit(id) {
        state.editing = id;
        state.formErrors = {};
        state.error = '';
        httpFetch('/api/v1/' + state.resource + '/' + id)
            .then(function (r) { return r.json(); })
            .then(function (full) {
                state.form = rowToForm(full, fields);
                renderForm();
            })
            .catch(function (e) { showToast('error', e.message); });
    }

    function renderForm() {
        var wrap = document.getElementById('modal-' + state.resource);
        if (!wrap) return;
        wrap.innerHTML =
            '<div class="modal-backdrop">' +
            '<div class="modal">' +
            '<div class="modal-head"><div><h3>' + (state.editing === null ? 'Add ' + title : 'Edit ' + title) + '</h3>' +
            '<p>' + escapeHtml(formGuidance[state.resource] || 'Fill in the details below, then save your changes.') + '</p></div>' +
            '<button type="button" class="btn-ghost btn-sm" id="modal-close" aria-label="Close dialog">✕</button></div>' +
            '<form class="modal-body" id="modal-form">' +
            '<div class="form-grid form-grid--2">' +
            fields.map(function (f) {
                var input = '';
                if (f.type === 'options') {
                    input = '<div class="space-y-2" id="f-options"></div>' +
                        '<button type="button" class="btn btn-secondary btn-sm" id="add-option" style="margin-top:8px;">+ Add option</button>';
                } else if (f.type === 'select') {
                    input = '<select class="input" id="f-' + f.name + '"' + (f.required ? ' required' : '') + '><option value="">— select —</option>' +
                        (f.options || []).map(function (o) { return '<option value="' + o.value + '">' + escapeHtml(o.label) + '</option>'; }).join('') +
                        '</select>';
                } else if (f.type === 'ref') {
                    input = '<select class="input" id="f-' + f.name + '"' + (f.required ? ' required' : '') + '><option value="">— select —</option>' +
                        (refs[f.source] || []).map(function (o) { return '<option value="' + o.id + '">' + escapeHtml(refLabel(o)) + '</option>'; }).join('') +
                        '</select>';
                } else if (f.type === 'checkbox') {
                    input = '<label class="flex items-center gap-2 text-sm text-gray-700">' +
                        '<input type="checkbox" id="f-' + f.name + '" class="rounded border-gray-300"> ' + escapeHtml(f.label) + '</label>';
                } else if (f.type === 'textarea') {
                    var areaValue = state.form[f.name] ?? '';
                    input = '<textarea class="input" id="f-' + f.name + '" rows="' + (f.rows || 3) + '" placeholder="' + escapeHtml(f.placeholder || '') + '"' + (f.required ? ' required' : '') + '>' + escapeHtml(areaValue) + '</textarea>';
                } else {
                    var val = state.form[f.name] ?? '';
                    var escaped = typeof val === 'string' ? escapeHtml(val) : val ?? '';
                    input = '<input class="input" type="' + (f.type === 'number' ? 'number' : 'text') + '" id="f-' + f.name + '" value="' + escaped + '" placeholder="' + escapeHtml(f.placeholder || '') + '"' + (f.required ? ' required' : '') + '>';
                }
                var label = f.type === 'checkbox' ? '' : '<label for="f-' + f.name + '">' + escapeHtml(f.label) + (f.required ? ' <span class="required-mark">Required</span>' : '') + '</label>';
                var hint = f.hint ? '<small class="field-hint">' + escapeHtml(f.hint) + '</small>' : '';
                return '<div class="form-group' + (f.wide ? ' form-group--wide' : '') + (f.type === 'checkbox' ? ' form-group--check' : '') + '">' + label + input + hint + '</div>';
            }).join('') +
            '</div>' +
            '<div class="error" id="form-error" style="display:none;margin-top:12px;"></div>' +
            '<div class="modal-foot">' +
            '<button type="button" class="btn btn-secondary" id="modal-close2">Cancel</button>' +
            (state.editing === null ? '<button type="button" class="btn btn-secondary" id="modal-save-next">Save + add another</button>' : '') +
            '<button type="submit" class="btn btn-primary" id="modal-save">Save</button>' +
            '</div>' +
            '</form>' +
            '</div>' +
            '</div>';
        syncInputs();
        renderOptionsEditor();

        document.getElementById('modal-close').addEventListener('click', closeModal);
        document.getElementById('modal-close2').addEventListener('click', closeModal);
        var form = document.getElementById('modal-form');
        form.addEventListener('submit', save);
        var saveNext = document.getElementById('modal-save-next');
        if (saveNext) saveNext.addEventListener('click', function () {
            if (!form.reportValidity()) return;
            state.saveAndContinue = true;
            form.requestSubmit();
        });
        form.addEventListener('change', function () {
            fields.forEach(function (f) {
                if (f.type !== 'ref') return;
                var el = document.getElementById('f-' + f.name);
                if (el) state.form[f.name] = el.value;
            });
            fields.forEach(function (f) { if (f.type === 'ref' && f.parent) populateRef(f); });
        });
        var addOpt = document.getElementById('add-option');
        if (addOpt) addOpt.addEventListener('click', function () {
            var opts = state.form.options || [];
            opts.push({
                label: String.fromCharCode(65 + (opts.length % 26)),
                text: '',
                is_correct: opts.length === 0
            });
            state.form.options = opts;
            renderOptionsEditor();
        });
    }

    function syncInputs() {
        fields.forEach(function (f) {
            var el = document.getElementById('f-' + f.name);
            if (!el || f.type === 'options') return;
            if (f.type === 'ref') { populateRef(f); return; }
            if (f.type === 'checkbox') { el.checked = !!state.form[f.name]; return; }
            el.value = state.form[f.name] ?? '';
        });
    }

    function populateRef(f) {
        var sel = document.getElementById('f-' + f.name);
        if (!sel) return;
        var parentEl = f.parent ? document.getElementById('f-' + f.parent) : null;
        var parentVal = parentEl ? String(parentEl.value) : null;
        var items = (refs[f.source] || []).filter(function (o) {
            return !f.parent || String(o[f.parentField] ?? '') === parentVal;
        });
        sel.innerHTML = '<option value="">— select —</option>' + items.map(function (o) {
            return '<option value="' + o.id + '">' + escapeHtml(refLabel(o)) + '</option>';
        }).join('');
        var want = state.form[f.name] ?? '';
        sel.value = String(want);
        if (sel.value === '' && want !== '' && want !== null && want !== undefined) {
            state.form[f.name] = ''; // parent changed — reset dependent value
        }
    }

    function renderOptionsEditor() {
        var wrap = document.getElementById('f-options');
        if (!wrap) return;
        var opts = state.form.options || [];
        wrap.innerHTML = opts.length
            ? opts.map(function (o, i) {
                return '<div class="option-row">' +
                    '<input class="input option-label" maxlength="4" value="' + escapeHtml(o.label || '') + '" aria-label="Label">' +
                    '<input class="input option-text" value="' + escapeHtml(o.text || '') + '" placeholder="Option text">' +
                    '<label class="option-correct"><input type="radio" name="f-correct" aria-label="Mark option ' + escapeHtml(o.label || String(i + 1)) + ' as correct"' + (o.is_correct ? ' checked' : '') + '> correct answer</label>' +
                    '<button type="button" class="delete-btn option-remove" title="Remove">✕</button>' +
                    '</div>';
            }).join('')
            : '<div class="text-xs text-gray-500">No options yet — add at least two.</div>';

        wrap.querySelectorAll('.option-row').forEach(function (row, i) {
            var label = row.querySelector('.option-label');
            var text = row.querySelector('.option-text');
            var correct = row.querySelector('input[type="radio"]');
            label.addEventListener('input', function () { state.form.options[i].label = label.value; });
            text.addEventListener('input', function () { state.form.options[i].text = text.value; });
            correct.addEventListener('change', function () {
                state.form.options.forEach(function (o, j) { o.is_correct = j === i; });
            });
            row.querySelector('.option-remove').addEventListener('click', function () {
                state.form.options.splice(i, 1);
                renderOptionsEditor();
            });
        });
    }

    function readForm() {
        fields.forEach(function (f) {
            if (f.type === 'options') return; // kept live by renderOptionsEditor
            var el = document.getElementById('f-' + f.name);
            if (!el) return;
            if (f.type === 'checkbox') state.form[f.name] = el.checked;
            else state.form[f.name] = el.value;
        });
    }

    function save(e) {
        e.preventDefault();
        if (state.saving) return;
        state.error = '';
        state.saving = true;
        var saveButton = document.getElementById('modal-save');
        var nextButton = document.getElementById('modal-save-next');
        if (saveButton) saveButton.disabled = true;
        if (nextButton) nextButton.disabled = true;
        readForm();

        var payload = {};
        fields.forEach(function (f) {
            var value = state.form[f.name];
            if (f.type === 'options') {
                payload[f.name] = (value || [])
                    .filter(function (o) { return o.text && o.text.trim() !== ''; })
                    .map(function (o) {
                        return { label: o.label, text: o.text.trim(), is_correct: !!o.is_correct };
                    });
            } else if (f.type === 'checkbox') {
                payload[f.name] = !!value;
            } else if (f.type === 'number' || f.type === 'ref') {
                payload[f.name] = (value === '' || value === null || value === undefined) ? null : Number(value);
            } else {
                payload[f.name] = value === undefined ? '' : value;
            }
        });

        var creating = state.editing === null;
        var continueCreating = creating && state.saveAndContinue;
        var url = creating
            ? '/api/v1/' + state.resource
            : '/api/v1/' + state.resource + '/' + state.editing;

        httpFetch(url, { method: creating ? 'POST' : 'PUT', body: payload })
            .then(function () {
                load();
                if (continueCreating) {
                    state.editing = null;
                    state.form = {};
                    fields.forEach(function (field) {
                        if (field.type === 'ref' || field.type === 'select' || field.type === 'checkbox') {
                            state.form[field.name] = payload[field.name];
                        }
                    });
                    if (fields.some(function (field) { return field.name === 'order'; })) {
                        state.form.order = Number(payload.order || 0) + 1;
                    }
                    if (fields.some(function (field) { return field.name === 'options'; })) {
                        state.form.options = [
                            { label: 'A', text: '', is_correct: true },
                            { label: 'B', text: '', is_correct: false }
                        ];
                    }
                    state.formErrors = {};
                    state.error = '';
                    renderForm();
                    var firstEntry = fields.find(function (field) {
                        return field.type === 'text' || field.type === 'textarea';
                    });
                    if (firstEntry) {
                        var firstInput = document.getElementById('f-' + firstEntry.name);
                        if (firstInput) firstInput.focus();
                    }
                    showToast('success', 'Saved. Add the next '+title.toLowerCase()+'.');
                } else {
                    closeModal();
                    showToast('success', creating ? 'Created' : 'Updated');
                }
            })
            .catch(function (err) {
                showFormErrors(fieldErrors(err));
                showFormError(err.message);
            })
            .finally(function () {
                state.saving = false;
                state.saveAndContinue = false;
                var saveButton = document.getElementById('modal-save');
                var nextButton = document.getElementById('modal-save-next');
                if (saveButton) saveButton.disabled = false;
                if (nextButton) nextButton.disabled = false;
            });
    }

    function showFormError(message) {
        var box = document.getElementById('form-error');
        if (!box) return;
        box.textContent = message || '';
        box.style.display = message ? 'block' : 'none';
    }

    function showFormErrors(errors) {
        document.querySelectorAll('#modal-' + state.resource + ' .form-field-error').forEach(function (n) { n.remove(); });
        if (!errors) return;
        fields.forEach(function (f) {
            var msg = errors[f.name];
            if (!msg) return;
            var el = document.getElementById('f-' + f.name);
            var host = el ? el.closest('.form-group') : null;
            if (!host) return;
            var p = document.createElement('p');
            p.className = 'form-field-error';
            p.textContent = Array.isArray(msg) ? msg[0] : msg;
            host.appendChild(p);
        });
    }

    function removeRow(id) {
        if (!window.confirm('Delete this entry?')) return;
        httpFetch('/api/v1/' + state.resource + '/' + id, { method: 'DELETE' })
            .then(function (r) { return r.json(); })
            .then(function () { load(); showToast('success', 'Deleted'); })
            .catch(function (e) { showToast('error', e.message); });
    }

    function closeModal() {
        state.editing = null;
        state.form = {};
        state.formErrors = {};
        state.error = '';
        var wrap = document.getElementById('modal-' + state.resource);
        if (wrap) wrap.innerHTML = '';
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

    function fieldErrors(err) {
        var out = {};
        if (err?.errors) {
            Object.keys(err.errors).forEach(function (k) {
                out[k] = Array.isArray(err.errors[k]) ? err.errors[k][0] : err.errors[k];
            });
        }
        return out;
    }

    function refLabel(item) {
        if (!item) return '';
        if (item.subject_id !== undefined && item.title) {
            return 'Chapter ' + (Number(item.order) > 0 ? item.order : '—') + ' · ' + item.title;
        }
        return item.name || item.title || '#' + item.id;
    }
    function escapeHtml(str) {
      return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function renderColumn(c, row) {
      switch (c.render) {
        case 'description': return escapeHtml((row.description || '—'));
        case 'status': return row.is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-gray">Hidden</span>';
        case 'chapter_access': return row.requires_activation ? '<span class="badge badge-warning">Activation required</span>' : '<span class="badge badge-success">Free</span>';
        case 'chapter_number': return Number(row.order) > 0 ? 'Chapter ' + Number(row.order) : 'Set chapter number';
        case 'chapter_notes_count': return '<span class="badge ' + (Number(row.notes_count) ? 'badge-success' : 'badge-gray') + '">' + Number(row.notes_count || 0) + ' notes</span>';
        case 'chapter_article': return row.telegraph_url ? '<a class="btn btn-ghost btn-sm" href="' + escapeHtml(row.telegraph_url) + '" target="_blank" rel="noopener">Open article</a>' : '<span class="badge badge-gray">Not published</span>';
        case 'chapter_pdf': return row.pdf_file ? '<span class="badge badge-success">PDF ready</span> <button class="btn btn-ghost btn-sm remove-chapter-pdf-btn" data-id="' + row.id + '">Remove</button>' : '<span class="badge badge-gray">No PDF</span>';
        case 'grade': return row.grade ? escapeHtml(row.grade.name) : '—';
        case 'subject': return row.subject ? escapeHtml(row.subject.name) : '—';
        case 'chapter': return row.chapter
          ? 'Chapter ' + (Number(row.chapter.order) > 0 ? Number(row.chapter.order) : '—') + ' · ' + escapeHtml(row.chapter.title)
          : '—';
        case 'subject_chapters': return row.chapters && row.chapters.length
          ? row.chapters.map(function (chapter) {
              return '<span class="chapter-pill">Chapter ' + (Number(chapter.order) > 0 ? Number(chapter.order) : '—') + ' · ' + escapeHtml(chapter.title) + '</span>';
            }).join(' ')
          : '<span class="text-gray-400">No units yet</span>';
        case 'content_preview': return escapeHtml(preview(row.content));
        case 'question_text': return escapeHtml(preview(row.question_text, 100));
        case 'difficulty': return badge(row.difficulty, row.difficulty === 'easy' ? 'badge-success' : row.difficulty === 'medium' ? 'badge-warning' : row.difficulty === 'hard' ? 'badge-danger' : 'badge-gray');
        case 'type': return row.question_type === 'true_false' ? 'T/F' : 'MCQ';
        case 'option_count': return row.options ? row.options.length : 0;
        case 'difficulty_badge': return badge(row.difficulty, row.difficulty === 'easy' ? 'badge-success' : row.difficulty === 'medium' ? 'badge-warning' : row.difficulty === 'hard' ? 'badge-danger' : 'badge-gray');
        case 'body_preview': return escapeHtml(preview(row.body));
        case 'audience': return row.audience === 'grade' ? 'Grade: ' + (row.grade ? escapeHtml(row.grade.name) : '—') : 'Everyone';
        case 'published': return row.is_published ? '<span class="badge badge-success">Published</span>' : '<span class="badge badge-gray">Draft</span>';
        case 'option_count_badge': return row.options ? row.options.length : 0;
        default: return row[c.key] != null && row[c.key] !== '' ? escapeHtml(String(row[c.key])) : '—';
      }
    }

    function preview(value, max) {
        var text = String(value || '').replace(/<[^>]+>/g, ' ').trim();
        if (!text) return '—';
        max = max || 90;
        return text.length > max ? text.slice(0, max) + '…' : text;
    }

    function badge(text, cls) {
        return '<span class="badge ' + cls + '">' + escapeHtml(text || '') + '</span>';
    }

    function rowToForm(row, fields) {
        var form = {};
        fields.forEach(function (f) {
            var value = row[f.name];
            if (f.type === 'checkbox') form[f.name] = !!value;
            else if (f.type === 'options') form[f.name] = (value || []).map(function (o) { return { label: o.label, text: o.text, is_correct: !!o.is_correct }; });
            else if (f.type === 'number') form[f.name] = value ?? '';
            else form[f.name] = value ?? '';
        });
        return form;
    }

    // Wiring
    document.getElementById('new-btn').addEventListener('click', openCreate);
    var searchInput = document.getElementById('search');
    if (searchInput) {
        var searchTimer;
        searchInput.addEventListener('input', function () {
            state.search = this.value;
            state.page = 1;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { load(1); }, 220);
        });
    }

    // Filters
    document.querySelectorAll('[id^="filter-"]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var changedFilter = sel.id.replace('filter-', '');
            state.filterValues[changedFilter] = sel.value;
            var hierarchy = ['grade_id', 'subject_id', 'chapter_id', 'topic_id'];
            var changedIndex = hierarchy.indexOf(changedFilter);
            if (changedIndex !== -1) {
                hierarchy.slice(changedIndex + 1).forEach(function (child) {
                    if (document.getElementById('filter-' + child)) state.filterValues[child] = '';
                });
            }
            refreshFilterDropdowns();
            state.page = 1;
            load(1);
        });
    });

    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('modal-backdrop')) closeModal();
    });

    loadRefs().then(function () {
        refreshFilterDropdowns();
        load(1).then(function () {
            if (new URLSearchParams(window.location.search).get('action') === 'create') openCreate();
        });
    });
});
</script>
@endpush

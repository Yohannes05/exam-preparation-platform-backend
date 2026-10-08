@extends('admin.layout')

@section('title', 'Questions')
@section('page-title', 'Questions')
@section('page-description', 'Add, edit, and organize practice questions for the Telegram bot.')

@section('content')
<div class="resource-toolbar question-filters">
    <div class="resource-toolbar__filters">
        <select id="filter-grade" class="input" aria-label="Filter by grade"><option value="">All grades</option></select>
        <select id="filter-subject" class="input" aria-label="Filter by subject"><option value="">All subjects</option></select>
        <select id="filter-chapter" class="input" aria-label="Filter by chapter"><option value="">All chapters</option></select>
        <input id="filter-source" class="input" aria-label="Filter by source" placeholder="Source">
        <select id="filter-year" class="input" aria-label="Filter by year"><option value="">All years</option>@for($year = 2015; $year <= 2026; $year++)<option value="{{ $year }}">{{ $year }} E.C.</option>@endfor</select>
    </div>
    <div class="resource-search"><span aria-hidden="true">⌕</span><input class="input" id="question-search" type="search" placeholder="Search question text…"></div>
</div>

<div class="questions-split">
    <section class="table-panel">
        <div class="table-panel__heading">
            <div><h2>Question bank</h2><p>Choose a question to edit it, or add a new one.</p></div>
            <span class="record-count" id="question-count">Loading…</span>
        </div>
        <div class="table-wrap"><table id="question-table">
            <thead><tr><th>ID</th><th>Question</th><th>Source / year</th><th>Chapter</th><th>Answer</th></tr></thead>
            <tbody><tr><td colspan="5" class="empty">Loading questions…</td></tr></tbody>
        </table></div>
        <div class="pagination" id="question-pagination"></div>
    </section>

    <section class="table-panel question-editor">
        <div class="table-panel__heading question-editor__heading">
            <div><span class="eyebrow">QUESTION EDITOR</span><h2 id="editor-title">Add a question</h2><p>Choose a grade, subject, and chapter first.</p></div>
            <button type="button" class="btn btn-secondary btn-sm" id="new-question">＋ New</button>
        </div>
        <form id="question-form" class="question-form">
            <div class="form-grid form-grid--2">
                <div class="form-group"><label for="edit-grade">Grade <span class="required-mark">Required</span></label><select class="input" id="edit-grade" required></select></div>
                <div class="form-group"><label for="edit-subject">Subject <span class="required-mark">Required</span></label><select class="input" id="edit-subject" required></select></div>
                <div class="form-group"><label for="edit-chapter">Chapter <span class="required-mark">Required</span></label><select class="input" id="edit-chapter" required></select></div>
                <div class="form-group"><label for="edit-topic">Topic <span class="field-optional">Optional</span></label><select class="input" id="edit-topic"></select></div>
                <div class="form-group"><label for="edit-type">Question type</label><select class="input" id="edit-type"><option value="multiple_choice">Multiple choice</option><option value="true_false">True / False</option></select></div>
                <div class="form-group"><label for="edit-difficulty">Difficulty</label><select class="input" id="edit-difficulty"><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></div>
                <div class="form-group form-group--wide"><label for="edit-question">Question text <span class="required-mark">Required</span></label><textarea class="input" id="edit-question" rows="4" required placeholder="Write the question students will answer"></textarea></div>
                <div class="form-group form-group--wide"><div class="form-label-row"><label>Answer choices</label><button type="button" class="btn btn-secondary btn-sm" id="add-choice">＋ Add choice</button></div><p class="field-hint">Add at least two choices, then mark the correct answer.</p><div id="choice-list" class="choice-list"></div></div>
                <div class="form-group form-group--wide"><label for="edit-explanation">Explanation <span class="field-optional">Shown after the student answers</span></label><textarea class="input" id="edit-explanation" rows="3" placeholder="Explain why the correct answer is right"></textarea></div>
                <div class="form-group"><label for="edit-source">Source</label><input class="input" id="edit-source" placeholder="e.g. National exam"></div>
                <div class="form-group"><label for="edit-year">Year (E.C.)</label><input class="input" id="edit-year" type="number" min="1900" max="2100" placeholder="2018"></div>
                <label class="question-active"><input type="checkbox" id="edit-active" checked> Visible to students</label>
            </div>
            <div class="question-form__actions"><button type="button" class="btn btn-danger" id="delete-question" hidden>Delete</button><span class="form-message" id="question-message" role="status"></span><button type="submit" class="btn btn-primary" id="save-question">Save question</button></div>
        </form>
    </section>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var state = { grades: [], subjects: [], chapters: [], topics: [], rows: [], page: 1, lastPage: 1, total: 0, editing: null, options: [], filters: { grade_id:'', subject_id:'', chapter_id:'', source:'', year:'' }, search:'' };
    var tableBody = document.querySelector('#question-table tbody');
    var form = document.getElementById('question-form');
    var choiceList = document.getElementById('choice-list');
    var message = document.getElementById('question-message');

    function escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
    function optionLabel(option) { return option.name || option.title || ('#' + option.id); }
    function setSelect(id, items, emptyText, selected, labeler) {
        var select = document.getElementById(id);
        select.innerHTML = '<option value="">' + escapeHtml(emptyText) + '</option>' + items.map(function (item) {
            return '<option value="' + item.id + '">' + escapeHtml(labeler ? labeler(item) : optionLabel(item)) + '</option>';
        }).join('');
        select.value = selected || '';
    }
    function chapterName(chapter) { return 'Chapter ' + (chapter.order || '—') + ' · ' + chapter.title; }
    function refreshSubjects(prefix, selected) {
        var gradeId = document.getElementById(prefix + 'grade').value;
        var choices = state.subjects.filter(function (subject) { return !gradeId || String(subject.grade_id) === String(gradeId); });
        setSelect(prefix + 'subject', choices, 'Choose subject', selected, function (subject) { return subject.name; });
    }
    function refreshChapters(prefix, selected) {
        var subjectId = document.getElementById(prefix + 'subject').value;
        var choices = state.chapters.filter(function (chapter) { return !subjectId || String(chapter.subject_id) === String(subjectId); });
        setSelect(prefix + 'chapter', choices, 'Choose chapter', selected, chapterName);
    }
    function updateEditorTopics(selected) {
        var chapterId = document.getElementById('edit-chapter').value;
        var choices = state.topics.filter(function (topic) { return !chapterId || String(topic.chapter_id) === String(chapterId); });
        setSelect('edit-topic', choices, 'No topic', selected, function (topic) { return topic.title; });
    }
    function loadReferences() {
        return Promise.all(['grades','subjects','chapters','topics'].map(function (resource) {
            return httpFetch('/api/v1/' + resource + '?per_page=100').then(function (response) { return response.json(); }).then(function (data) { state[resource] = data.data || []; });
        })).then(function () {
            setSelect('filter-grade', state.grades, 'All grades', '');
            setSelect('filter-subject', state.subjects, 'All subjects', '', function (subject) { return subject.name; });
            setSelect('filter-chapter', state.chapters, 'All chapters', '', chapterName);
            setSelect('edit-grade', state.grades, 'Choose grade', '');
            refreshSubjects('edit-', ''); refreshChapters('edit-', ''); updateEditorTopics('');
        });
    }
    function queryString() {
        var params = { page:state.page, per_page:15 };
        if (state.search) params.search = state.search;
        Object.keys(state.filters).forEach(function (key) { if (state.filters[key]) params[key] = state.filters[key]; });
        return new URLSearchParams(params);
    }
    function load(page) {
        state.page = page || state.page;
        tableBody.innerHTML = '<tr><td colspan="5" class="empty">Loading questions…</td></tr>';
        return httpFetch('/api/v1/questions?' + queryString()).then(function (response) { return response.json(); }).then(function (data) {
            state.rows = data.data || []; state.lastPage = data.last_page || 1; state.total = data.total || 0;
            document.getElementById('question-count').textContent = state.total + (state.total === 1 ? ' question' : ' questions');
            if (!state.rows.some(function (row) { return row.id === state.editing; })) state.editing = null;
            renderRows(); renderPagination();
        }).catch(function (error) { tableBody.innerHTML = '<tr><td colspan="5" class="empty">' + escapeHtml(error.message) + '</td></tr>'; });
    }
    function renderRows() {
        if (!state.rows.length) { tableBody.innerHTML = '<tr><td colspan="5" class="empty">No questions match these filters. Add a question or change the filters.</td></tr>'; return; }
        tableBody.innerHTML = state.rows.map(function (question) {
            var correct = (question.options || []).find(function (option) { return option.is_correct; });
            var chapter = question.chapter ? chapterName(question.chapter) : '—';
            return '<tr class="question-row' + (question.id === state.editing ? ' is-selected' : '') + '" data-id="' + question.id + '" tabindex="0">' +
                '<td class="mono">#' + question.id + '</td><td><div class="question-row__text">' + escapeHtml(question.question_text) + '</div></td>' +
                '<td>' + escapeHtml([question.source, question.year].filter(Boolean).join(' · ') || '—') + '</td><td>' + escapeHtml(chapter) + '</td>' +
                '<td>' + (correct ? '<span class="badge badge-success">' + escapeHtml(correct.label) + '</span>' : '—') + '</td></tr>';
        }).join('');
        tableBody.querySelectorAll('.question-row').forEach(function (row) {
            var open = function () { state.editing = Number(row.dataset.id); renderRows(); openEditor(state.rows.find(function (item) { return item.id === state.editing; })); };
            row.addEventListener('click', open); row.addEventListener('keydown', function (event) { if (event.key === 'Enter') open(); });
        });
    }
    function renderPagination() {
        var host = document.getElementById('question-pagination'); host.innerHTML = '';
        if (state.lastPage < 2) return;
        var previous = document.createElement('button'); previous.className='btn btn-secondary btn-sm'; previous.textContent='← Previous'; previous.disabled=state.page<=1; previous.onclick=function(){load(state.page-1);};
        var label = document.createElement('span'); label.textContent='Page '+state.page+' of '+state.lastPage;
        var next = document.createElement('button'); next.className='btn btn-secondary btn-sm'; next.textContent='Next →'; next.disabled=state.page>=state.lastPage; next.onclick=function(){load(state.page+1);};
        host.append(previous,label,next);
    }
    function renderOptions() {
        choiceList.innerHTML = state.options.map(function (option, index) {
            return '<div class="choice-row"><span class="choice-label">' + escapeHtml(option.label) + '</span><input class="input choice-text" data-index="' + index + '" value="' + escapeHtml(option.text) + '" placeholder="Type answer choice" aria-label="Choice ' + escapeHtml(option.label) + '"><label class="choice-correct"><input type="radio" name="correct-choice" data-index="' + index + '"' + (option.is_correct ? ' checked' : '') + '> Correct</label><button type="button" class="btn btn-ghost btn-sm remove-choice" data-index="' + index + '" aria-label="Remove choice">×</button></div>';
        }).join('');
        choiceList.querySelectorAll('.choice-text').forEach(function (input) { input.addEventListener('input', function () { state.options[Number(input.dataset.index)].text = input.value; }); });
        choiceList.querySelectorAll('.choice-correct input').forEach(function (input) { input.addEventListener('change', function () { state.options.forEach(function (item, index) { item.is_correct = index === Number(input.dataset.index); }); renderOptions(); }); });
        choiceList.querySelectorAll('.remove-choice').forEach(function (button) { button.addEventListener('click', function () { state.options.splice(Number(button.dataset.index),1); relabelOptions(); renderOptions(); }); });
    }
    function relabelOptions() { state.options.forEach(function (option, index) { option.label = String.fromCharCode(65 + index); }); if (!state.options.some(function (option) { return option.is_correct; }) && state.options.length) state.options[0].is_correct = true; }
    function resetEditor() {
        state.editing = null; state.options = [{label:'A',text:'',is_correct:true},{label:'B',text:'',is_correct:false}];
        form.reset(); document.getElementById('edit-active').checked = true;
        setSelect('edit-grade',state.grades,'Choose grade',''); refreshSubjects('edit-',''); refreshChapters('edit-',''); updateEditorTopics('');
        document.getElementById('editor-title').textContent='Add a question';
        document.getElementById('delete-question').hidden=true; message.textContent=''; renderOptions(); renderRows();
    }
    function openEditor(question) {
        if (!question) return resetEditor();
        state.editing=question.id;
        setSelect('edit-grade',state.grades,'Choose grade',question.grade_id);
        refreshSubjects('edit-',question.subject_id); refreshChapters('edit-',question.chapter_id); updateEditorTopics(question.topic_id);
        document.getElementById('edit-type').value=question.question_type;
        document.getElementById('edit-difficulty').value=question.difficulty;
        document.getElementById('edit-question').value=question.question_text || '';
        document.getElementById('edit-explanation').value=question.explanation || '';
        document.getElementById('edit-source').value=question.source || '';
        document.getElementById('edit-year').value=question.year || '';
        document.getElementById('edit-active').checked=!!question.is_active;
        state.options=(question.options || []).map(function(option){return {label:option.label,text:option.text,is_correct:!!option.is_correct};});
        document.getElementById('editor-title').textContent='Edit question #' + question.id;
        document.getElementById('delete-question').hidden=false; message.textContent=''; renderOptions();
    }
    function payload() {
        return {
            grade_id:document.getElementById('edit-grade').value,
            subject_id:document.getElementById('edit-subject').value,
            chapter_id:document.getElementById('edit-chapter').value,
            topic_id:document.getElementById('edit-topic').value || null,
            question_type:document.getElementById('edit-type').value,
            difficulty:document.getElementById('edit-difficulty').value,
            question_text:document.getElementById('edit-question').value.trim(),
            explanation:document.getElementById('edit-explanation').value.trim() || null,
            source:document.getElementById('edit-source').value.trim() || null,
            year:document.getElementById('edit-year').value || null,
            is_active:document.getElementById('edit-active').checked,
            options:state.options.map(function(option){return {label:option.label,text:option.text.trim(),is_correct:option.is_correct};})
        };
    }

    document.getElementById('new-question').addEventListener('click',resetEditor);
    document.getElementById('add-choice').addEventListener('click',function(){ if(state.options.length>=4){showToast('info','Questions can have up to four choices.');return;} state.options.push({label:String.fromCharCode(65+state.options.length),text:'',is_correct:false}); renderOptions(); });
    document.getElementById('edit-grade').addEventListener('change',function(){refreshSubjects('edit-','');refreshChapters('edit-','');updateEditorTopics('');});
    document.getElementById('edit-subject').addEventListener('change',function(){refreshChapters('edit-','');updateEditorTopics('');});
    document.getElementById('edit-chapter').addEventListener('change',function(){updateEditorTopics('');});
    document.getElementById('filter-grade').addEventListener('change',function(){state.filters.grade_id=this.value;state.filters.subject_id='';state.filters.chapter_id='';refreshSubjects('filter-','');refreshChapters('filter-','');load(1);});
    document.getElementById('filter-subject').addEventListener('change',function(){state.filters.subject_id=this.value;state.filters.chapter_id='';refreshChapters('filter-','');load(1);});
    document.getElementById('filter-chapter').addEventListener('change',function(){state.filters.chapter_id=this.value;load(1);});
    ['source','year'].forEach(function(key){document.getElementById('filter-'+key).addEventListener('change',function(){state.filters[key]=this.value;load(1);});});
    var searchTimer; document.getElementById('question-search').addEventListener('input',function(){state.search=this.value.trim();clearTimeout(searchTimer);searchTimer=setTimeout(function(){load(1);},250);});
    form.addEventListener('submit',function(event){
        event.preventDefault(); var data=payload();
        if(data.options.length<2 || data.options.some(function(option){return !option.text;})){message.textContent='Add at least two non-empty answer choices.';return;}
        if(data.options.filter(function(option){return option.is_correct;}).length!==1){message.textContent='Mark exactly one answer as correct.';return;}
        var editing=state.editing, url=editing?'/api/v1/questions/'+editing:'/api/v1/questions';
        document.getElementById('save-question').disabled=true; message.textContent='Saving…';
        httpFetch(url,{method:editing?'PUT':'POST',body:data}).then(function(response){return response.json();}).then(function(saved){
            state.editing=saved.id; showToast('success',editing?'Question updated.':'Question added.');
            return load(state.page).then(function(){var full=state.rows.find(function(item){return item.id===saved.id;}); if(full)openEditor(full);});
        }).catch(function(error){message.textContent=error.message;showToast('error',error.message);}).finally(function(){document.getElementById('save-question').disabled=false;});
    });
    document.getElementById('delete-question').addEventListener('click',function(){
        if(!state.editing||!confirm('Delete this question and its answer choices?'))return;
        httpFetch('/api/v1/questions/'+state.editing,{method:'DELETE'}).then(function(){showToast('success','Question deleted.');resetEditor();load(1);}).catch(function(error){showToast('error',error.message);});
    });

    loadReferences().then(function(){
        refreshSubjects('filter-','');refreshChapters('filter-','');resetEditor();load(1);
        if(new URLSearchParams(location.search).get('action')==='create') document.getElementById('edit-question').focus();
    }).catch(function(error){showToast('error','Could not load question form options: '+error.message);});
});
</script>
@endpush

@extends('admin.layout')

@section('title', 'Import questions')
@section('page-title', 'Import questions')
@section('page-description', 'Upload a spreadsheet, review every row, then add valid questions to the bot.')

@section('content')
<div class="import-layout">
    <section class="table-panel import-form-panel">
        <div class="table-panel__heading"><div><h2>Upload question file</h2><p>CSV and Excel .xlsx files are supported. Your file is checked before anything is saved.</p></div></div>
        <form id="import-form" class="import-form">
            <div class="form-grid form-grid--2">
                <div class="form-group"><label for="import-grade">Grade <span class="required-mark">Required</span></label><select class="input" id="import-grade" required><option value="">Choose grade</option></select></div>
                <div class="form-group"><label for="import-subject">Subject <span class="required-mark">Required</span></label><select class="input" id="import-subject" required><option value="">Choose subject</option></select></div>
                <div class="form-group"><label for="import-source">Default source <span class="field-optional">Optional</span></label><input class="input" id="import-source" placeholder="e.g. National exam"></div>
                <div class="form-group"><label for="import-year">Default year (E.C.) <span class="field-optional">Optional</span></label><input class="input" id="import-year" type="number" min="1900" max="2100" placeholder="2018"></div>
                <div class="form-group form-group--wide"><label for="import-file">Question file <span class="required-mark">Required</span></label><input class="input import-file" id="import-file" type="file" accept=".csv,.txt,.xlsx" required><small class="field-hint">Each row needs a question, two or more choices, the correct answer, and a chapter number.</small></div>
            </div>
            <div class="import-template"><b>Spreadsheet columns</b><p>Question · A · B · C · D · Answer · Chapter · Explanation · Source · Year</p><small>Use the letter (A–D) or exact choice text in the Answer column. Chapter can also be named Unit.</small></div>
            <div class="import-actions"><span id="import-message" class="form-message" role="status"></span><button type="submit" class="btn btn-primary" id="preview-import">Preview file</button></div>
        </form>
    </section>
    <aside class="table-panel import-help">
        <div class="table-panel__heading"><div><h2>Before you import</h2><p>Check these details in your spreadsheet.</p></div></div>
        <ol><li>Put the column names in the first row.</li><li>Set the chapter to its number, such as <b>1</b> or <b>4</b>.</li><li>Include at least two answer choices and one correct answer.</li><li>Only rows marked ready will be added. Existing questions are flagged as duplicates.</li></ol>
        <a class="btn btn-secondary" href="{{ route('admin.questions') }}">Open question bank</a>
    </aside>
</div>

<section class="table-panel import-preview" id="import-preview-panel" hidden>
    <div class="table-panel__heading"><div><h2>Review import</h2><p>Rows with errors or duplicates will be skipped.</p></div><div class="import-counts" id="import-counts"></div></div>
    <div class="table-wrap"><table><thead><tr><th>Row</th><th>Chapter</th><th>Question and choices</th><th>Answer</th><th>Check</th></tr></thead><tbody id="import-rows"></tbody></table></div>
    <div class="import-actions import-actions--bottom"><span id="commit-message" class="form-message" role="status"></span><button type="button" class="btn btn-primary" id="commit-import" disabled>Import ready questions</button></div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded',function(){
    var grades=[],subjects=[],previewKey=null;
    var form=document.getElementById('import-form'),message=document.getElementById('import-message'),commitMessage=document.getElementById('commit-message');
    function esc(value){return String(value??'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
    function loadSubjects(){var gradeId=document.getElementById('import-grade').value;var items=subjects.filter(function(item){return !gradeId||String(item.grade_id)===String(gradeId);});document.getElementById('import-subject').innerHTML='<option value="">Choose subject</option>'+items.map(function(item){return '<option value="'+item.id+'">'+esc(item.name)+'</option>';}).join('');}
    function showPreview(data){
        previewKey=data.preview_key;var panel=document.getElementById('import-preview-panel');panel.hidden=false;
        document.getElementById('import-counts').innerHTML='<span>'+data.total+' rows</span><span class="import-count-ready">'+data.ready+' ready</span><span>'+data.duplicates+' duplicates</span><span>'+data.needs_fix+' need fixes</span>';
        var body=document.getElementById('import-rows');
        body.innerHTML=data.rows.map(function(row){var choices=(row.options||[]).map(function(option){return '<div><b>'+esc(option.label)+'.</b> '+esc(option.text)+(option.is_correct?' <span class="badge badge-success">Correct</span>':'')+'</div>';}).join('');var issue=row.duplicate?'Already exists in this chapter.':(row.errors||[]).join(' ');return '<tr><td class="mono">'+row.row+'</td><td>Chapter '+esc(row.chapter_number||'—')+'</td><td><b>'+esc(row.question_text||'(empty question)')+'</b><div class="import-choice-preview">'+choices+'</div></td><td>'+esc(row.correct_answer||'—')+'</td><td>'+(row.ready?'<span class="badge badge-success">Ready</span>':'<span class="badge badge-warning">'+esc(issue||'Skipped')+'</span>')+'</td></tr>';}).join('');
        document.getElementById('commit-import').disabled=data.ready===0;panel.scrollIntoView({behavior:'smooth',block:'start'});
    }
    httpFetch('/api/v1/grades?per_page=100').then(function(r){return r.json();}).then(function(data){grades=data.data||[];document.getElementById('import-grade').innerHTML='<option value="">Choose grade</option>'+grades.map(function(item){return '<option value="'+item.id+'">'+esc(item.name)+'</option>';}).join('');});
    httpFetch('/api/v1/subjects?per_page=100').then(function(r){return r.json();}).then(function(data){subjects=data.data||[];loadSubjects();});
    document.getElementById('import-grade').addEventListener('change',loadSubjects);
    form.addEventListener('submit',function(event){event.preventDefault();var file=document.getElementById('import-file').files[0];if(!file)return;var gradeId=document.getElementById('import-grade').value,subjectId=document.getElementById('import-subject').value;if(!gradeId||!subjectId){message.textContent='Choose a grade and subject first.';return;}
        var submit=document.getElementById('preview-import');submit.disabled=true;message.textContent='Uploading and checking rows…';document.getElementById('import-preview-panel').hidden=true;
        var data=new FormData();data.append('file',file);data.append('grade_id',gradeId);data.append('subject_id',subjectId);data.append('source',document.getElementById('import-source').value);data.append('year',document.getElementById('import-year').value);
        httpFetch('/api/questions/import/preview',{method:'POST',body:data}).then(function(r){return r.json();}).then(function(result){showPreview(result);message.textContent='Review the rows below, then import the ready questions.';}).catch(function(error){message.textContent=error.message;showToast('error',error.message);}).finally(function(){submit.disabled=false;});
    });
    document.getElementById('commit-import').addEventListener('click',function(){if(!previewKey)return;var button=this;if(!confirm('Import all ready questions into the selected subject?'))return;button.disabled=true;commitMessage.textContent='Importing…';httpFetch('/api/questions/import/commit',{method:'POST',body:{preview_key:previewKey}}).then(function(r){return r.json();}).then(function(result){previewKey=null;commitMessage.textContent=result.imported+' questions imported; '+result.skipped+' skipped.';showToast('success',result.imported+' questions imported.');button.disabled=true;}).catch(function(error){commitMessage.textContent=error.message;showToast('error',error.message);button.disabled=false;});});
});
</script>
@endpush

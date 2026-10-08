@extends('admin.layout')

@section('title', 'Notices')
@section('page-title', 'Notices')
@section('page-description', 'Publish updates students can read in the bot and optionally receive as a message.')

@section('content')
<div class="notice-layout">
    <section class="table-panel">
        <div class="table-panel__heading"><div><h2>New notice</h2><p>Write a short update and choose which students should see it.</p></div></div>
        <form id="notice-form" class="notice-form">
            <div class="form-group"><label for="notice-title">Title <span class="required-mark">Required</span></label><input class="input" id="notice-title" maxlength="200" required placeholder="e.g. New exam questions added"></div>
            <div class="form-group"><label for="notice-body">Message <span class="required-mark">Required</span></label><textarea class="input" id="notice-body" rows="6" maxlength="10000" required placeholder="Write the update students will see in the bot"></textarea><small class="field-hint">Students can open published notices from the bot menu.</small></div>
            <div class="form-grid form-grid--2">
                <div class="form-group"><label for="notice-audience">Show to</label><select class="input" id="notice-audience"><option value="all">All students</option><option value="grade">One grade</option><option value="activated">Activated students only</option></select></div>
                <div class="form-group" id="notice-grade-wrap" hidden><label for="notice-grade">Grade</label><select class="input" id="notice-grade"></select></div>
            </div>
            <label class="notice-broadcast"><input type="checkbox" id="notice-send"> Also send this notice as a Telegram message now</label>
            <p class="field-hint">Message delivery requires the bot token to be configured. A published notice is also available from the bot’s Notices menu.</p>
            <div class="notice-form__actions"><span id="notice-message" class="form-message" role="status"></span><button class="btn btn-primary" type="submit" id="publish-notice">Publish notice</button></div>
        </form>
    </section>

    <aside class="notice-preview-column">
        <section class="table-panel">
            <div class="table-panel__heading"><div><h2>Student preview</h2><p>This is the notice shown in Telegram.</p></div></div>
            <div class="telegram-preview"><div class="telegram-preview__top"><span class="telegram-preview__avatar">E</span><span><b>Exam Prep Bot</b><small>bot</small></span></div><div class="telegram-preview__bubble"><span class="telegram-preview__label">📢 Notice · ማስታወቂያ</span><b id="notice-preview-title">Your notice title</b><p id="notice-preview-body">Your message will appear here as you type.</p><small>now</small></div></div>
        </section>
        <section class="table-panel notice-help"><div class="table-panel__heading"><div><h2>Who sees it?</h2></div></div><div class="notice-help__body"><p><b>All students</b> can read it from Notices.</p><p><b>One grade</b> is shown only to students in that grade.</p><p><b>Activated students</b> is restricted to accounts with an active subscription.</p></div></section>
    </aside>
</div>

<section class="table-panel notice-history">
    <div class="table-panel__heading"><div><h2>Notice history</h2><p>Hide an announcement to remove it from the bot menu.</p></div><span class="record-count" id="notice-count">Loading…</span></div>
    <div class="table-wrap"><table id="notice-table"><thead><tr><th>Title</th><th>Audience</th><th>Published</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr><td colspan="5" class="empty">Loading notices…</td></tr></tbody></table></div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('notice-form');
    var audience = document.getElementById('notice-audience');
    var title = document.getElementById('notice-title');
    var body = document.getElementById('notice-body');
    var message = document.getElementById('notice-message');
    var gradeList = [];
    function esc(value) { return String(value ?? '').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];}); }
    function updatePreview() {
        document.getElementById('notice-preview-title').textContent = title.value.trim() || 'Your notice title';
        document.getElementById('notice-preview-body').textContent = body.value.trim() || 'Your message will appear here as you type.';
    }
    function audienceName(item) {
        if (item.audience === 'grade') return item.grade ? item.grade.name : 'Selected grade';
        return item.audience === 'activated' ? 'Activated students' : 'All students';
    }
    function loadNotices() {
        httpFetch('/api/v1/announcements?per_page=100').then(function(r){return r.json();}).then(function(data){
            var rows=data.data||[]; document.getElementById('notice-count').textContent=rows.length+' notices';
            var host=document.querySelector('#notice-table tbody');
            if(!rows.length){host.innerHTML='<tr><td colspan="5" class="empty">No notices yet. Publish the first one above.</td></tr>';return;}
            host.innerHTML=rows.map(function(item){
                var status=item.is_published?'<span class="badge badge-success">Published</span>':'<span class="badge badge-gray">Hidden</span>';
                var action=item.is_published?'Hide':'Publish';
                return '<tr><td><b>'+esc(item.title)+'</b><small class="table-subline">'+esc(item.body)+'</small></td><td>'+esc(audienceName(item))+'</td><td>'+esc(item.published_at?new Date(item.published_at).toLocaleDateString():'—')+'</td><td>'+status+'</td><td><button class="btn btn-secondary btn-sm toggle-notice" data-id="'+item.id+'" data-published="'+(item.is_published?'1':'0')+'">'+action+'</button> <button class="btn btn-ghost btn-sm remove-notice" data-id="'+item.id+'">Delete</button></td></tr>';
            }).join('');
            host.querySelectorAll('.toggle-notice').forEach(function(button){button.addEventListener('click',function(){toggleNotice(Number(button.dataset.id),button.dataset.published!=='1');});});
            host.querySelectorAll('.remove-notice').forEach(function(button){button.addEventListener('click',function(){deleteNotice(Number(button.dataset.id));});});
        }).catch(function(error){document.querySelector('#notice-table tbody').innerHTML='<tr><td colspan="5" class="empty">'+esc(error.message)+'</td></tr>';});
    }
    function toggleNotice(id, publish) {
        httpFetch('/api/v1/announcements/'+id).then(function(r){return r.json();}).then(function(item){
            item.is_published=publish;
            return httpFetch('/api/v1/announcements/'+id,{method:'PATCH',body:item});
        }).then(function(){showToast('success',publish?'Notice published in the bot.':'Notice hidden from the bot.');loadNotices();}).catch(function(error){showToast('error',error.message);});
    }
    function deleteNotice(id) {
        if(!confirm('Delete this notice permanently?'))return;
        httpFetch('/api/v1/announcements/'+id,{method:'DELETE'}).then(function(){showToast('success','Notice deleted.');loadNotices();}).catch(function(error){showToast('error',error.message);});
    }
    audience.addEventListener('change',function(){
        var gradeMode=audience.value==='grade'; document.getElementById('notice-grade-wrap').hidden=!gradeMode;
        document.getElementById('notice-grade').required=gradeMode;
    });
    title.addEventListener('input',updatePreview); body.addEventListener('input',updatePreview);
    form.addEventListener('submit',function(event){
        event.preventDefault(); message.textContent='Publishing…'; document.getElementById('publish-notice').disabled=true;
        var payload={title:title.value.trim(),body:body.value.trim(),audience:audience.value,grade_id:audience.value==='grade'?document.getElementById('notice-grade').value:null,send_as_message:document.getElementById('notice-send').checked};
        httpFetch('/api/announcements/publish',{method:'POST',body:payload}).then(function(r){return r.json();}).then(function(result){
            var suffix=payload.send_as_message?' Telegram messages sent: '+result.messages_sent+'. Failed: '+result.messages_failed+'.':'';
            showToast('success','Notice published.'+suffix);message.textContent='Published successfully.'+suffix;form.reset();document.getElementById('notice-grade-wrap').hidden=true;updatePreview();loadNotices();
        }).catch(function(error){message.textContent=error.message;showToast('error',error.message);}).finally(function(){document.getElementById('publish-notice').disabled=false;});
    });
    httpFetch('/api/v1/grades?per_page=100').then(function(r){return r.json();}).then(function(data){gradeList=data.data||[];document.getElementById('notice-grade').innerHTML='<option value="">Choose grade</option>'+gradeList.map(function(item){return '<option value="'+item.id+'">'+esc(item.name)+'</option>';}).join('');});
    updatePreview();loadNotices();
});
</script>
@endpush

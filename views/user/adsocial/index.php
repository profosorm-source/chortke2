<?php $title='Adsocial — تسک شبکه اجتماعی'; $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><i class="material-icons text-primary">thumb_up</i> Adsocial — کسب درآمد</h4>
    <p class="text-muted mb-0" style="font-size:12px;">تسک‌های شبکه اجتماعی را انجام دهید و درآمد کسب کنید</p>
  </div>
  <a href="<?= url('/adsocial/history') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">history</i> تاریخچه</a>
</div>

<?php if(!empty($stats)): ?>
<div class="row g-2 mt-2">
  <div class="col-6 col-md-3"><div class="card text-center py-2"><div class="card-body p-2"><div class="h4 mb-0 text-success"><?= number_format($stats->approved??0) ?></div><small class="text-muted">تایید شده</small></div></div></div>
  <div class="col-6 col-md-3"><div class="card text-center py-2"><div class="card-body p-2"><div class="h4 mb-0 text-warning"><?= number_format($stats->pending??0) ?></div><small class="text-muted">در انتظار</small></div></div></div>
  <div class="col-6 col-md-3"><div class="card text-center py-2"><div class="card-body p-2"><div class="h4 mb-0 text-danger"><?= number_format($stats->rejected??0) ?></div><small class="text-muted">رد شده</small></div></div></div>
  <div class="col-6 col-md-3"><div class="card text-center py-2"><div class="card-body p-2"><div class="h4 mb-0 text-primary"><?= number_format($stats->total??0) ?></div><small class="text-muted">کل</small></div></div></div>
</div>
<?php endif; ?>

<div class="mt-3">
<?php if(empty($tasks)): ?>
  <div class="alert alert-info text-center mt-4"><i class="material-icons">info</i> در حال حاضر تسکی موجود نیست.</div>
<?php else: foreach($tasks as $task): ?>
  <div class="card mb-2">
    <div class="card-body py-2">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <strong><?= e($task->title) ?></strong>
          <span class="badge bg-secondary ms-1"><?= e($task->platform??'') ?></span>
          <span class="badge bg-info ms-1"><?= e($task->task_type??'') ?></span>
          <div class="text-success small mt-1"><i class="material-icons" style="font-size:14px;vertical-align:middle;">payments</i> <?= number_format($task->reward_per_execution??0) ?> <?= setting('currency_mode','irt')==='usdt'?'USDT':'تومان' ?></div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="startTask(<?= e($task->id) ?>)">شروع</button>
      </div>
    </div>
  </div>
<?php endforeach; endif; ?>
</div>

<script>
function startTask(adId) {
  fetch('<?= url('/adsocial/start') ?>', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'},body:JSON.stringify({ad_id:adId})})
    .then(r=>r.json()).then(d=>{
      if(d.success) { alert(d.message||'تسک شروع شد'); if(d.execution_id) location.href='<?= url('/adsocial/') ?>'+d.execution_id+'/execute'; else location.reload(); }
      else alert(d.message||'خطا');
    });
}
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>

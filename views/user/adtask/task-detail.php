<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">work_outline</span> جزئیات تسک</h4>
  </div>
  <a href="<?= url('/adtask/available') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<?php if(!$task): ?>
  <div class="alert alert-danger mt-3">تسک یافت نشد.</div>
<?php else: ?>
<div class="row mt-3">
  <div class="col-md-8">
    <div class="card mb-3">
      <?php if(!empty($task->sample_image)): ?>
      <img src="<?= e($task->sample_image) ?>" class="card-img-top" style="max-height:200px;object-fit:cover;">
      <?php endif; ?>
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <h5 class="fw-bold mb-0"><?= e($task->title) ?></h5>
          <span class="badge bg-info"><?= e($task->task_type ?? '') ?></span>
        </div>
        <div class="mb-3 text-muted" style="white-space:pre-wrap;line-height:1.8;"><?= nl2br(e($task->description ?? '')) ?></div>
        <?php if(!empty($task->target_url)): ?>
        <div class="mb-3">
          <strong>لینک مرتبط:</strong>
          <a href="<?= e($task->target_url) ?>" target="_blank" class="ms-2"><?= e($task->target_url) ?></a>
        </div>
        <?php endif; ?>
        <div class="alert alert-warning small">
          <span class="material-icons" style="font-size:16px;vertical-align:middle;">assignment</span>
          <strong>مدرک مورد نیاز:</strong>
          <?= e($task->proof_description ?? ($task->proof_type ?? 'اسکرین‌شات')) ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card mb-3">
      <div class="card-body text-center">
        <div class="fs-2 fw-bold text-success"><?= number_format($task->reward_per_user ?? 0) ?></div>
        <div class="text-muted mb-3">تومان پاداش</div>
        <div class="row text-center small mb-3">
          <div class="col-6">
            <div class="fw-bold"><?= number_format($task->slots_remaining ?? 0) ?></div>
            <div class="text-muted">جای خالی</div>
          </div>
          <div class="col-6">
            <div class="fw-bold"><?= $task->deadline ? e(substr($task->deadline, 0, 10)) : '—' ?></div>
            <div class="text-muted">ددلاین</div>
          </div>
        </div>
        <?php if(($task->already_started ?? false) || ($task->already_submitted ?? false)): ?>
          <div class="alert alert-info small mb-2">شما قبلاً این تسک را شروع کرده‌اید.</div>
        <?php elseif(($task->slots_remaining ?? 0) <= 0): ?>
          <div class="alert alert-warning small mb-2">ظرفیت پر شده است.</div>
        <?php else: ?>
        <button class="btn btn-primary w-100" id="btnStart" data-id="<?= e($task->id) ?>">
          <span class="material-icons" style="font-size:16px;vertical-align:middle;">play_arrow</span> شروع تسک
        </button>
        <?php endif; ?>
      </div>
    </div>

    <?php if($task->already_started ?? false): ?>
    <div class="card">
      <div class="card-header"><h6 class="mb-0">ارسال مدرک</h6></div>
      <div class="card-body">
        <form method="POST" action="<?= url("/adtask/{$task->submission_id}/submit-proof") ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label small">توضیح</label>
            <textarea name="proof_text" class="form-control form-control-sm" rows="3" placeholder="توضیح مدرک..."></textarea>
          </div>
          <?php if(in_array($task->proof_type ?? '', ['screenshot','video','file'])): ?>
          <div class="mb-3">
            <label class="form-label small">فایل مدرک</label>
            <input type="file" name="proof_file" class="form-control form-control-sm">
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-success btn-sm w-100">ارسال مدرک</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
document.getElementById('btnStart')?.addEventListener('click', function() {
  const id = this.dataset.id;
  this.disabled = true; this.textContent = 'در حال پردازش...';
  fetch('/adtask/start', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''},
    body: JSON.stringify({task_id: id})
  }).then(r=>r.json()).then(d=>{
    if(d.success) { alert('تسک شروع شد! مدرک خود را ارسال کنید.'); location.reload(); }
    else { alert(d.message || 'خطا'); this.disabled = false; this.textContent = 'شروع تسک'; }
  });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
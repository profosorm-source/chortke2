<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">task_alt</span> انجام تسک Adsocial</h4>
    <p class="text-muted mb-0" style="font-size:12px;"><?= e($task->title ?? '') ?></p>
  </div>
  <a href="<?= url('/adsocial') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<div class="row mt-3 justify-content-center">
  <div class="col-md-8">
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h5 class="fw-bold mb-1"><?= e($task->title ?? '') ?></h5>
            <div class="d-flex gap-2">
              <span class="badge bg-info"><?= e($task->platform ?? '') ?></span>
              <span class="badge bg-secondary"><?= e($task->task_type ?? '') ?></span>
            </div>
          </div>
          <div class="text-center">
            <div class="fw-bold text-success fs-5"><?= number_format($task->reward ?? 0) ?> تومان</div>
            <small class="text-muted">پاداش شما</small>
          </div>
        </div>

        <?php if($task->description ?? ''): ?>
        <div class="alert alert-light border mb-3" style="white-space:pre-wrap;"><?= nl2br(e($task->description)) ?></div>
        <?php endif; ?>

        <?php if($task->target_url ?? ''): ?>
        <div class="mb-3">
          <a href="<?= e($task->target_url) ?>" target="_blank" class="btn btn-primary">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">open_in_new</span>
            رفتن به صفحه هدف
          </a>
          <small class="text-muted d-block mt-1">پس از انجام تسک، برگردید و مدرک ارسال کنید.</small>
        </div>
        <?php endif; ?>

        <hr>
        <h6 class="fw-bold mb-3">ارسال مدرک</h6>
        <form method="POST" action="<?= url("/adsocial/{$execution->id}/submit") ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>

          <?php $proofType = $task->proof_type ?? 'screenshot'; ?>

          <?php if(in_array($proofType, ['screenshot', 'video', 'file'])): ?>
          <div class="mb-3">
            <label class="form-label fw-bold">
              <?php if($proofType === 'screenshot'): ?>اسکرین‌شات<?php elseif($proofType === 'video'): ?>ویدیو<?php else: ?>فایل<?php endif; ?>
              <span class="text-danger">*</span>
            </label>
            <input type="file" name="proof_file" class="form-control" required
              accept="<?= $proofType === 'video' ? 'video/*' : ($proofType === 'screenshot' ? 'image/*' : '*') ?>">
          </div>
          <?php endif; ?>

          <?php if($proofType === 'code'): ?>
          <div class="mb-3">
            <label class="form-label fw-bold">کد رفرال / شناسه <span class="text-danger">*</span></label>
            <input type="text" name="proof_text" class="form-control" required placeholder="کد را وارد کنید">
          </div>
          <?php else: ?>
          <div class="mb-3">
            <label class="form-label">توضیح تکمیلی</label>
            <textarea name="proof_text" class="form-control" rows="2" placeholder="توضیح اختیاری..."></textarea>
          </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-success w-100">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">send</span>
            ارسال مدرک و دریافت پاداش
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>

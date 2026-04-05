<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">rate_review</span> بررسی مدرک تسک</h4>
  </div>
  <a href="<?= url('/adsocial/advertise') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<?php if(!$exec): ?>
  <div class="alert alert-danger mt-3">مدرک یافت نشد.</div>
<?php else: ?>
<div class="row mt-3 justify-content-center">
  <div class="col-md-8">
    <div class="card mb-3">
      <div class="card-header"><h6 class="mb-0">اطلاعات اجرا</h6></div>
      <div class="card-body">
        <table class="table table-sm table-bordered">
          <tr><th width="35%">کاربر اجراکننده</th><td><?= e($exec->user_name ?? $exec->user_id) ?></td></tr>
          <tr><th>آگهی</th><td><?= e($exec->ad_title ?? $exec->ad_id) ?></td></tr>
          <tr><th>تاریخ شروع</th><td><?= e(substr($exec->created_at ?? '', 0, 16)) ?></td></tr>
          <tr><th>وضعیت</th><td>
            <?php
              $sc = ['pending'=>'warning','submitted'=>'warning','approved'=>'success','rejected'=>'danger'];
              $sl = ['pending'=>'در انتظار','submitted'=>'ارسال شده','approved'=>'تایید شده','rejected'=>'رد شده'];
              $st = $exec->status ?? 'pending';
            ?>
            <span class="badge bg-<?= $sc[$st] ?? 'secondary' ?>"><?= $sl[$st] ?? $st ?></span>
          </td></tr>
        </table>
      </div>
    </div>

    <?php if(!empty($exec->proof_file) || !empty($exec->proof_text)): ?>
    <div class="card mb-3">
      <div class="card-header"><h6 class="mb-0">مدرک ارسالی</h6></div>
      <div class="card-body">
        <?php if(!empty($exec->proof_file)): ?>
        <?php $ext = strtolower(pathinfo($exec->proof_file, PATHINFO_EXTENSION)); ?>
        <?php if(in_array($ext, ['jpg','jpeg','png','gif','webp'])): ?>
          <img src="<?= e($exec->proof_file) ?>" class="img-fluid rounded mb-2" style="max-height:400px;">
        <?php elseif(in_array($ext, ['mp4','webm','mov'])): ?>
          <video src="<?= e($exec->proof_file) ?>" controls class="w-100 rounded mb-2" style="max-height:300px;"></video>
        <?php else: ?>
          <a href="<?= e($exec->proof_file) ?>" target="_blank" class="btn btn-outline-primary mb-2">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">download</span> دانلود فایل
          </a>
        <?php endif; ?>
        <?php endif; ?>
        <?php if(!empty($exec->proof_text)): ?>
        <div class="bg-light p-3 rounded"><?= nl2br(e($exec->proof_text)) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if(in_array($st, ['submitted','pending'])): ?>
    <div class="card">
      <div class="card-header"><h6 class="mb-0">تصمیم‌گیری</h6></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <form method="POST" action="<?= url("/adsocial/{$exec->id}/approve") ?>">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-success w-100" onclick="return confirm('تایید اجرا و پرداخت پاداش؟')">
                <span class="material-icons" style="font-size:16px;vertical-align:middle;">check_circle</span>
                تایید و پرداخت پاداش
              </button>
            </form>
          </div>
          <div class="col-md-6">
            <div id="rejectBlock">
              <button class="btn btn-danger w-100" id="btnShowReject">
                <span class="material-icons" style="font-size:16px;vertical-align:middle;">cancel</span>
                رد کردن
              </button>
            </div>
            <div id="rejectForm" class="d-none">
              <form method="POST" action="<?= url("/adsocial/{$exec->id}/reject") ?>">
                <?= csrf_field() ?>
                <textarea name="reason" class="form-control mb-2" rows="2" placeholder="دلیل رد..." required></textarea>
                <button type="submit" class="btn btn-danger btn-sm w-100">تایید رد</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
document.getElementById('btnShowReject')?.addEventListener('click', () => {
  document.getElementById('rejectBlock').classList.add('d-none');
  document.getElementById('rejectForm').classList.remove('d-none');
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>

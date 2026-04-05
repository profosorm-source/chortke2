<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">manage_accounts</span> مدیریت آگهی Adsocial</h4>
    <p class="text-muted mb-0" style="font-size:12px;"><?= e($ad->title ?? '') ?></p>
  </div>
  <a href="<?= url('/adsocial/advertise') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<?php if(!$ad): ?>
  <div class="alert alert-danger mt-3">آگهی یافت نشد.</div>
<?php else: ?>
<?php
  $sc = ['pending'=>'warning','active'=>'success','paused'=>'secondary','completed'=>'primary','rejected'=>'danger','cancelled'=>'dark'];
  $sl = ['pending'=>'در انتظار','active'=>'فعال','paused'=>'متوقف','completed'=>'تکمیل','rejected'=>'رد شده','cancelled'=>'لغو'];
  $st = $ad->status ?? 'pending';
?>
<div class="row mt-3">
  <div class="col-md-4">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">اطلاعات آگهی</h6>
        <span class="badge bg-<?= $sc[$st] ?>"><?= $sl[$st] ?></span>
      </div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0 small">
          <tr><td class="text-muted">پلتفرم</td><td><?= e($platforms[$ad->platform ?? ''] ?? $ad->platform ?? '—') ?></td></tr>
          <tr><td class="text-muted">نوع تسک</td><td><?= e($ad->task_type ?? '—') ?></td></tr>
          <tr><td class="text-muted">پاداش/نفر</td><td class="text-success fw-bold"><?= number_format($ad->reward ?? 0) ?> تومان</td></tr>
          <tr><td class="text-muted">ظرفیت</td><td><?= number_format($ad->max_slots ?? 0) ?></td></tr>
          <tr><td class="text-muted">انجام شده</td><td><?= number_format($ad->completed_slots ?? 0) ?></td></tr>
          <tr><td class="text-muted">ددلاین</td><td><?= $ad->deadline ? e(substr($ad->deadline, 0, 10)) : 'ندارد' ?></td></tr>
        </table>
        <?php if($ad->target_url ?? ''): ?>
        <a href="<?= e($ad->target_url) ?>" target="_blank" class="btn btn-outline-primary btn-sm w-100 mt-2">
          <span class="material-icons" style="font-size:14px;vertical-align:middle;">open_in_new</span> لینک هدف
        </a>
        <?php endif; ?>
        <?php $done = $ad->completed_slots ?? 0; $total = max(1, $ad->max_slots ?? 1); ?>
        <div class="progress mt-2" style="height:6px;">
          <div class="progress-bar bg-success" style="width:<?= min(100, ($done/$total)*100) ?>%"></div>
        </div>
        <small class="text-muted"><?= $done ?>/<?= $total ?></small>
      </div>
    </div>

    <?php if(in_array($st, ['active','paused'])): ?>
    <div class="card mb-3">
      <div class="card-body d-grid gap-2">
        <?php if($st === 'active'): ?>
        <form method="POST" action="<?= url("/adsocial/{$ad->id}/pause") ?>">
          <?= csrf_field() ?>
          <button class="btn btn-warning btn-sm w-100">
            <span class="material-icons" style="font-size:14px;vertical-align:middle;">pause</span> توقف موقت
          </button>
        </form>
        <?php else: ?>
        <form method="POST" action="<?= url("/adsocial/{$ad->id}/resume") ?>">
          <?= csrf_field() ?>
          <button class="btn btn-success btn-sm w-100">
            <span class="material-icons" style="font-size:14px;vertical-align:middle;">play_arrow</span> ادامه
          </button>
        </form>
        <?php endif; ?>
        <form method="POST" action="<?= url("/adsocial/{$ad->id}/cancel") ?>">
          <?= csrf_field() ?>
          <button class="btn btn-outline-danger btn-sm w-100" onclick="return confirm('لغو آگهی؟')">
            <span class="material-icons" style="font-size:14px;vertical-align:middle;">cancel</span> لغو آگهی
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-md-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">اجراها / مدارک دریافتی</h6>
        <span class="badge bg-secondary"><?= count($executions ?? []) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if(empty($executions)): ?>
          <div class="text-center py-4 text-muted small">هنوز اجرایی ثبت نشده.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
              <tr><th>کاربر</th><th>مدرک</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th></tr>
            </thead>
            <tbody>
              <?php foreach($executions as $exec): ?>
              <?php
                $esc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','in_progress'=>'info','submitted'=>'warning'];
                $esl = ['pending'=>'در انتظار','approved'=>'تایید ✓','rejected'=>'رد','in_progress'=>'شروع','submitted'=>'ارسال شده'];
                $est = $exec->status ?? 'pending';
              ?>
              <tr>
                <td>
                  <small class="fw-bold"><?= e($exec->user_name ?? $exec->user_id) ?></small>
                </td>
                <td>
                  <?php if(!empty($exec->proof_file)): ?>
                    <a href="<?= e($exec->proof_file) ?>" target="_blank" class="btn btn-link btn-sm p-0 small">مشاهده فایل</a>
                  <?php elseif(!empty($exec->proof_text)): ?>
                    <small class="text-muted"><?= e(mb_substr($exec->proof_text, 0, 40)) ?></small>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td><span class="badge bg-<?= $esc[$est] ?? 'secondary' ?>"><?= $esl[$est] ?? $est ?></span></td>
                <td style="font-size:11px;"><?= e(substr($exec->updated_at ?? $exec->created_at ?? '', 0, 10)) ?></td>
                <td>
                  <?php if(in_array($est, ['submitted','pending']) && !empty($exec->proof_file ?? $exec->proof_text ?? '')): ?>
                  <a href="<?= url("/adsocial/review/{$exec->id}") ?>" class="btn btn-outline-primary btn-sm">بررسی</a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>

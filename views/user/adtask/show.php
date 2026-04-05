<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">manage_accounts</span> مدیریت Adtask</h4>
    <p class="text-muted mb-0" style="font-size:12px;"><?= e(is_array($task) ? ($task[0]->title ?? '') : ($task->title ?? '')) ?></p>
  </div>
  <a href="<?= url('/adtask/advertise') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<?php $t = is_array($task) ? ($task[0] ?? null) : $task; ?>
<?php if(!$t): ?>
  <div class="alert alert-danger mt-3">تسک یافت نشد.</div>
<?php else: ?>
<?php
  $sc = ['draft'=>'secondary','review_pending'=>'warning','active'=>'success','paused'=>'info','completed'=>'primary','rejected'=>'danger','expired'=>'dark'];
  $sl = ['draft'=>'پیشنویس','review_pending'=>'در انتظار بررسی','active'=>'فعال','paused'=>'متوقف','completed'=>'تکمیل','rejected'=>'رد شده','expired'=>'منقضی'];
  $st = $t->status ?? 'draft';
?>
<div class="row mt-3">
  <div class="col-md-4">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">اطلاعات تسک</h6>
        <span class="badge bg-<?= $sc[$st] ?? 'secondary' ?>"><?= $sl[$st] ?? $st ?></span>
      </div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="text-muted">نوع</td><td><?= e($t->task_type ?? '—') ?></td></tr>
          <tr><td class="text-muted">پاداش/نفر</td><td class="text-success fw-bold"><?= number_format($t->reward_per_user ?? 0) ?> تومان</td></tr>
          <tr><td class="text-muted">ظرفیت</td><td><?= number_format($t->max_slots ?? 0) ?></td></tr>
          <tr><td class="text-muted">انجام‌شده</td><td><?= number_format($t->completed_slots ?? 0) ?></td></tr>
          <tr><td class="text-muted">ددلاین</td><td><?= $t->deadline ? e(substr($t->deadline, 0, 10)) : 'ندارد' ?></td></tr>
        </table>
        <?php $done = $t->completed_slots ?? 0; $total = max(1, $t->max_slots ?? 1); ?>
        <div class="progress mt-2" style="height:8px;">
          <div class="progress-bar bg-success" style="width:<?= min(100, ($done/$total)*100) ?>%"></div>
        </div>
        <small class="text-muted"><?= $done ?> از <?= $total ?> تکمیل شده</small>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">اجراها و مدارک دریافتی</h6>
        <span class="badge bg-secondary"><?= count($submissions ?? []) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if(empty($submissions)): ?>
          <div class="text-center py-4 text-muted">هنوز کسی این تسک را انجام نداده.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
              <tr><th>کاربر</th><th>مدرک</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th></tr>
            </thead>
            <tbody>
              <?php foreach($submissions as $sub): ?>
              <?php
                $ssc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','in_progress'=>'info'];
                $ssl = ['pending'=>'در انتظار','approved'=>'تایید ✓','rejected'=>'رد','in_progress'=>'بررسی'];
                $sst = $sub->status ?? 'pending';
              ?>
              <tr>
                <td><?= e($sub->user_name ?? $sub->user_id) ?></td>
                <td>
                  <?php if(!empty($sub->proof_text)): ?>
                    <small><?= e(mb_substr($sub->proof_text, 0, 40)) ?></small>
                  <?php elseif(!empty($sub->proof_file)): ?>
                    <a href="<?= e($sub->proof_file) ?>" target="_blank" class="btn btn-link btn-sm p-0">مشاهده فایل</a>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td><span class="badge bg-<?= $ssc[$sst] ?? 'secondary' ?>"><?= $ssl[$sst] ?? $sst ?></span></td>
                <td style="font-size:11px;"><?= e(substr($sub->created_at ?? '', 0, 10)) ?></td>
                <td>
                  <?php if($sst === 'pending'): ?>
                  <div class="d-flex gap-1">
                    <button class="btn btn-success btn-sm btn-review" data-id="<?= e($sub->id) ?>" data-action="approve" title="تایید">
                      <span class="material-icons" style="font-size:14px;">check</span>
                    </button>
                    <button class="btn btn-danger btn-sm btn-review" data-id="<?= e($sub->id) ?>" data-action="reject" title="رد">
                      <span class="material-icons" style="font-size:14px;">close</span>
                    </button>
                  </div>
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

<script>
document.querySelectorAll('.btn-review').forEach(btn => {
  btn.addEventListener('click', function() {
    const action = this.dataset.action;
    const label = action === 'approve' ? 'تایید' : 'رد';
    if(!confirm(`${label} این مدرک؟`)) return;
    fetch('/adtask/review', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''},
      body: JSON.stringify({submission_id: this.dataset.id, action})
    }).then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert(d.message || 'خطا'); });
  });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
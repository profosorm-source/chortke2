<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">assignment</span> Adtask — تسک‌های من</h4>
    <p class="text-muted mb-0" style="font-size:12px;">مدیریت تسک‌هایی که تعریف کرده‌اید</p>
  </div>
  <a href="<?= url('/adtask/advertise/create') ?>" class="btn btn-primary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">add</span> تسک جدید
  </a>
</div>

<?php if(empty($tasks)): ?>
<div class="card mt-4">
  <div class="card-body text-center py-5">
    <span class="material-icons text-muted" style="font-size:64px;">assignment_add</span>
    <h5 class="mt-3 text-muted">هنوز تسکی تعریف نکرده‌اید</h5>
    <p class="text-muted small">تسک سفارشی تعریف کنید تا کاربران آن را انجام دهند.</p>
    <a href="<?= url('/adtask/advertise/create') ?>" class="btn btn-primary mt-2">ثبت اولین تسک</a>
  </div>
</div>
<?php else: ?>
<div class="card mt-3">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>عنوان تسک</th>
            <th>پاداش/نفر</th>
            <th>ظرفیت</th>
            <th>انجام‌شده</th>
            <th>وضعیت</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($tasks as $t): ?>
          <?php
            $sc = ['draft'=>'secondary','review_pending'=>'warning','active'=>'success','paused'=>'info','completed'=>'primary','rejected'=>'danger','expired'=>'dark'];
            $st = $t->status ?? 'draft';
          ?>
          <tr>
            <td><?= e($t->id) ?></td>
            <td>
              <div class="fw-bold"><?= e(mb_substr($t->title, 0, 50)) ?><?= mb_strlen($t->title) > 50 ? '...' : '' ?></div>
              <small class="text-muted"><?= e($t->task_type ?? '') ?></small>
            </td>
            <td class="text-success fw-bold"><?= number_format($t->reward_per_user ?? 0) ?> تومان</td>
            <td><?= number_format($t->max_slots ?? 0) ?></td>
            <td>
              <?php $done = $t->completed_slots ?? 0; $total = $t->max_slots ?? 1; ?>
              <div class="d-flex align-items-center gap-1">
                <div class="progress flex-grow-1" style="height:6px;min-width:60px;">
                  <div class="progress-bar bg-success" style="width:<?= min(100, ($done/$total)*100) ?>%"></div>
                </div>
                <small><?= $done ?>/<?= $total ?></small>
              </div>
            </td>
            <td><span class="badge bg-<?= $sc[$st] ?? 'secondary' ?>"><?= e($statusLabels[$st] ?? $st) ?></span></td>
            <td>
              <a href="<?= url("/adtask/advertise/{$t->id}") ?>" class="btn btn-outline-secondary btn-sm" title="جزئیات">
                <span class="material-icons" style="font-size:16px;">visibility</span>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
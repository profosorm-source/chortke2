<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">list_alt</span> سفارش‌های تبلیغ من</h4>
    <p class="text-muted mb-0" style="font-size:12px;">سفارش‌هایی که برای تبلیغ در اینفلوئنسرها ثبت کرده‌اید</p>
  </div>
  <a href="<?= url('/influencer/advertise') ?>" class="btn btn-primary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">add</span> سفارش جدید
  </a>
</div>

<?php if(empty($orders)): ?>
<div class="card mt-4">
  <div class="card-body text-center py-5">
    <span class="material-icons text-muted" style="font-size:64px;">list_alt</span>
    <h5 class="mt-3 text-muted">سفارشی ثبت نکرده‌اید</h5>
    <p class="text-muted small">اینفلوئنسر مناسب را پیدا کنید و تبلیغ سفارش دهید.</p>
    <a href="<?= url('/influencer/advertise') ?>" class="btn btn-primary mt-2">دیدن اینفلوئنسرها</a>
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
            <th>اینفلوئنسر</th>
            <th>پلتفرم</th>
            <th>نوع</th>
            <th>مبلغ</th>
            <th>وضعیت</th>
            <th>تاریخ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($orders as $o): ?>
          <?php
            $sc = [
              'pending'       => 'warning',
              'accepted'      => 'info',
              'in_progress'   => 'primary',
              'proof_uploaded'=> 'info',
              'completed'     => 'success',
              'rejected'      => 'danger',
              'cancelled'     => 'secondary',
            ];
            $sl = [
              'pending'        => 'در انتظار پذیرش',
              'accepted'       => 'پذیرفته شد',
              'in_progress'    => 'در حال انجام',
              'proof_uploaded' => 'مدرک ارسال شد',
              'completed'      => 'تکمیل ✓',
              'rejected'       => 'رد شد',
              'cancelled'      => 'لغو شد',
            ];
            $st = $o->status ?? 'pending';
          ?>
          <tr>
            <td><?= e($o->id) ?></td>
            <td>
              <div class="fw-bold">@<?= e($o->influencer_username ?? '—') ?></div>
              <small class="text-muted"><?= number_format($o->follower_count ?? 0) ?> دنبال‌کننده</small>
            </td>
            <td>
              <span class="badge bg-<?= ($o->platform ?? '') === 'telegram' ? 'primary' : 'danger' ?>">
                <?= ($o->platform ?? '') === 'telegram' ? 'تلگرام' : 'اینستاگرام' ?>
              </span>
            </td>
            <td><small><?= e($o->order_type ?? '—') ?></small></td>
            <td class="fw-bold text-success"><?= number_format($o->price ?? 0) ?> <small>تومان</small></td>
            <td><span class="badge bg-<?= $sc[$st] ?? 'secondary' ?>"><?= $sl[$st] ?? $st ?></span></td>
            <td style="font-size:12px;"><?= e(substr($o->created_at ?? '', 0, 10)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if(($page ?? 1) > 1 || count($orders) >= 20): ?>
<div class="d-flex justify-content-center mt-3">
  <nav><ul class="pagination pagination-sm">
    <?php if($page > 1): ?><li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>">قبلی</a></li><?php endif; ?>
    <li class="page-item active"><a class="page-link" href="#"><?= $page ?></a></li>
    <?php if(count($orders) >= 20): ?><li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>">بعدی</a></li><?php endif; ?>
  </ul></nav>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
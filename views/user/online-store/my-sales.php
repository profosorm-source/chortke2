<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">sell</i> آگهی‌های فروش من</h4>
  <a href="<?= url('/online-store/sell/create') ?>" class="btn btn-primary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">add</i> آگهی جدید</a>
</div>
<?php if(empty($listings)): ?>
  <div class="alert alert-info text-center mt-4">هنوز آگهی فروش ثبت نکرده‌اید.</div>
<?php else: ?>
<div class="table-responsive mt-3"><table class="table">
  <thead><tr><th>عنوان</th><th>پلتفرم</th><th>قیمت</th><th>وضعیت</th><th>عملیات</th></tr></thead>
  <tbody>
  <?php foreach($listings as $l): ?>
  <tr>
    <td><?= e($l->title) ?><br><small dir="ltr" class="text-muted">@<?= e($l->username) ?></small></td>
    <td><?= e($l->platform) ?></td>
    <td><?= number_format($l->price_usdt,2) ?> USDT</td>
    <td><?php $s=$statuses[$l->status]??$l->status; $sc=['active'=>'bg-success','pending_verification'=>'bg-warning','sold'=>'bg-primary','in_escrow'=>'bg-info','disputed'=>'bg-danger','rejected'=>'bg-danger','cancelled'=>'bg-secondary']; ?><span class="badge <?= $sc[$l->status]??'bg-secondary' ?>"><?= e($s) ?></span></td>
    <td><a href="<?= url('/online-store/'.$l->id) ?>" class="btn btn-sm btn-outline-primary">مشاهده</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">campaign</i> تبلیغات Adsocial من</h4>
  <a href="<?= url('/adsocial/advertise/create') ?>" class="btn btn-primary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">add</i> تبلیغ جدید</a>
</div>
<?php if(empty($ads)): ?>
  <div class="alert alert-info text-center mt-4">هنوز تبلیغی ثبت نکرده‌اید.<br><a href="<?= url('/adsocial/advertise/create') ?>" class="btn btn-primary mt-2">اولین تبلیغ را ثبت کنید</a></div>
<?php else: ?>
<div class="table-responsive mt-3"><table class="table">
  <thead><tr><th>عنوان</th><th>پلتفرم</th><th>وضعیت</th><th>اجراها</th><th>عملیات</th></tr></thead>
  <tbody>
  <?php foreach($ads as $ad): ?>
  <tr>
    <td><?= e($ad->title) ?></td>
    <td><span class="badge bg-secondary"><?= e($ad->platform??'') ?></span></td>
    <td><?php $sc=['active'=>'bg-success','paused'=>'bg-warning','pending'=>'bg-info','rejected'=>'bg-danger','cancelled'=>'bg-secondary']; ?><span class="badge <?= $sc[$ad->status]??'bg-secondary' ?>"><?= e($ad->status) ?></span></td>
    <td><?= number_format($ad->execution_count??0) ?></td>
    <td><a href="<?= url('/adsocial/advertise/'.$ad->id) ?>" class="btn btn-sm btn-outline-primary">مدیریت</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>

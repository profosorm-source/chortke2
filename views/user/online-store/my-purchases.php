<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">shopping_bag</i> خریدهای من</h4>
  <a href="<?= url('/online-store') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت به بازار</a>
</div>
<?php if(empty($listings)): ?>
  <div class="alert alert-info text-center mt-4">هنوز خریدی انجام نداده‌اید.</div>
<?php else: ?>
<div class="table-responsive mt-3"><table class="table">
  <thead><tr><th>عنوان</th><th>پلتفرم</th><th>قیمت</th><th>فروشنده</th><th>وضعیت</th><th>عملیات</th></tr></thead>
  <tbody>
  <?php foreach($listings as $l): ?>
  <tr>
    <td><?= e($l->title) ?></td>
    <td><?= e($l->platform) ?></td>
    <td><?= number_format($l->price_usdt,2) ?> USDT</td>
    <td><?= e($l->seller_name??'—') ?></td>
    <td><?php $sc=['in_escrow'=>'bg-warning','sold'=>'bg-success','disputed'=>'bg-danger']; ?><span class="badge <?= $sc[$l->status]??'bg-secondary' ?>"><?= e($statuses[$l->status]??$l->status) ?></span></td>
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
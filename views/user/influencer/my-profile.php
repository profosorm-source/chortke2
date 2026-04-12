<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">stars</i> Influencer — پروفایل من</h4>
  <a href="<?= url('/influencer/register') ?>" class="btn btn-<?= $profile?'outline-primary':'primary' ?> btn-sm"><?= $profile?'ویرایش پروفایل':'ثبت پروفایل' ?></a>
</div>
<?php if(!$profile): ?>
  <div class="alert alert-info mt-3 text-center">هنوز پروفایل اینفلوئنسر ندارید.<br><a href="<?= url('/influencer/register') ?>" class="btn btn-primary mt-2">ثبت پروفایل</a></div>
<?php else: ?>
<div class="card mt-3"><div class="card-body">
  <div class="d-flex gap-3 align-items-start">
    <?php if(!empty($profile->profile_image)): ?><img src="<?= e($profile->profile_image) ?>" class="rounded-circle" style="width:80px;height:80px;object-fit:cover;"><?php endif; ?>
    <div class="flex-grow-1">
      <h5 class="mb-1">@<?= e($profile->username) ?></h5>
      <span class="badge bg-info"><?= e($platforms[$profile->platform]??$profile->platform) ?></span>
      <?php $sc=['pending'=>'bg-warning','verified'=>'bg-success','rejected'=>'bg-danger','suspended'=>'bg-secondary']; ?><span class="badge <?= $sc[$profile->status]??'bg-secondary' ?> ms-1"><?= e($profile->status) ?></span>
      <div class="small text-muted mt-1"><?= number_format($profile->follower_count??0) ?> فالوور | <?= number_format($profile->completed_orders??0) ?> سفارش تکمیل‌شده</div>
    </div>
  </div>
  <?php if($profile->status==='verified'): ?>
  <div class="row mt-3 text-center border-top pt-3">
    <?php if($profile->platform==='instagram'): ?>
    <div class="col-3"><div class="fw-bold"><?= number_format($profile->story_price_24h??0) ?></div><small>استوری ۲۴h</small></div>
    <div class="col-3"><div class="fw-bold"><?= number_format($profile->post_price_24h??0) ?></div><small>پست ۲۴h</small></div>
    <?php elseif($profile->platform==='telegram'): ?>
    <div class="col-3"><div class="fw-bold"><?= number_format($profile->sponsored_post_price??0) ?></div><small>پست اسپانسری</small></div>
    <div class="col-3"><div class="fw-bold"><?= number_format($profile->pin_price??0) ?></div><small>پین</small></div>
    <div class="col-3"><div class="fw-bold"><?= number_format($profile->forward_price??0) ?></div><small>فوروارد</small></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div></div>
<?php if(!empty($orders)): ?>
<div class="mt-3"><h6>آخرین سفارش‌ها</h6>
<?php foreach($orders as $o): ?>
<div class="card mb-2"><div class="card-body py-2 d-flex justify-content-between">
  <span><?= e($o->title??'سفارش #'.$o->id) ?></span>
  <span class="badge bg-info"><?= e($o->status) ?></span>
</div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
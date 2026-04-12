<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">auto_awesome</i> پیدا کردن اینفلوئنسر</h4>
  <a href="<?= url('/influencer/advertise/my-orders') ?>" class="btn btn-outline-secondary btn-sm">سفارش‌های من</a>
</div>
<div class="card mt-3"><div class="card-body py-2">
  <form method="GET" class="row g-2">
    <div class="col-md-3"><select name="platform" class="form-select form-select-sm"><option value="">همه پلتفرم‌ها</option><?php foreach($platforms as $k=>$v): ?><option value="<?= e($k) ?>" <?= ($filters['platform']??'')===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><select name="category" class="form-select form-select-sm"><option value="">همه دسته‌ها</option><?php foreach($categories as $cat): ?><option value="<?= e($cat) ?>" <?= ($filters['category']??'')===$cat?'selected':'' ?>><?= e($cat) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><input type="text" name="search" class="form-control form-control-sm" placeholder="جستجو..." value="<?= e($filters['search']??'') ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">فیلتر</button></div>
  </form>
</div></div>
<?php if(empty($profiles)): ?>
  <div class="alert alert-info text-center mt-4">اینفلوئنسری پیدا نشد.</div>
<?php else: ?>
<div class="row mt-3">
<?php foreach($profiles as $p): ?>
<div class="col-md-6 col-lg-4 mb-3">
  <div class="card h-100"><div class="card-body">
    <div class="d-flex gap-2 align-items-center mb-2">
      <?php if(!empty($p->profile_image)): ?><img src="<?= e($p->profile_image) ?>" class="rounded-circle" style="width:50px;height:50px;object-fit:cover;"><?php endif; ?>
      <div><div class="fw-bold">@<?= e($p->username) ?></div><span class="badge bg-info"><?= e($platforms[$p->platform]??$p->platform) ?></span></div>
    </div>
    <div class="small text-muted mb-1"><?= number_format($p->follower_count??0) ?> فالوور</div>
    <?php if(!empty($p->category)): ?><div class="small mb-1"><?= e($p->category) ?></div><?php endif; ?>
    <div class="small text-muted mb-2 text-truncate"><?= e($p->bio??'') ?></div>
    <?php if($p->platform==='instagram' && $p->story_price_24h>0): ?><div class="small text-success">استوری: <?= number_format($p->story_price_24h) ?></div><?php endif; ?>
    <?php if($p->platform==='telegram' && $p->sponsored_post_price>0): ?><div class="small text-success">پست اسپانسری: <?= number_format($p->sponsored_post_price) ?></div><?php endif; ?>
    <a href="<?= url('/influencer/advertise/create?influencer_id='.$p->id) ?>" class="btn btn-primary btn-sm w-100 mt-2">ثبت سفارش</a>
  </div></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><i class="material-icons text-primary">storefront</i> Online Store — بازار پیج/کانال</h4>
    <p class="text-muted mb-0" style="font-size:12px;">خرید و فروش پیج و کانال — فقط USDT — دارای escrow</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= url('/online-store/sell') ?>" class="btn btn-outline-secondary btn-sm">آگهی‌های من</a>
    <a href="<?= url('/online-store/sell/create') ?>" class="btn btn-primary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">add</i> فروش</a>
  </div>
</div>

<div class="card mt-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-md-4"><select name="platform" class="form-select form-select-sm"><option value="">همه پلتفرم‌ها</option><?php foreach($platforms as $k=>$v): ?><option value="<?= e($k) ?>" <?= ($filters['platform']??'')===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><input type="text" name="search" class="form-control form-control-sm" placeholder="جستجو..." value="<?= e($filters['search']??'') ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">فیلتر</button></div>
  </form>
</div></div>

<?php if(empty($listings)): ?>
  <div class="alert alert-info text-center mt-4">آگهی فعالی یافت نشد.</div>
<?php else: ?>
<div class="row mt-3">
<?php foreach($listings as $l): ?>
<div class="col-md-6 col-lg-4 mb-3">
  <div class="card h-100">
    <div class="card-body">
      <div class="d-flex justify-content-between mb-2">
        <span class="badge bg-info"><?= e($platforms[$l->platform]??$l->platform) ?></span>
        <span class="fw-bold text-success"><?= number_format($l->price_usdt,2) ?> USDT</span>
      </div>
      <h6 class="fw-bold"><?= e($l->title) ?></h6>
      <div class="small text-muted mb-1">@<?= e($l->username) ?></div>
      <div class="small text-muted"><?= number_format($l->member_count??0) ?> عضو/فالوور</div>
      <a href="<?= url('/online-store/'.$l->id) ?>" class="btn btn-sm btn-outline-primary w-100 mt-2">مشاهده و خرید</a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1">
      <span class="material-icons text-primary">manage_search</span> SEO Ad — تبلیغات من
    </h4>
    <p class="text-muted mb-0" style="font-size:12px;">
      سایت خود را ثبت کنید — کاربران با جستجوی کلمه کلیدی به سایت شما می‌رسند
    </p>
  </div>
  <a href="<?= url('/seo-ad/create') ?>" class="btn btn-primary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">add</span> تبلیغ جدید
  </a>
</div>

<?php if(empty($ads)): ?>
<div class="card mt-4">
  <div class="card-body text-center py-5">
    <span class="material-icons text-muted" style="font-size:64px;">travel_explore</span>
    <h5 class="mt-3 text-muted">هنوز تبلیغ SEO Ad ندارید</h5>
    <p class="text-muted small">
      بودجه‌ای تعیین کنید — هر بار که کاربری کلمه کلیدی شما را جستجو کرد و
      کلیک کرد، مبلغ مشخصی از بودجه کسر می‌شود.
    </p>
    <a href="<?= url('/seo-ad/create') ?>" class="btn btn-primary mt-2">ثبت اولین تبلیغ</a>
  </div>
</div>
<?php else: ?>
<div class="row mt-3">
<?php foreach($ads as $ad): ?>
<?php
  $sc = ['pending'=>'warning','active'=>'success','paused'=>'secondary','rejected'=>'danger','exhausted'=>'dark'];
  $sl = ['pending'=>'در انتظار','active'=>'فعال','paused'=>'متوقف','rejected'=>'رد شده','exhausted'=>'بودجه تمام'];
  $st = $ad->status ?? 'pending';
  $pct = $ad->budget > 0 ? (1 - $ad->remaining_budget / $ad->budget) * 100 : 0;
?>
<div class="col-md-6 mb-3">
  <div class="card h-100">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <h6 class="fw-bold mb-1"><?= e($ad->title) ?></h6>
          <span class="badge bg-info"><?= e($ad->keyword) ?></span>
        </div>
        <span class="badge bg-<?= $sc[$st] ?>"><?= $sl[$st] ?></span>
      </div>

      <a href="<?= e($ad->site_url) ?>" target="_blank"
         class="text-muted small text-truncate d-block mb-3" style="max-width:100%;">
        <?= e($ad->site_url) ?>
      </a>

      <div class="row text-center small mb-2">
        <div class="col-4">
          <div class="fw-bold text-primary"><?= number_format($ad->clicks_count ?? 0) ?></div>
          <div class="text-muted">کلیک</div>
        </div>
        <div class="col-4">
          <div class="fw-bold text-success"><?= number_format($ad->budget) ?></div>
          <div class="text-muted">بودجه (تومان)</div>
        </div>
        <div class="col-4">
          <div class="fw-bold text-warning"><?= number_format($ad->remaining_budget) ?></div>
          <div class="text-muted">باقی‌مانده</div>
        </div>
      </div>

      <div class="progress mb-3" style="height:5px;" title="<?= round($pct) ?>٪ مصرف شده">
        <div class="progress-bar bg-danger" style="width:<?= min(100,$pct) ?>%"></div>
      </div>

      <div class="d-flex gap-2">
        <a href="<?= url("/seo-ad/{$ad->id}") ?>" class="btn btn-outline-secondary btn-sm flex-fill">جزئیات</a>
        <?php if($st === 'active'): ?>
        <form method="POST" action="<?= url("/seo-ad/{$ad->id}/pause") ?>" class="flex-fill">
          <?= csrf_field() ?>
          <button class="btn btn-outline-warning btn-sm w-100">توقف</button>
        </form>
        <?php elseif($st === 'paused'): ?>
        <form method="POST" action="<?= url("/seo-ad/{$ad->id}/resume") ?>" class="flex-fill">
          <?= csrf_field() ?>
          <button class="btn btn-outline-success btn-sm w-100">ادامه</button>
        </form>
        <?php endif; ?>
      </div>
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
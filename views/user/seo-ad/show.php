<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1">
      <span class="material-icons text-primary">manage_search</span> جزئیات SEO Ad
    </h4>
    <p class="text-muted mb-0" style="font-size:12px;"><?= e($ad->keyword ?? '') ?></p>
  </div>
  <a href="<?= url('/seo-ad') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<?php
  $sc = ['pending'=>'warning','active'=>'success','paused'=>'secondary','rejected'=>'danger','exhausted'=>'dark'];
  $sl = ['pending'=>'در انتظار تایید','active'=>'فعال','paused'=>'متوقف','rejected'=>'رد شده','exhausted'=>'بودجه تمام'];
  $st = $ad->status ?? 'pending';
  $spent = $ad->budget - $ad->remaining_budget;
  $pct   = $ad->budget > 0 ? ($spent / $ad->budget) * 100 : 0;
?>

<div class="row mt-3">
  <!-- اطلاعات -->
  <div class="col-md-7">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">اطلاعات تبلیغ</h6>
        <span class="badge bg-<?= $sc[$st] ?> fs-6"><?= $sl[$st] ?></span>
      </div>
      <div class="card-body">
        <table class="table table-bordered table-sm">
          <tr>
            <th width="35%">کلمه کلیدی</th>
            <td><span class="badge bg-info fs-6"><?= e($ad->keyword) ?></span></td>
          </tr>
          <tr>
            <th>عنوان نمایشی</th>
            <td><?= e($ad->title) ?></td>
          </tr>
          <tr>
            <th>آدرس سایت</th>
            <td>
              <a href="<?= e($ad->site_url) ?>" target="_blank">
                <?= e($ad->site_url) ?>
              </a>
            </td>
          </tr>
          <?php if($ad->description): ?>
          <tr>
            <th>توضیحات</th>
            <td><?= e($ad->description) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <th>هزینه هر کلیک</th>
            <td><?= number_format($ad->price_per_click) ?> تومان</td>
          </tr>
          <tr>
            <th>تاریخ پایان</th>
            <td><?= $ad->deadline ? e(substr($ad->deadline, 0, 10)) : 'بدون محدودیت' ?></td>
          </tr>
          <tr>
            <th>تاریخ ثبت</th>
            <td><?= e(substr($ad->created_at, 0, 10)) ?></td>
          </tr>
        </table>

        <?php if($ad->rejection_reason): ?>
        <div class="alert alert-danger mt-2 small">
          <strong>دلیل رد:</strong> <?= e($ad->rejection_reason) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- آمار + کنترل -->
  <div class="col-md-5">
    <div class="card mb-3">
      <div class="card-header"><h6 class="mb-0">آمار بودجه</h6></div>
      <div class="card-body">
        <div class="text-center mb-3">
          <div class="fs-1 fw-bold text-primary"><?= number_format($ad->clicks_count ?? 0) ?></div>
          <div class="text-muted">کل کلیک‌های تایید شده</div>
        </div>
        <hr>
        <div class="d-flex justify-content-between small mb-1">
          <span class="text-muted">بودجه کل:</span>
          <strong><?= number_format($ad->budget) ?> تومان</strong>
        </div>
        <div class="d-flex justify-content-between small mb-1">
          <span class="text-muted">مصرف شده:</span>
          <strong class="text-danger"><?= number_format($spent) ?> تومان</strong>
        </div>
        <div class="d-flex justify-content-between small mb-2">
          <span class="text-muted">باقی‌مانده:</span>
          <strong class="text-success"><?= number_format($ad->remaining_budget) ?> تومان</strong>
        </div>
        <div class="progress" style="height:8px;">
          <div class="progress-bar bg-danger" style="width:<?= min(100,$pct) ?>%"></div>
        </div>
        <small class="text-muted"><?= round($pct) ?>٪ مصرف شده</small>
      </div>
    </div>

    <?php if(in_array($st, ['active','paused'])): ?>
    <div class="card">
      <div class="card-body d-grid gap-2">
        <?php if($st === 'active'): ?>
        <form method="POST" action="<?= url("/seo-ad/{$ad->id}/pause") ?>">
          <?= csrf_field() ?>
          <button class="btn btn-warning w-100">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">pause</span>
            توقف موقت
          </button>
        </form>
        <?php else: ?>
        <form method="POST" action="<?= url("/seo-ad/{$ad->id}/resume") ?>">
          <?= csrf_field() ?>
          <button class="btn btn-success w-100">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">play_arrow</span>
            ادامه تبلیغ
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
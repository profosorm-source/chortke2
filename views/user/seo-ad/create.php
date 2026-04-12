<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1">
      <span class="material-icons text-primary">manage_search</span> ثبت تبلیغ SEO Ad جدید
    </h4>
    <p class="text-muted mb-0" style="font-size:12px;">
      سایت خود را برای نمایش در نتایج جستجوی کاربران ثبت کنید
    </p>
  </div>
  <a href="<?= url('/seo-ad') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<div class="row mt-3 justify-content-center">
  <div class="col-md-8">

    <!-- توضیح سیستم -->
    <div class="card mb-3 border-primary">
      <div class="card-body py-3">
        <div class="d-flex gap-3 align-items-start">
          <span class="material-icons text-primary mt-1">info</span>
          <div class="small">
            <div class="fw-bold mb-1">چطور کار می‌کند؟</div>
            <ul class="mb-0 ps-3">
              <li>کاربران در بخش «SEO Search» یک کلمه کلیدی جستجو می‌کنند</li>
              <li>اگر کلمه کلیدی شما با جستجو تطابق داشت، سایت شما نمایش داده می‌شود</li>
              <li>با هر کلیک کاربر، <strong><?= number_format($pricePerClick) ?> تومان</strong> از بودجه شما کسر می‌شود</li>
              <li>کاربر نیز همان مبلغ را به عنوان پاداش دریافت می‌کند</li>
              <li>وقتی بودجه تمام شد، تبلیغ خودکار متوقف می‌شود</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <form method="POST" action="<?= url('/seo-ad/store') ?>">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label class="form-label fw-bold">
              کلمه کلیدی هدف <span class="text-danger">*</span>
            </label>
            <input type="text" name="keyword" class="form-control"
              placeholder="مثال: خرید کتاب، آموزش آنلاین" required maxlength="100"
              value="<?= e($_POST['keyword'] ?? '') ?>">
            <small class="text-muted">
              کاربران با جستجوی این عبارت، سایت شما را خواهند دید
            </small>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">
              آدرس سایت <span class="text-danger">*</span>
            </label>
            <input type="url" name="site_url" class="form-control"
              placeholder="https://example.com" required
              value="<?= e($_POST['site_url'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">
              عنوان نمایشی <span class="text-danger">*</span>
            </label>
            <input type="text" name="title" class="form-control"
              placeholder="مثال: فروشگاه آنلاین کتاب — بهترین قیمت"
              required maxlength="150"
              value="<?= e($_POST['title'] ?? '') ?>">
            <small class="text-muted">این عنوان زیر لینک سایت شما نمایش داده می‌شود</small>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">توضیح کوتاه (اختیاری)</label>
            <textarea name="description" class="form-control" rows="2"
              maxlength="200"
              placeholder="یک جمله درباره سایت یا محصول..."><?= e($_POST['description'] ?? '') ?></textarea>
          </div>

          <div class="row mb-3">
            <div class="col-md-7">
              <label class="form-label fw-bold">
                بودجه کل (تومان) <span class="text-danger">*</span>
              </label>
              <input type="number" name="budget" class="form-control"
                id="budgetInput"
                min="<?= $minBudget ?>" step="1000"
                placeholder="حداقل <?= number_format($minBudget) ?> تومان"
                required value="<?= e($_POST['budget'] ?? '') ?>">
              <small class="text-muted">
                همین الان از کیف پول کسر می‌شود
              </small>
            </div>
            <div class="col-md-5">
              <label class="form-label fw-bold">تخمین تعداد کلیک</label>
              <div class="form-control bg-light" id="clickEstimate">—</div>
              <small class="text-muted"><?= number_format($pricePerClick) ?> تومان/کلیک</small>
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label fw-bold">تاریخ پایان (اختیاری)</label>
            <input type="date" name="deadline" class="form-control"
              min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
              value="<?= e($_POST['deadline'] ?? '') ?>">
            <small class="text-muted">
              اگر خالی بگذارید، تبلیغ تا اتمام بودجه ادامه دارد
            </small>
          </div>

          <div class="d-flex justify-content-end gap-2">
            <a href="<?= url('/seo-ad') ?>" class="btn btn-outline-secondary">انصراف</a>
            <button type="submit" class="btn btn-primary">
              <span class="material-icons" style="font-size:16px;vertical-align:middle;">save</span>
              ثبت و کسر بودجه
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
const ppc = <?= (float)$pricePerClick ?>;
document.getElementById('budgetInput')?.addEventListener('input', function() {
  const b = parseFloat(this.value) || 0;
  const est = b > 0 && ppc > 0 ? Math.floor(b / ppc) : 0;
  document.getElementById('clickEstimate').textContent =
    est > 0 ? est.toLocaleString('fa-IR') + ' کلیک' : '—';
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
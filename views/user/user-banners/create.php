<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">view_carousel</span> ثبت بنر تبلیغاتی جدید</h4>
    <p class="text-muted mb-0" style="font-size:12px;">بنر شما در جایگاه‌های انتخابی سایت نمایش داده می‌شود</p>
  </div>
  <a href="<?= url('/my-banners') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<div class="row mt-3 justify-content-center">
  <div class="col-md-8">
    <?php if($pricePerDay > 0): ?>
    <div class="alert alert-warning small">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">payments</span>
      هزینه: <strong><?= number_format($pricePerDay) ?> تومان</strong> در روز — از کیف پول کسر می‌شود
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <form method="POST" action="<?= url('/my-banners/store') ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label class="form-label fw-bold">عنوان بنر <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control" required maxlength="200" placeholder="نام کسب‌وکار یا محصول">
          </div>

          <?php if(!empty($placements)): ?>
          <div class="mb-3">
            <label class="form-label fw-bold">جایگاه نمایش</label>
            <select name="placement_id" class="form-select">
              <option value="">انتخاب جایگاه (اختیاری)</option>
              <?php foreach($placements as $p): ?>
              <option value="<?= e($p->id) ?>"><?= e($p->name) ?> — <?= e($p->description ?? '') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label fw-bold">تصویر بنر <span class="text-danger">*</span></label>
            <input type="file" name="image" class="form-control" required accept="image/jpeg,image/png,image/webp">
            <small class="text-muted">فرمت: JPG, PNG, WEBP — حداکثر ۲MB — پیشنهاد: ۷۲۸×۹۰</small>
            <div id="imgPreview" class="mt-2 d-none">
              <img id="imgPreviewEl" style="max-width:100%;max-height:90px;border-radius:4px;">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">لینک هدف</label>
            <input type="url" name="link_url" class="form-control" placeholder="https://...">
            <small class="text-muted">کاربران پس از کلیک به این آدرس هدایت می‌شوند</small>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">تعداد روز <span class="text-danger">*</span></label>
            <input type="number" name="days" class="form-control" min="1" max="90" value="7" required id="daysInput">
            <?php if($pricePerDay > 0): ?>
            <div class="mt-1 small text-muted">هزینه کل: <strong id="totalCost"><?= number_format($pricePerDay * 7) ?></strong> تومان</div>
            <?php endif; ?>
          </div>

          <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="<?= url('/my-banners') ?>" class="btn btn-outline-secondary">انصراف</a>
            <button type="submit" class="btn btn-primary">
              <span class="material-icons" style="font-size:16px;vertical-align:middle;">save</span> ثبت بنر
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Preview image
document.querySelector('input[name=image]')?.addEventListener('change', function() {
  const f = this.files[0]; if(!f) return;
  const r = new FileReader();
  r.onload = e => { document.getElementById('imgPreviewEl').src = e.target.result; document.getElementById('imgPreview').classList.remove('d-none'); };
  r.readAsDataURL(f);
});
// Update cost
const pricePerDay = <?= (float)($pricePerDay ?? 0) ?>;
document.getElementById('daysInput')?.addEventListener('input', function() {
  const cost = Math.round(pricePerDay * (parseInt(this.value) || 0));
  const el = document.getElementById('totalCost');
  if(el) el.textContent = cost.toLocaleString('fa-IR');
});
</script>

<?php $content = ob_get_clean(); require layout_path($layout); ?>

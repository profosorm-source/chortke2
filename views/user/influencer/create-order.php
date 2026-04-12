<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">auto_awesome</span> ثبت سفارش تبلیغ Influencer</h4>
    <p class="text-muted mb-0" style="font-size:12px;">تبلیغ خود را در اینستاگرام یا تلگرام اینفلوئنسر سفارش دهید</p>
  </div>
  <a href="<?= url('/influencer/advertise') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<?php if($influencer): ?>
<div class="alert alert-info d-flex align-items-center gap-3 mt-3">
  <?php if(!empty($influencer->profile_image)): ?>
  <img src="<?= e($influencer->profile_image) ?>" class="rounded-circle" style="width:50px;height:50px;object-fit:cover;">
  <?php endif; ?>
  <div>
    <div class="fw-bold">@<?= e($influencer->username) ?></div>
    <small class="text-muted"><?= number_format($influencer->follower_count ?? 0) ?> دنبال‌کننده — <?= e($influencer->platform === 'telegram' ? 'تلگرام' : 'اینستاگرام') ?></small>
  </div>
</div>
<?php endif; ?>

<div class="row mt-3 justify-content-center">
  <div class="col-md-8">
    <div class="card">
      <div class="card-body">
        <form method="POST" action="<?= url('/influencer/advertise/store') ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <?php if($influencer): ?>
          <input type="hidden" name="influencer_id" value="<?= e($influencer->id) ?>">
          <?php else: ?>
          <div class="mb-3">
            <label class="form-label fw-bold">شناسه اینفلوئنسر <span class="text-danger">*</span></label>
            <input type="number" name="influencer_id" class="form-control" required placeholder="ID اینفلوئنسر">
          </div>
          <?php endif; ?>

          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">پلتفرم <span class="text-danger">*</span></label>
              <select name="platform" class="form-select" id="platformSel" required>
                <?php foreach($platforms as $k => $v): ?>
                  <option value="<?= e($k) ?>" <?= ($influencer->platform ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">نوع تبلیغ <span class="text-danger">*</span></label>
              <select name="order_type" class="form-select" id="orderTypeSel" required>
                <!-- Instagram -->
                <optgroup label="اینستاگرام" id="igGroup">
                  <option value="story_24h">استوری ۲۴ ساعته<?php if($influencer && $influencer->story_price_24h): ?> — <?= number_format($influencer->story_price_24h) ?> تومان<?php endif; ?></option>
                  <option value="post_24h">پست ۲۴ ساعته<?php if($influencer && $influencer->post_price_24h): ?> — <?= number_format($influencer->post_price_24h) ?> تومان<?php endif; ?></option>
                  <option value="post_48h">پست ۴۸ ساعته<?php if($influencer && $influencer->post_price_48h): ?> — <?= number_format($influencer->post_price_48h) ?> تومان<?php endif; ?></option>
                </optgroup>
                <!-- Telegram -->
                <optgroup label="تلگرام" id="tgGroup">
                  <option value="sponsored_post">پست اسپانسری<?php if($influencer && ($influencer->sponsored_post_price ?? 0)): ?> — <?= number_format($influencer->sponsored_post_price) ?> تومان<?php endif; ?></option>
                  <option value="pin">پین پیام<?php if($influencer && ($influencer->pin_price ?? 0)): ?> — <?= number_format($influencer->pin_price) ?> تومان<?php endif; ?></option>
                  <option value="forward">فوروارد<?php if($influencer && ($influencer->forward_price ?? 0)): ?> — <?= number_format($influencer->forward_price) ?> تومان<?php endif; ?></option>
                </optgroup>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">توضیحات محتوا / بریف <span class="text-danger">*</span></label>
            <textarea name="brief_text" class="form-control" rows="4" required
              placeholder="محتوایی که می‌خواهید تبلیغ شود را توضیح دهید..."></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">فایل پیوست (اختیاری)</label>
            <input type="file" name="brief_file" class="form-control" accept="image/*,.pdf,.doc,.docx">
            <small class="text-muted">تصویر، لوگو یا فایل راهنما</small>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">تاریخ مورد نظر</label>
            <input type="date" name="requested_date" class="form-control"
              min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
          </div>

          <div class="alert alert-warning small">
            <span class="material-icons" style="font-size:16px;vertical-align:middle;">payments</span>
            هزینه پس از تایید اینفلوئنسر از کیف پول شما کسر می‌شود. در صورت عدم تایید، مبلغ برگشت داده می‌شود.
          </div>

          <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="<?= url('/influencer/advertise') ?>" class="btn btn-outline-secondary">انصراف</a>
            <button type="submit" class="btn btn-primary">
              <span class="material-icons" style="font-size:16px;vertical-align:middle;">send</span> ارسال سفارش
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
const platformSel = document.getElementById('platformSel');
const igGroup = document.getElementById('igGroup');
const tgGroup = document.getElementById('tgGroup');
function updateOrderTypes() {
  const p = platformSel.value;
  igGroup.style.display = p === 'instagram' ? '' : 'none';
  tgGroup.style.display = p === 'telegram' ? '' : 'none';
  // Select first visible option
  const firstVis = document.querySelector(`#orderTypeSel optgroup[id="${p === 'instagram' ? 'igGroup' : 'tgGroup'}"] option`);
  if(firstVis) firstVis.selected = true;
}
platformSel?.addEventListener('change', updateOrderTypes);
updateOrderTypes();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">sell</i> ثبت آگهی فروش</h4>
  <a href="<?= url('/online-store/sell') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت</a>
</div>
<div class="alert alert-warning mt-3 small"><i class="material-icons" style="font-size:16px;vertical-align:middle;">info</i> <strong>مهم:</strong> پس از ثبت آگهی باید آدرس سایت <strong><?= e($siteUrl) ?></strong> را در بیو پیج/کانال خود قرار دهید تا مدیر تایید بیو را انجام دهد. پس از تایید، آگهی در بازار نمایش داده می‌شود.</div>
<form action="<?= url('/online-store/sell/store') ?>" method="POST" enctype="multipart/form-data" class="mt-3">
  <?= csrf_field() ?>
  <div class="card"><div class="card-header"><h6 class="card-title mb-0">اطلاعات پیج/کانال</h6></div><div class="card-body">
    <div class="row">
      <div class="col-md-4 mb-3"><label class="form-label">پلتفرم <span class="text-danger">*</span></label>
        <select name="platform" class="form-select" required>
        <?php foreach($platforms as $k=>$v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 mb-3"><label class="form-label">نام کاربری/کانال <span class="text-danger">*</span></label><input type="text" name="username" class="form-control" required placeholder="@username"></div>
      <div class="col-md-4 mb-3"><label class="form-label">تعداد عضو/فالوور</label><input type="number" name="member_count" class="form-control" min="0"></div>
    </div>
    <div class="mb-3"><label class="form-label">لینک صفحه <span class="text-danger">*</span></label><input type="url" name="page_url" class="form-control" required placeholder="https://t.me/..."></div>
    <div class="mb-3"><label class="form-label">عنوان آگهی <span class="text-danger">*</span></label><input type="text" name="title" class="form-control" required maxlength="300"></div>
    <div class="mb-3"><label class="form-label">توضیحات</label><textarea name="description" class="form-control" rows="4"></textarea></div>
    <div class="row">
      <div class="col-md-6 mb-3"><label class="form-label">قیمت فروش (USDT) <span class="text-danger">*</span></label><input type="number" name="price_usdt" class="form-control" min="1" step="0.01" required></div>
      <div class="col-md-6 mb-3"><label class="form-label">تاریخ تأسیس</label><input type="date" name="creation_date" class="form-control"></div>
    </div>
    <div class="mb-3"><label class="form-label">اسکرین‌شات‌های آماری (حداکثر ۳ تصویر)</label>
      <input type="file" name="screenshot_1" class="form-control mb-1" accept="image/*">
      <input type="file" name="screenshot_2" class="form-control mb-1" accept="image/*">
      <input type="file" name="screenshot_3" class="form-control" accept="image/*">
    </div>
    <div class="mb-3"><label class="form-label">توضیحات برای تایید بیو</label><textarea name="proof_text" class="form-control" rows="2" placeholder="توضیح دهید که چطور آدرس سایت را در بیو قرار می‌دهید..."></textarea></div>
  </div></div>
  <div class="mt-3"><button type="submit" class="btn btn-primary px-4">ثبت آگهی</button></div>
</form>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
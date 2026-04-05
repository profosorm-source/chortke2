<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">add_task</span> ثبت Adtask جدید</h4>
    <p class="text-muted mb-0" style="font-size:12px;">تسک سفارشی تعریف کنید — کاربران انجام می‌دهند و پاداش می‌گیرند</p>
  </div>
  <a href="<?= url('/adtask/advertise') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<div class="row mt-3 justify-content-center">
  <div class="col-md-9">
    <?php if($feePercent ?? 0): ?>
    <div class="alert alert-info small">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">info</span>
      کارمزد سایت: <strong><?= $feePercent ?>٪</strong> از کل بودجه — مبلغ خالص به هر کاربر پرداخت می‌شود
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <form method="POST" action="<?= url('/adtask/advertise/store') ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>

          <div class="row mb-3">
            <div class="col-md-8">
              <label class="form-label fw-bold">عنوان تسک <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required maxlength="200" placeholder="مثال: نصب اپلیکیشن و ثبت‌نام">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">نوع تسک <span class="text-danger">*</span></label>
              <select name="task_type" class="form-select" required>
                <?php foreach($taskTypes ?? [] as $k => $v): ?>
                <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">توضیحات کامل <span class="text-danger">*</span></label>
            <textarea name="description" class="form-control" rows="4" required placeholder="مراحل انجام تسک را دقیق توضیح دهید..."></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold">لینک مرتبط (اختیاری)</label>
            <input type="url" name="target_url" class="form-control" placeholder="https://...">
            <small class="text-muted">لینک اپ، سایت یا صفحه‌ای که تسک روی آن انجام می‌شود</small>
          </div>

          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">نوع مدرک ارسالی <span class="text-danger">*</span></label>
              <select name="proof_type" class="form-select" required>
                <?php foreach($proofTypes ?? [] as $k => $v): ?>
                <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">توضیح مدرک</label>
              <input type="text" name="proof_description" class="form-control" placeholder="مثال: اسکرین‌شات از صفحه پروفایل">
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-md-4">
              <label class="form-label fw-bold">پاداش هر نفر (تومان) <span class="text-danger">*</span></label>
              <input type="number" name="reward_per_user" class="form-control" min="100" step="100" required id="rewardInput">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">تعداد کاربر مورد نیاز <span class="text-danger">*</span></label>
              <input type="number" name="max_slots" class="form-control" min="1" max="10000" value="10" required id="slotsInput">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">بودجه کل (تخمین)</label>
              <div class="form-control bg-light" id="totalBudget">—</div>
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">ددلاین (اختیاری)</label>
              <input type="datetime-local" name="deadline" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">تصویر نمونه (اختیاری)</label>
              <input type="file" name="sample_image" class="form-control" accept="image/*">
            </div>
          </div>

          <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="<?= url('/adtask/advertise') ?>" class="btn btn-outline-secondary">انصراف</a>
            <button type="submit" class="btn btn-primary">
              <span class="material-icons" style="font-size:16px;vertical-align:middle;">save</span> ثبت Adtask
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
const fee = <?= (float)($feePercent ?? 10) ?> / 100;
function updateTotal() {
  const r = parseFloat(document.getElementById('rewardInput')?.value) || 0;
  const s = parseInt(document.getElementById('slotsInput')?.value) || 0;
  const total = Math.ceil(r * s * (1 + fee));
  document.getElementById('totalBudget').textContent = total.toLocaleString('fa-IR') + ' تومان';
}
document.getElementById('rewardInput')?.addEventListener('input', updateTotal);
document.getElementById('slotsInput')?.addEventListener('input', updateTotal);
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
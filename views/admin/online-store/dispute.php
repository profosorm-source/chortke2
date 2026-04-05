<?php $title = 'رسیدگی اختلاف — Online Store'; $layout = 'admin'; ob_start(); ?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="page-title mb-1"><span class="material-icons text-warning">gavel</span> رسیدگی به اختلاف</h4>
      <p class="text-muted mb-0" style="font-size:12px;">آگهی #<?= e($listing->id) ?> — <?= e($listing->title) ?></p>
    </div>
    <a href="<?= url('/admin/online-store') ?>" class="btn btn-outline-secondary btn-sm">
      <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
    </a>
  </div>

  <?php if(!$listing): ?>
    <div class="alert alert-danger">آگهی یافت نشد.</div>
  <?php else: ?>
  <div class="row">
    <div class="col-md-8">
      <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">جزئیات آگهی</h6></div>
        <div class="card-body">
          <table class="table table-bordered table-sm">
            <tr><th width="35%">عنوان</th><td><?= e($listing->title) ?></td></tr>
            <tr><th>پلتفرم</th><td><?= e($listing->platform) ?></td></tr>
            <tr><th>قیمت</th><td class="text-success fw-bold"><?= number_format($listing->price_usdt,2) ?> USDT</td></tr>
            <tr><th>فروشنده (ID)</th><td><?= e($listing->seller_id) ?></td></tr>
            <tr><th>خریدار (ID)</th><td><?= e($listing->buyer_id??'—') ?></td></tr>
            <tr><th>دلیل اختلاف</th><td><?= e($listing->dispute_reason??'ذکر نشده') ?></td></tr>
          </table>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-warning">
        <div class="card-header bg-warning text-dark"><h6 class="mb-0">تصمیم‌گیری</h6></div>
        <div class="card-body">
          <p class="small text-muted mb-3">به نفع کدام طرف رأی می‌دهید؟</p>
          <div class="d-grid gap-2">
            <button class="btn btn-success btn-resolve" data-winner="seller">
              <span class="material-icons" style="font-size:16px;vertical-align:middle;">sell</span>
              به نفع فروشنده — آزاد وجه
            </button>
            <button class="btn btn-primary btn-resolve" data-winner="buyer">
              <span class="material-icons" style="font-size:16px;vertical-align:middle;">person</span>
              به نفع خریدار — استرداد
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.btn-resolve').forEach(btn => {
  btn.addEventListener('click', function() {
    const winner = this.dataset.winner;
    const label = winner === 'seller' ? 'فروشنده' : 'خریدار';
    if(!confirm(`آیا رأی به نفع ${label} را تایید می‌کنید؟`)) return;
    fetch(`/admin/online-store/<?= e($listing->id) ?>/resolve`, {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''},
      body: JSON.stringify({winner})
    }).then(r=>r.json()).then(d=>{
      if(d.success) { alert('تصمیم ثبت شد.'); location.href='/admin/online-store'; }
      else alert('خطا در ثبت تصمیم.');
    });
  });
});
</script>

<?php $content = ob_get_clean(); include base_path('views/layouts/admin.php'); ?>

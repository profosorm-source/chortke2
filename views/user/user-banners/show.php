<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">view_carousel</span> جزئیات بنر</h4>
  </div>
  <a href="<?= url('/my-banners') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</span> بازگشت
  </a>
</div>

<?php if(!$banner): ?>
  <div class="alert alert-danger mt-3">بنر یافت نشد.</div>
<?php else: ?>
<?php
  $sc = ['pending'=>'warning','active'=>'success','rejected'=>'danger','cancelled'=>'secondary','expired'=>'dark'];
  $sl = ['pending'=>'در انتظار تایید','active'=>'فعال','rejected'=>'رد شده','cancelled'=>'لغو شده','expired'=>'منقضی'];
  $st = $banner->status ?? 'pending';
?>
<div class="row mt-3">
  <div class="col-md-8">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><?= e($banner->title ?? '—') ?></h6>
        <span class="badge bg-<?= $sc[$st] ?>"><?= $sl[$st] ?></span>
      </div>
      <?php if(!empty($banner->image_path)): ?>
      <img src="<?= e($banner->image_path) ?>" class="card-img-top" style="max-height:120px;object-fit:cover;">
      <?php endif; ?>
      <div class="card-body">
        <table class="table table-bordered table-sm">
          <tr><th width="35%">جایگاه</th><td><?= e($banner->placement_name ?? 'عمومی') ?></td></tr>
          <tr><th>لینک هدف</th><td><?php if($banner->link_url): ?><a href="<?= e($banner->link_url) ?>" target="_blank"><?= e($banner->link_url) ?></a><?php else: ?>—<?php endif; ?></td></tr>
          <tr><th>مدت</th><td><?= e($banner->days ?? '—') ?> روز</td></tr>
          <tr><th>هزینه پرداخت‌شده</th><td><?= number_format($banner->total_price ?? 0) ?> تومان</td></tr>
          <tr><th>شروع</th><td><?= e(substr($banner->starts_at ?? '—', 0, 10)) ?></td></tr>
          <tr><th>پایان</th><td><?= e(substr($banner->ends_at ?? '—', 0, 10)) ?></td></tr>
          <tr><th>تاریخ درخواست</th><td><?= e(substr($banner->created_at ?? '', 0, 10)) ?></td></tr>
        </table>
        <?php if(!empty($banner->rejection_reason)): ?>
        <div class="alert alert-danger mt-2 small"><strong>دلیل رد:</strong> <?= e($banner->rejection_reason) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <?php if($st === 'pending'): ?>
    <div class="card">
      <div class="card-body">
        <button class="btn btn-outline-danger w-100 btn-cancel" data-id="<?= e($banner->id) ?>">
          <span class="material-icons" style="font-size:16px;vertical-align:middle;">cancel</span> لغو درخواست
        </button>
        <small class="text-muted d-block mt-2 text-center">وجه به کیف پول بازمی‌گردد</small>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
document.querySelector('.btn-cancel')?.addEventListener('click', function() {
  if(!confirm('لغو این بنر و بازگشت وجه؟')) return;
  fetch(`/my-banners/${this.dataset.id}/cancel`, {
    method:'POST', headers:{'X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''}
  }).then(r=>r.json()).then(d=>{ if(d.success) location.href='/my-banners'; else alert('خطا'); });
});
</script>

<?php $content = ob_get_clean(); require layout_path($layout); ?>

<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">view_carousel</span> بنرهای تبلیغاتی من</h4>
    <p class="text-muted mb-0" style="font-size:12px;">نمایش بنر تبلیغاتی در سایت — پرداخت از کیف پول</p>
  </div>
  <a href="<?= url('/my-banners/create') ?>" class="btn btn-primary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">add</span> بنر جدید
  </a>
</div>

<?php if(!empty($price)): ?>
<div class="alert alert-info small mt-3">
  <span class="material-icons" style="font-size:16px;vertical-align:middle;">info</span>
  هزینه هر روز نمایش بنر: <strong><?= number_format($price) ?> تومان</strong>
</div>
<?php endif; ?>

<?php if(empty($banners)): ?>
<div class="card mt-3">
  <div class="card-body text-center py-5">
    <span class="material-icons text-muted" style="font-size:64px;">view_carousel</span>
    <h5 class="mt-3 text-muted">بنر تبلیغاتی ندارید</h5>
    <p class="text-muted small">با ثبت بنر، تبلیغ شما در صفحات مختلف سایت نمایش داده می‌شود.</p>
    <a href="<?= url('/my-banners/create') ?>" class="btn btn-primary mt-2">ثبت بنر جدید</a>
  </div>
</div>
<?php else: ?>
<div class="row mt-3">
<?php foreach($banners as $b): ?>
<?php
  $sc = ['pending'=>'warning','active'=>'success','rejected'=>'danger','cancelled'=>'secondary','expired'=>'dark'];
  $sl = ['pending'=>'در انتظار تایید','active'=>'فعال','rejected'=>'رد شده','cancelled'=>'لغو شده','expired'=>'منقضی'];
  $st = $b->status ?? 'pending';
?>
<div class="col-md-6 mb-3">
  <div class="card h-100">
    <?php if(!empty($b->image_path)): ?>
    <img src="<?= e($b->image_path) ?>" class="card-img-top" style="height:120px;object-fit:cover;">
    <?php endif; ?>
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <h6 class="fw-bold mb-0"><?= e($b->title ?? '—') ?></h6>
          <small class="text-muted"><?= e($b->placement_name ?? 'جایگاه عمومی') ?></small>
        </div>
        <span class="badge bg-<?= $sc[$st] ?? 'secondary' ?>"><?= $sl[$st] ?? $st ?></span>
      </div>
      <?php if(!empty($b->link_url)): ?>
      <a href="<?= e($b->link_url) ?>" target="_blank" class="text-muted small text-truncate d-block" style="max-width:100%;"><?= e($b->link_url) ?></a>
      <?php endif; ?>
      <hr class="my-2">
      <div class="row text-center small">
        <div class="col-6">
          <div class="text-muted">مدت</div>
          <div class="fw-bold"><?= e($b->days ?? '—') ?> روز</div>
        </div>
        <div class="col-6">
          <div class="text-muted">هزینه</div>
          <div class="fw-bold"><?= number_format($b->total_price ?? 0) ?> تومان</div>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <a href="<?= url("/my-banners/{$b->id}") ?>" class="btn btn-outline-secondary btn-sm flex-fill">جزئیات</a>
        <?php if($st === 'pending'): ?>
        <button class="btn btn-outline-danger btn-sm flex-fill btn-cancel" data-id="<?= e($b->id) ?>">لغو</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-cancel').forEach(btn => {
  btn.addEventListener('click', function() {
    if(!confirm('لغو این بنر و بازگشت وجه؟')) return;
    fetch(`/my-banners/${this.dataset.id}/cancel`, {
      method:'POST',
      headers:{'X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''}
    }).then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert('خطا'); });
  });
});
</script>

<?php $content = ob_get_clean(); require layout_path($layout); ?>

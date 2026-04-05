<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">history</i> تاریخچه Adsocial</h4>
  <a href="<?= url('/adsocial') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت</a>
</div>
<div class="mt-3">
<?php if(empty($history)): ?>
  <div class="alert alert-info text-center">هیچ تاریخچه‌ای وجود ندارد.</div>
<?php else: foreach($history as $item): ?>
  <div class="card mb-2"><div class="card-body py-2">
    <div class="d-flex justify-content-between">
      <div>
        <strong><?= e($item->title??'—') ?></strong>
        <?php $sc=['approved'=>'bg-success','pending'=>'bg-warning','rejected'=>'bg-danger','expired'=>'bg-secondary']; ?>
        <span class="badge <?= $sc[$item->status]??'bg-info' ?> ms-1"><?= e($item->status) ?></span>
      </div>
      <small class="text-muted"><?= e($item->created_at??'') ?></small>
    </div>
  </div></div>
<?php endforeach; endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>

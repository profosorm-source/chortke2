<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">pending_actions</i> سفارش‌های دریافتی</h4>
  <a href="<?= url('/influencer') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت</a>
</div>
<?php if(empty($orders)): ?>
  <div class="alert alert-info text-center mt-4">سفارشی وجود ندارد.</div>
<?php else: foreach($orders as $o): ?>
<div class="card mt-2"><div class="card-body">
  <div class="d-flex justify-content-between align-items-start">
    <div>
      <strong>سفارش #<?= e($o->id) ?></strong>
      <span class="badge bg-secondary ms-1"><?= e($o->platform??'') ?></span>
      <?php $sc=['pending'=>'bg-warning','accepted'=>'bg-info','completed'=>'bg-success','rejected'=>'bg-danger']; ?><span class="badge <?= $sc[$o->status]??'bg-secondary' ?> ms-1"><?= e($o->status) ?></span>
      <div class="small text-muted mt-1"><?= e($o->created_at) ?></div>
    </div>
    <?php if($o->status==='pending'): ?>
    <div class="d-flex gap-1">
      <button class="btn btn-success btn-sm" onclick="respond(<?= e($o->id) ?>,'accept')">قبول</button>
      <button class="btn btn-outline-danger btn-sm" onclick="respond(<?= e($o->id) ?>,'reject')">رد</button>
    </div>
    <?php endif; ?>
  </div>
</div></div>
<?php endforeach; endif; ?>
<script>
function respond(id,action){
  fetch('<?= url('/influencer/orders/') ?>'+id+'/respond',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'},body:JSON.stringify({order_id:id,action})}).then(r=>r.json()).then(d=>{if(d.success)location.reload();else alert(d.message||'خطا');});
}
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
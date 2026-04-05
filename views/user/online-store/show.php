<?php $layout='user'; ob_start();
$session=\Core\Session::getInstance();
?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">storefront</i> <?= e($listing->title) ?></h4>
  <a href="<?= url('/online-store') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت</a>
</div>
<div class="row mt-3">
  <div class="col-md-8">
    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
          <span class="badge bg-info"><?= e($listing->platform) ?></span>
          <span class="badge <?= $listing->status==='active'?'bg-success':'bg-secondary' ?>"><?= e($statuses[$listing->status]??$listing->status) ?></span>
        </div>
        <h5 class="fw-bold"><?= e($listing->title) ?></h5>
        <p class="text-muted" dir="ltr">@<?= e($listing->username) ?></p>
        <div class="mb-2"><strong>تعداد اعضا:</strong> <?= number_format($listing->member_count??0) ?></div>
        <div class="mb-3"><strong>توضیحات:</strong><p class="mt-1"><?= nl2br(e($listing->description??'')) ?></p></div>
        <?php if(!empty($listing->screenshots)): $ss=json_decode($listing->screenshots,true)??[]; ?>
        <div class="mb-3"><strong>اسکرین‌شات‌ها:</strong><div class="d-flex gap-2 mt-1 flex-wrap"><?php foreach($ss as $s): ?><img src="<?= e($s) ?>" class="img-thumbnail" style="max-height:120px;"><?php endforeach; ?></div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card">
      <div class="card-body text-center">
        <div class="h3 text-success mb-1"><?= number_format($listing->price_usdt,2) ?> USDT</div>
        <div class="text-muted small mb-3">قیمت فروش</div>
        <div class="text-muted small mb-3">فروشنده: <?= e($listing->seller_name??'—') ?></div>
        <?php if(!$isSeller && !$isBuyer && $listing->status==='active'): ?>
        <button class="btn btn-success w-100" onclick="buyListing()">خرید این پیج/کانال</button>
        <div class="mt-2 small text-muted">مبلغ تا تایید دریافت در escrow نگه داشته می‌شود</div>
        <?php elseif($isBuyer && $listing->status==='in_escrow'): ?>
        <div class="alert alert-info small">پول شما در escrow است. پس از دریافت اطلاعات دسترسی، تایید کنید.</div>
        <button class="btn btn-success w-100 mb-2" onclick="confirmReceived()">تایید دریافت و آزاد کردن پول</button>
        <button class="btn btn-outline-danger w-100 btn-sm" onclick="disputeListing()">اعلام اختلاف</button>
        <?php elseif($isSeller): ?>
        <div class="alert alert-info small text-start">این آگهی شماست.<br>وضعیت: <?= e($statuses[$listing->status]??'') ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
const csrf = '<?= csrf_token() ?>';
const id = <?= e($listing->id) ?>;
function buyListing() {
  if(!confirm('آیا از خرید این پیج اطمینان دارید؟')) return;
  fetch(`<?= url('/online-store/') ?>${id}/buy`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:'{}'}). then(r=>r.json()).then(d=>{alert(d.message);if(d.success)location.reload();});
}
function confirmReceived() {
  if(!confirm('آیا اطلاعات دسترسی را دریافت کرده‌اید؟')) return;
  fetch(`<?= url('/online-store/') ?>${id}/confirm`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:'{}'}).then(r=>r.json()).then(d=>{alert(d.message);if(d.success)location.reload();});
}
function disputeListing() {
  const reason = prompt('دلیل اختلاف را بنویسید:');
  if(!reason) return;
  fetch(`<?= url('/online-store/') ?>${id}/dispute`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({reason})}).then(r=>r.json()).then(d=>{alert(d.message);if(d.success)location.reload();});
}
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
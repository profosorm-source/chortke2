<?php $layout='user'; ob_start();
$session=\Core\Session::getInstance(); $old=$session->getFlash('old')??[]; $old=is_array($old)?(object)$old:$old;
$currencyLabel=setting('currency_mode','irt')==='usdt'?'USDT':'تومان';
?>
<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0"><i class="material-icons text-primary">add_circle</i> ثبت تبلیغ Adsocial</h4>
  <a href="<?= url('/adsocial/advertise') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت</a>
</div>
<form action="<?= url('/adsocial/advertise/store') ?>" method="POST" class="mt-3">
  <?= csrf_field() ?>
  <div class="card"><div class="card-header"><h6 class="card-title mb-0">مشخصات تبلیغ</h6></div><div class="card-body">
    <div class="row">
      <div class="col-md-8 mb-3"><label class="form-label">عنوان تبلیغ <span class="text-danger">*</span></label><input type="text" name="title" class="form-control" value="<?= e($old->title??'') ?>" required maxlength="200"></div>
      <div class="col-md-4 mb-3"><label class="form-label">پلتفرم</label>
        <select name="platform" class="form-select">
        <?php foreach($platforms as $k=>$v): ?><option value="<?= e($k) ?>" <?= ($old->platform??'')===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="row">
      <div class="col-md-6 mb-3"><label class="form-label">نوع اقدام</label>
        <select name="task_type" class="form-select">
        <?php foreach($taskTypes as $k=>$v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 mb-3"><label class="form-label">لینک هدف <span class="text-danger">*</span></label><input type="url" name="target_url" class="form-control" value="<?= e($old->target_url??'') ?>" required placeholder="https://..."></div>
    </div>
    <div class="mb-3"><label class="form-label">توضیحات</label><textarea name="description" class="form-control" rows="3"><?= e($old->description??'') ?></textarea></div>
    <div class="row">
      <div class="col-md-4 mb-3"><label class="form-label">پاداش هر اجرا (<?= $currencyLabel ?>)</label><input type="number" name="reward_per_execution" class="form-control" value="<?= e($old->reward_per_execution??'') ?>" min="0" step="0.01" required></div>
      <div class="col-md-4 mb-3"><label class="form-label">حداکثر اجراها</label><input type="number" name="max_executions" class="form-control" value="<?= e($old->max_executions??100) ?>" min="1"></div>
      <div class="col-md-4 mb-3"><label class="form-label">بودجه کل (<?= $currencyLabel ?>)</label><input type="number" name="total_budget" class="form-control" value="<?= e($old->total_budget??'') ?>" min="0" step="0.01" required></div>
    </div>
  </div></div>
  <div class="mt-3"><button type="submit" class="btn btn-primary px-4"><i class="material-icons" style="font-size:16px;vertical-align:middle;">save</i> ثبت تبلیغ</button></div>
</form>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>

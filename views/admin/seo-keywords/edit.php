<?php
// views/admin/seo-keywords/edit.php
$title = 'ویرایش کلمه کلیدی';
$layout = 'admin';
ob_start();
$kw = $keyword;
?>

<div class="page-header">
    <h4><i class="material-icons">edit</i> ویرایش کلمه #<?= e($kw->id) ?></h4>
    <a href="<?= url('/admin/seo-keywords') ?>" class="btn btn-outline-sm"><i class="material-icons">arrow_forward</i> بازگشت</a>
</div>

<div class="card"><div class="card-body">
<form action="<?= url('/admin/seo-keywords/' . $kw->id . '/update') ?>" method="POST">
    <?= csrf_field() ?>
    <div class="form-row">
        <div class="form-group col-md-6"><label>کلمه کلیدی <span class="required">*</span></label><input type="text" name="keyword" class="form-control" value="<?= e($kw->keyword) ?>" required></div>
        <div class="form-group col-md-6"><label>URL هدف <span class="required">*</span></label><input type="url" name="target_url" class="form-control ltr-input" value="<?= e($kw->target_url) ?>" required></div>
    </div>
    <div class="form-row">
        <div class="form-group col-md-3"><label>رتبه فعلی</label><input type="number" name="target_position" class="form-control" value="<?= e($kw->target_position) ?>" min="0"></div>
        <div class="form-group col-md-3"><label>پاداش</label><input type="number" name="reward_amount" class="form-control" value="<?= e($kw->reward_amount) ?>" min="0" step="0.01"></div>
        <div class="form-group col-md-3"><label>اولویت</label><input type="number" name="priority" class="form-control" value="<?= e($kw->priority) ?>" min="0"></div>
        <div class="form-group col-md-3"><label>بودجه روزانه</label><input type="number" name="daily_budget" class="form-control" value="<?= e($kw->daily_budget) ?>" min="1"></div>
    </div>
    <hr>
    <h6>تنظیمات اسکرول (ثانیه)</h6>
    <div class="form-row">
        <div class="form-group col-md-3"><label>اسکرول حداقل</label><input type="number" name="scroll_min_seconds" class="form-control" value="<?= e($kw->scroll_min_seconds) ?>" min="5"></div>
        <div class="form-group col-md-3"><label>اسکرول حداکثر</label><input type="number" name="scroll_max_seconds" class="form-control" value="<?= e($kw->scroll_max_seconds) ?>" min="10"></div>
        <div class="form-group col-md-3"><label>توقف حداقل</label><input type="number" name="pause_min_seconds" class="form-control" value="<?= e($kw->pause_min_seconds) ?>" min="1"></div>
        <div class="form-group col-md-3"><label>توقف حداکثر</label><input type="number" name="pause_max_seconds" class="form-control" value="<?= e($kw->pause_max_seconds) ?>" min="2"></div>
    </div>
    <div class="form-row">
        <div class="form-group col-md-3"><label>کل مدت مرور</label><input type="number" name="total_browse_seconds" class="form-control" value="<?= e($kw->total_browse_seconds) ?>" min="20"></div>
        <div class="form-group col-md-3"><label>حداکثر/ساعت</label><input type="number" name="max_per_hour" class="form-control" value="<?= e($kw->max_per_hour) ?>" min="1"></div>
        <div class="form-group col-md-3"><label>حداکثر/روز</label><input type="number" name="max_per_day" class="form-control" value="<?= e($kw->max_per_day) ?>" min="1"></div>
        <div class="form-group col-md-3"><label>وضعیت</label><select name="is_active" class="form-control"><option value="1" <?= $kw->is_active ? 'selected' : '' ?>>فعال</option><option value="0" <?= !$kw->is_active ? 'selected' : '' ?>>غیرفعال</option></select></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="material-icons">save</i> ذخیره</button></div>
</form>
</div></div>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
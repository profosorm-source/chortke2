<?php $title = 'ایجاد بنر جدید'; $layout = 'admin'; ob_start(); ?>
<?php
$session = \Core\Session::getInstance();
$errors = $session->getFlash('errors') ?? [];
$old = $session->getFlash('old') ?? [];
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><span class="material-icons me-1" style="vertical-align:middle;">add_photo_alternate</span> ایجاد بنر جدید</h5>
                    <a href="<?= url('/admin/banners') ?>" class="btn btn-sm btn-outline-secondary">
                        <span class="material-icons" style="font-size:14px;vertical-align:middle;">arrow_forward</span> بازگشت
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors['general'])): ?>
                        <div class="alert alert-danger"><?= e($errors['general']) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="<?= url('/admin/banners/store') ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>

                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">عنوان بنر <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>" value="<?= e($old['title'] ?? '') ?>" required>
                                <?php if (isset($errors['title'])): ?>
                                    <div class="invalid-feedback"><?= e($errors['title']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">نوع</label>
                                <select name="type" class="form-select" id="bannerType">
                                    <option value="image" <?= ($old['type'] ?? '') === 'image' ? 'selected' : '' ?>>تصویر</option>
                                    <option value="gif" <?= ($old['type'] ?? '') === 'gif' ? 'selected' : '' ?>>GIF</option>
                                    <option value="code" <?= ($old['type'] ?? '') === 'code' ? 'selected' : '' ?>>کد HTML</option>
                                </select>
                            </div>
                        </div>

                        <div id="imageUploadSection" class="mb-3">
                            <label class="form-label">تصویر بنر</label>
                            <input type="file" name="image" class="form-control <?= isset($errors['image']) ? 'is-invalid' : '' ?>" accept="image/jpeg,image/png,image/gif,image/webp">
                            <small class="text-muted">فرمت‌های مجاز: JPG, PNG, GIF, WEBP — حداکثر 2 مگابایت</small>
                            <?php if (isset($errors['image'])): ?>
                                <div class="invalid-feedback"><?= e($errors['image']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div id="codeSection" class="mb-3" style="display:none;">
                            <label class="form-label">کد HTML سفارشی</label>
                            <textarea name="custom_code" class="form-control" rows="4"><?= e($old['custom_code'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">لینک مقصد</label>
                            <input type="url" name="link" class="form-control <?= isset($errors['link']) ? 'is-invalid' : '' ?>" value="<?= e($old['link'] ?? '') ?>" placeholder="https://example.com" dir="ltr">
                            <?php if (isset($errors['link'])): ?>
                                <div class="invalid-feedback"><?= e($errors['link']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">جایگاه <span class="text-danger">*</span></label>
                                <select name="placement" class="form-select <?= isset($errors['placement']) ? 'is-invalid' : '' ?>" required>
                                    <option value="">انتخاب کنید</option>
                                    <?php foreach ($placements as $p): ?>
                                        <option value="<?= e($p->slug) ?>" <?= ($old['placement'] ?? '') === $p->slug ? 'selected' : '' ?>><?= e($p->title) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['placement'])): ?>
                                    <div class="invalid-feedback"><?= e($errors['placement']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">اولویت نمایش</label>
                                <input type="number" name="sort_order" class="form-control" value="<?= e($old['sort_order'] ?? '0') ?>" min="0">
                                <small class="text-muted">عدد کمتر = اولویت بالاتر</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">باز شدن لینک</label>
                                <select name="target" class="form-select">
                                    <option value="_blank" <?= ($old['target'] ?? '') === '_blank' ? 'selected' : '' ?>>در تب جدید</option>
                                    <option value="_self" <?= ($old['target'] ?? '') === '_self' ? 'selected' : '' ?>>در همین صفحه</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">تاریخ شروع</label>
                                <input type="datetime-local" name="start_date" class="form-control" value="<?= e($old['start_date'] ?? '') ?>" dir="ltr">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">تاریخ پایان</label>
                                <input type="datetime-local" name="end_date" class="form-control <?= isset($errors['end_date']) ? 'is-invalid' : '' ?>" value="<?= e($old['end_date'] ?? '') ?>" dir="ltr">
                                <?php if (isset($errors['end_date'])): ?>
                                    <div class="invalid-feedback"><?= e($errors['end_date']) ?></div>
                                <?php endif; ?>
                                <small class="text-muted">خالی = بدون محدودیت زمانی</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">متن جایگزین (Alt)</label>
                            <input type="text" name="alt_text" class="form-control" value="<?= e($old['alt_text'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" class="form-check-input" id="isActive" <?= ($old['is_active'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isActive">فعال</label>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success">
                                <span class="material-icons" style="font-size:16px;vertical-align:middle;">save</span> ذخیره بنر
                            </button>
                            <a href="<?= url('/admin/banners') ?>" class="btn btn-outline-secondary">انصراف</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeSelect = document.getElementById('bannerType');
    const imageSection = document.getElementById('imageUploadSection');
    const codeSection = document.getElementById('codeSection');

    function toggleSections() {
        if (typeSelect.value === 'code') {
            imageSection.style.display = 'none';
            codeSection.style.display = 'block';
        } else {
            imageSection.style.display = 'block';
            codeSection.style.display = 'none';
        }
    }

    typeSelect.addEventListener('change', toggleSections);
    toggleSections();
});
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
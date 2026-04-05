<?php $title = 'ویرایش بنر'; $layout = 'admin'; ob_start(); ?>
<?php
$session = \Core\Session::getInstance();
$errors = $session->getFlash('errors') ?? [];
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><span class="material-icons me-1" style="vertical-align:middle;">edit</span> ویرایش بنر: <?= e($banner->title) ?></h5>
                    <a href="<?= url('/admin/banners') ?>" class="btn btn-sm btn-outline-secondary">
                        <span class="material-icons" style="font-size:14px;vertical-align:middle;">arrow_forward</span> بازگشت
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors['general'])): ?>
                        <div class="alert alert-danger"><?= e($errors['general']) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="<?= url("/admin/banners/{$banner->id}/update") ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>

                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">عنوان بنر <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>" value="<?= e($banner->title) ?>" required>
                                <?php if (isset($errors['title'])): ?>
                                    <div class="invalid-feedback"><?= e($errors['title']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">نوع</label>
                                <select name="type" class="form-select" id="bannerType">
                                    <option value="image" <?= $banner->type === 'image' ? 'selected' : '' ?>>تصویر</option>
                                    <option value="gif" <?= $banner->type === 'gif' ? 'selected' : '' ?>>GIF</option>
                                    <option value="code" <?= $banner->type === 'code' ? 'selected' : '' ?>>کد HTML</option>
                                </select>
                            </div>
                        </div>

                        <!-- تصویر فعلی -->
                        <?php if ($banner->image_path): ?>
                            <div class="mb-3">
                                <label class="form-label">تصویر فعلی</label>
                                <div>
                                    <img src="<?= asset($banner->image_path) ?>" alt="<?= e($banner->title) ?>" style="max-width:300px;max-height:150px;border-radius:8px;border:1px solid #eee;">
                                </div>
                            </div>
                        <?php endif; ?>

                        <div id="imageUploadSection" class="mb-3">
                            <label class="form-label">تصویر جدید (اختیاری)</label>
                            <input type="file" name="image" class="form-control <?= isset($errors['image']) ? 'is-invalid' : '' ?>" accept="image/jpeg,image/png,image/gif,image/webp">
                            <small class="text-muted">فقط در صورت تغییر تصویر آپلود کنید</small>
                            <?php if (isset($errors['image'])): ?>
                                <div class="invalid-feedback"><?= e($errors['image']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div id="codeSection" class="mb-3" style="display:none;">
                            <label class="form-label">کد HTML سفارشی</label>
                            <textarea name="custom_code" class="form-control" rows="4"><?= e($banner->custom_code ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">لینک مقصد</label>
                            <input type="url" name="link" class="form-control <?= isset($errors['link']) ? 'is-invalid' : '' ?>" value="<?= e($banner->link ?? '') ?>" placeholder="https://example.com" dir="ltr">
                            <?php if (isset($errors['link'])): ?>
                                <div class="invalid-feedback"><?= e($errors['link']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">جایگاه</label>
                                <select name="placement" class="form-select" required>
                                    <?php foreach ($placements as $p): ?>
                                        <option value="<?= e($p->slug) ?>" <?= $banner->placement === $p->slug ? 'selected' : '' ?>><?= e($p->title) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">اولویت نمایش</label>
                                <input type="number" name="sort_order" class="form-control" value="<?= e($banner->sort_order) ?>" min="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">باز شدن لینک</label>
                                <select name="target" class="form-select">
                                    <option value="_blank" <?= $banner->target === '_blank' ? 'selected' : '' ?>>در تب جدید</option>
                                    <option value="_self" <?= $banner->target === '_self' ? 'selected' : '' ?>>در همین صفحه</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">تاریخ شروع</label>
                                <input type="datetime-local" name="start_date" class="form-control" value="<?= e($banner->start_date ? \date('Y-m-d\TH:i', \strtotime($banner->start_date)) : '') ?>" dir="ltr">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">تاریخ پایان</label>
                                <input type="datetime-local" name="end_date" class="form-control <?= isset($errors['end_date']) ? 'is-invalid' : '' ?>" value="<?= e($banner->end_date ? \date('Y-m-d\TH:i', \strtotime($banner->end_date)) : '') ?>" dir="ltr">
                                <?php if (isset($errors['end_date'])): ?>
                                    <div class="invalid-feedback"><?= e($errors['end_date']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">متن جایگزین (Alt)</label>
                            <input type="text" name="alt_text" class="form-control" value="<?= e($banner->alt_text ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" class="form-check-input" id="isActive" <?= $banner->is_active ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isActive">فعال</label>
                            </div>
                        </div>

                        <!-- آمار فعلی -->
                        <div class="alert alert-info mb-3">
                            <div class="row text-center">
                                <div class="col-4">
                                    <strong><?= number_format($banner->clicks) ?></strong><br><small>کلیک</small>
                                </div>
                                <div class="col-4">
                                    <strong><?= number_format($banner->impressions) ?></strong><br><small>نمایش</small>
                                </div>
                                <div class="col-4">
                                    <strong><?= e($banner->ctr) ?>%</strong><br><small>نرخ کلیک</small>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <span class="material-icons" style="font-size:16px;vertical-align:middle;">save</span> ذخیره تغییرات
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
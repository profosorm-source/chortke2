<?php
$pageTitle = 'ایجاد تیکت جدید';
ob_start();

use App\Enums\TicketPriority;
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">ایجاد تیکت جدید</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="material-icons">info</i>
                        <strong>راهنما:</strong>
                        <ul class="mb-0 mt-2" style="font-size: 13px;">
                            <li>برای مشکلات <strong>مالی و پرداخت</strong> حتماً تیکت ثبت کنید</li>
                            <li>برای سوالات ساده از <strong>چت آنلاین</strong> استفاده کنید</li>
                            <li>موضوع را واضح و کامل بنویسید</li>
                            <li>در صورت نیاز، فایل پیوست ارسال کنید</li>
                        </ul>
                    </div>

                    <form method="POST" action="<?= url('/tickets/store') ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        
                        <!-- دسته‌بندی -->
                        <div class="form-group">
                            <label>دسته‌بندی <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-control" required>
                                <option value="">انتخاب کنید</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e($category->id) ?>" <?= old('category_id') == $category->id ? 'selected' : '' ?>>
                                        <?= e($category->name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- موضوع -->
                        <div class="form-group">
                            <label>موضوع <span class="text-danger">*</span></label>
                            <input type="text" name="subject" class="form-control" value="<?= old('subject') ?>" required>
                        </div>

                        <!-- اولویت -->
                        <div class="form-group">
                            <label>اولویت <span class="text-danger">*</span></label>
                            <select name="priority" class="form-control" required>
                                <?php foreach (TicketPriority::all() as $p): ?>
                                    <option value="<?= e($p) ?>" <?= old('priority', 'normal') === $p ? 'selected' : '' ?>>
                                        <?= TicketPriority::label($p) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- پیام -->
                        <div class="form-group">
                            <label>پیام <span class="text-danger">*</span></label>
                            <textarea name="message" class="form-control" rows="6" required><?= old('message') ?></textarea>
                        </div>

                        <!-- فایل پیوست -->
                        <div class="form-group">
                            <label>فایل پیوست (اختیاری)</label>
                            <input type="file" name="attachments[]" class="form-control" multiple accept="image/*,.pdf">
                            <small class="form-text text-muted">حداکثر 5MB - فرمت: JPG, PNG, PDF</small>
                        </div>

                        <!-- دکمه‌ها -->
                        <div class="form-group mb-0">
                            <button type="submit" class="btn btn-primary">
                                <i class="material-icons">send</i>
                                ارسال تیکت
                            </button>
                            <a href="<?= url('/tickets') ?>" class="btn btn-secondary">
                                <i class="material-icons">arrow_back</i>
                                بازگشت
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../layouts/user.php'; ?>
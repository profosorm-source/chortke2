<?php

$title = 'احراز هویت';
$layout = 'user';
ob_start();
?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <?php if (!$kyc): ?>
            <!-- هنوز احراز هویت نشده -->
            <div class="card">
                <div class="card-body text-center py-5">
                    <div class="mb-4">
                        <i class="material-icons text-warning" style="font-size: 80px;">verified_user</i>
                    </div>
                    <h5 class="mb-3">احراز هویت حساب کاربری</h5>
                    <p class="text-muted mb-4">
                        برای استفاده از امکانات برداشت وجه، لطفاً احراز هویت خود را تکمیل کنید
                    </p>
                    <a href="<?= url('/kyc/upload') ?>" class="btn btn-primary">
                        <i class="material-icons" style="font-size: 18px; vertical-align: middle;">upload</i>
                        شروع احراز هویت
                    </a>
                </div>
            </div>

        <?php else: ?>
            <!-- وضعیت احراز هویت -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">وضعیت احراز هویت</h5>
                </div>
                <div class="card-body">
                    <?php
                    $statusConfig = [
                        'pending' => [
                            'label' => 'در انتظار بررسی',
                            'color' => 'warning',
                            'icon' => 'schedule'
                        ],
                        'under_review' => [
                            'label' => 'در حال بررسی',
                            'color' => 'info',
                            'icon' => 'pending'
                        ],
                        'verified' => [
                            'label' => 'تأیید شده',
                            'color' => 'success',
                            'icon' => 'check_circle'
                        ],
                        'rejected' => [
                            'label' => 'رد شده',
                            'color' => 'danger',
                            'icon' => 'cancel'
                        ],
                        'expired' => [
                            'label' => 'منقضی شده',
                            'color' => 'secondary',
                            'icon' => 'event_busy'
                        ]
                    ];

                    $config = $statusConfig[$kyc->status] ?? $statusConfig['pending'];
                    ?>

                    <div class="alert alert-<?= e($config['color']) ?> d-flex align-items-center mb-4">
                        <i class="material-icons me-3" style="font-size: 40px;"><?= e($config['icon']) ?></i>
                        <div>
                            <h6 class="mb-1">وضعیت: <?= e($config['label']) ?></h6>
                            <small>
                                <?php if ($kyc->status === 'pending'): ?>
                                    درخواست شما در صف بررسی قرار دارد. لطفاً منتظر بمانید.
                                <?php elseif ($kyc->status === 'under_review'): ?>
                                    مدارک شما در حال بررسی توسط کارشناسان ماست.
                                <?php elseif ($kyc->status === 'verified'): ?>
                                    احراز هویت شما با موفقیت تأیید شد.
                                <?php elseif ($kyc->status === 'rejected'): ?>
                                    متأسفانه درخواست شما رد شد.
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>

                    <!-- جزئیات -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted">کد ملی:</label>
                            <p class="mb-0"><?= e($kyc->national_code ?? 'ثبت نشده') ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">تاریخ تولد:</label>
                            <p class="mb-0">
                                <?= $kyc->birth_date ? jdate($kyc->birth_date, 'Y/m/d') : 'ثبت نشده' ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">تاریخ ثبت:</label>
                            <p class="mb-0">
                                <?= $kyc->submitted_at ? jdate($kyc->submitted_at) : '-' ?>
                            </p>
                        </div>
                        <?php if ($kyc->verified_at): ?>
                            <div class="col-md-6">
                                <label class="form-label text-muted">تاریخ تأیید:</label>
                                <p class="mb-0"><?= jdate($kyc->verified_at) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- دلیل رد -->
                    <?php if ($kyc->status === 'rejected' && $kyc->rejection_reason): ?>
                        <div class="alert alert-danger mt-4">
                            <strong>دلیل رد:</strong><br>
                            <?= nl2br(e($kyc->rejection_reason)) ?>
                        </div>
                        <a href="<?= url('/kyc/upload') ?>" class="btn btn-primary">
                            ارسال مجدد مدارک
                        </a>
                    <?php endif; ?>

                    <!-- اعتبار KYC -->
                    <?php if ($kyc->status === 'verified' && $kyc->expires_at): ?>
                        <div class="alert alert-info mt-4">
                            <i class="material-icons" style="font-size: 18px; vertical-align: middle;">info</i>
                            اعتبار احراز هویت تا: <?= jdate($kyc->expires_at, 'Y/m/d') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- راهنما -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0">راهنمای احراز هویت</h6>
            </div>
            <div class="card-body">
                <ul class="mb-0">
                    <li class="mb-2">تصویر کارت ملی باید واضح و خوانا باشد</li>
                    <li class="mb-2">تصویر سلفی شما باید در کنار یک برگه دست‌نوشته باشد</li>
                    <li class="mb-2">در برگه دست‌نوشته عبارت "<?= config('app.name') ?>" و تاریخ امروز را بنویسید</li>
                    <li class="mb-2">حداکثر حجم هر تصویر: 5 مگابایت</li>
                    <li class="mb-2">فرمت‌های مجاز: JPG، PNG</li>
                    <li>زمان بررسی: حداکثر 48 ساعت</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
<?php
$pageTitle = 'تنظیمات اعلان‌ها';
include VIEW_PATH . '/layouts/user.php';
?>
<?php
$title = $title ?? 'تنظیمات اعلان';
ob_start();
?>

<div class="card">
    <div class="card-header">
        <h5><i class="fas fa-cog"></i> تنظیمات اعلان‌ها</h5>
    </div>
    <div class="card-body">
        <form id="prefsForm">
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-md-6">
                    <h6 class="mb-3">اعلان‌های داخل سایت (In-App)</h6>

                    <?php
                    $inAppFields = [
                        'in_app_enabled' => 'فعال‌سازی کلی اعلان‌های داخل سایت',
                        'in_app_payment' => 'پرداخت‌ها و واریزها',
                        'in_app_withdrawal' => 'برداشت‌ها',
                        'in_app_task' => 'تسک‌ها',
                        'in_app_investment' => 'سرمایه‌گذاری',
                        'in_app_lottery' => 'قرعه‌کشی',
                        'in_app_referral' => 'معرفی و کمیسیون',
                        'in_app_system' => 'سیستمی و اطلاعیه‌ها',
                    ];
                    ?>
                    <?php foreach ($inAppFields as $key => $label): ?>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" <?= $preferences->{$key} ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="col-md-6">
                    <h6 class="mb-3">اعلان‌های ایمیلی</h6>

                    <?php
                    $emailFields = [
                        'email_enabled' => 'فعال‌سازی کلی ایمیل‌ها',
                        'email_payment' => 'پرداخت‌ها و واریزها',
                        'email_withdrawal' => 'برداشت‌ها',
                        'email_task' => 'تسک‌ها',
                        'email_investment' => 'سرمایه‌گذاری',
                        'email_lottery' => 'قرعه‌کشی',
                        'email_referral' => 'معرفی و کمیسیون',
                        'email_system' => 'سیستمی و اطلاعیه‌ها',
                        'email_marketing' => 'تبلیغاتی/بازاریابی',
                    ];
                    ?>
                    <?php foreach ($emailFields as $key => $label): ?>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" <?= $preferences->{$key} ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mt-4">
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-save"></i> ذخیره تنظیمات
                </button>
                <a class="btn btn-outline-secondary" href="<?= url('/notifications') ?>">
                    بازگشت
                </a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const notyf = new Notyf({ duration: 2500, position: { x: 'right', y: 'top' } });

    document.getElementById('prefsForm')?.addEventListener('submit', async function (e) {
        e.preventDefault();

        const formData = new FormData(this);
        const payload = {};
        formData.forEach((v, k) => payload[k] = v);

        // checkboxها که تیک نخورده‌اند داخل FormData نیستند، پس backend خودش false می‌گذارد.

        try {
            const res = await fetch('<?= url('/notifications/preferences/update') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': payload.csrf_token
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            if (data.success) {
                notyf.success(data.message);
            } else {
                notyf.error(data.message || 'خطا در ذخیره');
            }
        } catch (e) {
            notyf.error('خطا در ارتباط با سرور');
        }
    });
});
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../layouts/user.php';
?>
<?php
$pageTitle = 'ارسال اعلان گروهی';
include VIEW_PATH . '/layouts/admin.php';
?>

<div class="card">
    <div class="card-header">
        <h5><i class="fas fa-bullhorn"></i> ارسال اعلان گروهی</h5>
        <small class="text-muted">تعداد کاربران فعال: <?= number_format((int)$total_users) ?></small>
    </div>

    <div class="card-body">
        <form id="bulkNotifForm">
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label>مخاطب</label>
                    <select name="target" class="form-select" required>
                        <option value="all">همه کاربران</option>
                        <option value="verified">فقط احراز شده‌ها</option>
                        <option value="silver">سطح Silver</option>
                        <option value="gold">سطح Gold</option>
                        <option value="vip">سطح VIP</option>
                    </select>
                </div>

                <div class="col-md-4 mb-3">
                    <label>نوع اعلان</label>
                    <select name="type" class="form-select" required>
                        <option value="system">سیستمی</option>
                        <option value="payment">پرداخت</option>
                        <option value="withdrawal">برداشت</option>
                        <option value="task">تسک</option>
                        <option value="investment">سرمایه‌گذاری</option>
                        <option value="lottery">قرعه‌کشی</option>
                        <option value="referral">معرفی</option>
                        <option value="security">امنیتی</option>
                    </select>
                </div>

                <div class="col-md-4 mb-3">
                    <label>اولویت</label>
                    <select name="priority" class="form-select">
                        <option value="normal">عادی</option>
                        <option value="high">مهم</option>
                        <option value="urgent">فوری</option>
                    </select>
                </div>

                <div class="col-md-6 mb-3">
                    <label>عنوان</label>
                    <input type="text" name="title" class="form-control" maxlength="255" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label>لینک اقدام (اختیاری)</label>
                    <input type="text" name="action_url" class="form-control" placeholder="/wallet یا لینک کامل">
                </div>

                <div class="col-md-12 mb-3">
                    <label>متن پیام</label>
                    <textarea name="message" class="form-control" rows="4" required></textarea>
                </div>
            </div>

            <button class="btn btn-primary" type="submit">
                <i class="fas fa-paper-plane"></i> ارسال
            </button>
            <a class="btn btn-outline-secondary" href="<?= url('/admin/notifications/stats') ?>">
                مشاهده آمار
            </a>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const notyf = new Notyf({ duration: 2500, position: { x: 'right', y: 'top' } });

    document.getElementById('bulkNotifForm')?.addEventListener('submit', async function (e) {
        e.preventDefault();

        const formData = new FormData(this);
        const payload = {};
        formData.forEach((v, k) => payload[k] = v);

        confirmAction({
            type: 'warning',
            title: 'ارسال اعلان گروهی',
            text: 'آیا از ارسال این اعلان برای کاربران انتخاب‌شده مطمئن هستید؟',
            confirmButtonText: 'بله، ارسال شود',
            onConfirm: async () => {
                showLoading('در حال ارسال...');

                try {
                    const res = await fetch('<?= url('/admin/notifications/send') ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': payload.csrf_token
                        },
                        body: JSON.stringify(payload)
                    });

                    const data = await res.json();
                    closeLoading();

                    if (data.success) {
                        showSuccess('ارسال شد', data.message);
                    } else {
                        showError('خطا', data.message || 'ارسال ناموفق');
                    }
                } catch (e) {
                    closeLoading();
                    showError('خطا', 'خطا در ارتباط با سرور');
                }
            }
        });
    });
});
</script>
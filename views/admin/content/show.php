<?php $title = 'جزئیات محتوا #' . $submission->id; $layout = 'admin'; ob_start(); ?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-content.css') ?>">


<div class="content-header">
    <h4><i class="material-icons">movie</i> <?= e($submission->title) ?></h4>
    <div>
        <a href="<?= url('/admin/content') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons">arrow_back</i> بازگشت
        </a>
        <?php if ($submission->status === 'published'): ?>
        <a href="<?= url('/admin/content/' . $submission->id . '/revenue/create') ?>" class="btn btn-primary btn-sm">
            <i class="material-icons">add</i> ثبت درآمد
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
$statusLabels = [
    'pending' => ['در انتظار بررسی', 'badge-warning', 'hourglass_empty'],
    'under_review' => ['در حال بررسی', 'badge-info', 'rate_review'],
    'approved' => ['تأیید شده', 'badge-success', 'check_circle'],
    'published' => ['منتشر شده', 'badge-primary', 'public'],
    'rejected' => ['رد شده', 'badge-danger', 'cancel'],
    'suspended' => ['تعلیق شده', 'badge-dark', 'block'],
];
$sl = $statusLabels[$submission->status] ?? ['نامشخص', 'badge-secondary', 'help'];
?>

<!-- اطلاعات محتوا -->
<div class="card">
    <div class="card-header">
        <h5>اطلاعات محتوا</h5>
        <span class="badge <?= e($sl[1]) ?>" style="font-size: 13px;">
            <i class="material-icons" style="font-size:14px; vertical-align:middle;"><?= e($sl[2]) ?></i>
            <?= e($sl[0]) ?>
        </span>
    </div>
    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">شناسه</span>
                <span class="detail-value">#<?= e($submission->id) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">کاربر</span>
                <span class="detail-value">
                    <a href="<?= url('/admin/users/' . $submission->user_id . '/edit') ?>">
                        <?= e($submission->user_name ?? 'نامشخص') ?>
                    </a>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">پلتفرم</span>
                <span class="detail-value"><?= $submission->platform === 'aparat' ? 'آپارات' : 'یوتیوب' ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">لینک ویدیو</span>
                <span class="detail-value">
                    <a href="<?= e($submission->video_url) ?>" target="_blank" dir="ltr" style="word-break:break-all;">
                        <?= e($submission->video_url) ?>
                    </a>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">دسته‌بندی</span>
                <span class="detail-value"><?= e($submission->category ?? '-') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">تاریخ ارسال</span>
                <span class="detail-value"><?= e(to_jalali($submission->created_at ?? '')) ?></span>
            </div>
            <?php if ($submission->approved_at): ?>
            <div class="detail-item">
                <span class="detail-label">تاریخ تأیید</span>
                <span class="detail-value"><?= e(to_jalali($submission->approved_at)) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($submission->published_at): ?>
            <div class="detail-item">
                <span class="detail-label">تاریخ انتشار</span>
                <span class="detail-value"><?= e(to_jalali($submission->published_at)) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">کانال</span>
                <span class="detail-value"><?= e($submission->channel_name ?? '-') ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($submission->description): ?>
        <div class="mt-3">
            <strong>توضیحات:</strong>
            <p style="color:#666; margin-top:5px;"><?= \nl2br(e($submission->description)) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($submission->rejection_reason): ?>
        <div class="alert alert-danger mt-3">
            <strong>دلیل رد/تعلیق:</strong>
            <p style="margin:5px 0 0;"><?= \nl2br(e($submission->rejection_reason)) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- دکمه‌های عملیات -->
    <div class="card-footer" style="display:flex; gap:8px; flex-wrap:wrap;">
        <?php if (\in_array($submission->status, ['pending', 'under_review'])): ?>
            <button class="btn btn-success btn-sm" onclick="approveContent(<?= e($submission->id) ?>)">
                <i class="material-icons">check</i> تأیید
            </button>
            <button class="btn btn-danger btn-sm" onclick="rejectContent(<?= e($submission->id) ?>)">
                <i class="material-icons">close</i> رد
            </button>
        <?php endif; ?>
        <?php if ($submission->status === 'approved'): ?>
            <button class="btn btn-info btn-sm" onclick="publishContent(<?= e($submission->id) ?>)">
                <i class="material-icons">public</i> ثبت انتشار
            </button>
        <?php endif; ?>
        <?php if (\in_array($submission->status, ['approved', 'published'])): ?>
            <button class="btn btn-dark btn-sm" onclick="suspendContent(<?= e($submission->id) ?>)">
                <i class="material-icons">block</i> تعلیق
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- تعهدنامه -->
<?php if ($agreement): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">gavel</i> تعهدنامه</h5>
    </div>
    <div class="card-body">
        <div style="background:#f8f9fa; padding:15px; border-radius:6px; font-size:13px; line-height:2;">
            <?= \nl2br(e($agreement->agreement_text)) ?>
        </div>
        <div class="mt-2" style="font-size:12px; color:#999;">
            <span>IP: <?= e($agreement->ip_address ?? '-') ?></span> |
            <span>تاریخ: <?= e(to_jalali($agreement->accepted_at ?? '')) ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- درآمدها -->
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">monetization_on</i> تاریخچه درآمد</h5>
        <?php if ($submission->status === 'published'): ?>
        <a href="<?= url('/admin/content/' . $submission->id . '/revenue/create') ?>" class="btn btn-primary btn-sm">
            <i class="material-icons">add</i> ثبت درآمد جدید
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($revenues)): ?>
            <p class="text-muted text-center">هنوز درآمدی ثبت نشده.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>دوره</th>
                            <th>بازدید</th>
                            <th>درآمد کل</th>
                            <th>سهم سایت</th>
                            <th>سهم کاربر</th>
                            <th>مالیات</th>
                            <th>خالص</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revenues as $rev): ?>
                        <tr>
                            <td><?= e($rev->period) ?></td>
                            <td><?= number_format($rev->views) ?></td>
                            <td><?= number_format($rev->total_revenue) ?></td>
                            <td><?= number_format($rev->site_share_amount) ?> (<?= e($rev->site_share_percent) ?>%)</td>
                            <td><?= number_format($rev->user_share_amount) ?> (<?= e($rev->user_share_percent) ?>%)</td>
                            <td><?= number_format($rev->tax_amount) ?></td>
                            <td><strong><?= number_format($rev->net_user_amount) ?></strong></td>
                            <td>
                                <?php
                                $rsl = [
                                    'pending' => ['در انتظار', 'badge-warning'],
                                    'approved' => ['تأیید', 'badge-info'],
                                    'paid' => ['پرداخت شده', 'badge-success'],
                                    'cancelled' => ['لغو', 'badge-danger'],
                                ][$rev->status] ?? ['؟', 'badge-secondary'];
                                ?>
                                <span class="badge <?= e($rsl[1]) ?>"><?= e($rsl[0]) ?></span>
                            </td>
                            <td>
                                <?php if ($rev->status === 'pending'): ?>
                                    <button class="btn btn-xs btn-success" onclick="approveRevenue(<?= e($rev->id) ?>)">تأیید</button>
                                <?php elseif ($rev->status === 'approved'): ?>
                                    <button class="btn btn-xs btn-primary" onclick="payRevenue(<?= e($rev->id) ?>)">پرداخت</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function approveRevenue(id) {
    confirmAction('تأیید درآمد', 'آیا از تأیید این درآمد مطمئنید؟', function() {
        fetch(`<?= url('/admin/content/revenue/') ?>${id}/approve`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' }
        }).then(r => r.json()).then(res => {
            res.success ? notyf.success(res.message) : notyf.error(res.message);
            if (res.success) setTimeout(() => location.reload(), 1000);
        });
    });
}

function payRevenue(id) {
    confirmAction('پرداخت درآمد', 'آیا مطمئنید؟ مبلغ به کیف پول کاربر واریز خواهد شد.', function() {
        fetch(`<?= url('/admin/content/revenue/') ?>${id}/pay`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' }
        }).then(r => r.json()).then(res => {
            res.success ? notyf.success(res.message) : notyf.error(res.message);
            if (res.success) setTimeout(() => location.reload(), 1000);
        });
    });
}

function suspendContent(id) {
    Swal.fire({
        title: 'تعلیق محتوا', input: 'textarea',
        inputLabel: 'دلیل تعلیق', inputPlaceholder: 'حداقل ۱۰ کاراکتر...',
        showCancelButton: true, confirmButtonText: 'تعلیق', cancelButtonText: 'انصراف',
        confirmButtonColor: '#333',
        inputValidator: v => (!v || v.length < 10) ? 'حداقل ۱۰ کاراکتر' : null
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/content/') ?>${id}/suspend`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
                body: JSON.stringify({ reason: result.value })
            }).then(r => r.json()).then(res => {
                res.success ? notyf.success(res.message) : notyf.error(res.message);
                if (res.success) setTimeout(() => location.reload(), 1000);
            });
        }
    });
}

function confirmAction(title, text, callback) {
    Swal.fire({
        title, text, icon: 'question',
        showCancelButton: true, confirmButtonText: 'بله', cancelButtonText: 'انصراف'
    }).then(r => { if (r.isConfirmed) callback(); });
}
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
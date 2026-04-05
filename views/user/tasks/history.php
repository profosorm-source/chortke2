<?php
// views/user/tasks/history.php
$title = 'تاریخچه تسک‌ها';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-tasks.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">history</i> تاریخچه تسک‌ها</h4>
</div>

<!-- آمار -->
<div class="stats-row">
    <div class="stat-card stat-green">
        <span class="stat-num"><?= number_format($stats->approved ?? 0) ?></span>
        <span class="stat-lbl">تایید شده</span>
    </div>
    <div class="stat-card stat-red">
        <span class="stat-num"><?= number_format($stats->rejected ?? 0) ?></span>
        <span class="stat-lbl">رد شده</span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-num"><?= number_format($stats->total_earned ?? 0) ?></span>
        <span class="stat-lbl">کل درآمد</span>
    </div>
</div>

<!-- فیلتر -->
<div class="filter-bar">
    <a href="<?= url('/tasks/history') ?>" class="filter-btn <?= empty($status) ? 'active' : '' ?>">همه</a>
    <a href="<?= url('/tasks/history?status=submitted') ?>" class="filter-btn <?= $status === 'submitted' ? 'active' : '' ?>">در انتظار</a>
    <a href="<?= url('/tasks/history?status=approved') ?>" class="filter-btn <?= $status === 'approved' ? 'active' : '' ?>">تایید شده</a>
    <a href="<?= url('/tasks/history?status=rejected') ?>" class="filter-btn <?= $status === 'rejected' ? 'active' : '' ?>">رد شده</a>
    <a href="<?= url('/tasks/history?status=expired') ?>" class="filter-btn <?= $status === 'expired' ? 'active' : '' ?>">منقضی</a>
    <a href="<?= url('/tasks/history?status=disputed') ?>" class="filter-btn <?= $status === 'disputed' ? 'active' : '' ?>">اختلاف</a>
</div>

<?php if (empty($executions)): ?>
    <div class="empty-state">
        <i class="material-icons">inbox</i>
        <h5>تسکی یافت نشد</h5>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>عنوان</th>
                    <th>پلتفرم</th>
                    <th>نوع</th>
                    <th>پاداش</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($executions as $exec): ?>
                    <tr>
                        <td><?= e($exec->id) ?></td>
                        <td><?= e($exec->ad_title ?? '—') ?></td>
                        <td>
                            <span class="badge-sm platform-<?= e($exec->ad_platform ?? '') ?>">
                                <?= e(social_platform_label($exec->ad_platform ?? '')) ?>
                            </span>
                        </td>
                        <td><?= e(ad_task_type_label($exec->ad_task_type ?? '')) ?></td>
                        <td>
                            <strong><?= number_format($exec->reward_amount) ?></strong>
                            <small><?= $exec->reward_currency === 'usdt' ? 'تتر' : 'تومان' ?></small>
                        </td>
                        <td>
                            <span class="badge badge-<?= e(task_execution_status_badge($exec->status)) ?>">
                                <?= e(task_execution_status_label($exec->status)) ?>
                            </span>
                        </td>
                        <td><?= to_jalali($exec->created_at) ?></td>
                        <td>
                            <?php if ($exec->status === 'rejected' && !$exec->commission_paid): ?>
                                <button class="btn btn-xs btn-warning btn-dispute"
                                        data-id="<?= e($exec->id) ?>">
                                    <i class="material-icons">gavel</i> اعتراض
                                </button>
                            <?php endif; ?>

                            <?php if ($exec->rejection_reason): ?>
                                <button class="btn btn-xs btn-outline-secondary btn-show-reason"
                                        data-reason="<?= e($exec->rejection_reason) ?>">
                                    <i class="material-icons">info</i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- صفحه‌بندی -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="<?= url('/tasks/history?page=' . $i . ($status ? '&status=' . $status : '')) ?>"
                   class="page-link <?= $i === $page ? 'active' : '' ?>"><?= e($i) ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
// نمایش دلیل رد
document.querySelectorAll('.btn-show-reason').forEach(btn => {
    btn.addEventListener('click', function() {
        Swal.fire({
            title: 'دلیل رد',
            text: this.dataset.reason,
            icon: 'info',
            confirmButtonText: 'متوجه شدم'
        });
    });
});

// اعتراض
document.querySelectorAll('.btn-dispute').forEach(btn => {
    btn.addEventListener('click', function() {
        const execId = this.dataset.id;

        Swal.fire({
            title: 'ثبت اعتراض',
            input: 'textarea',
            inputLabel: 'دلیل اعتراض خود را بنویسید:',
            inputPlaceholder: 'توضیح دهید چرا معتقدید تسک به درستی انجام شده...',
            showCancelButton: true,
            confirmButtonText: 'ارسال اعتراض',
            cancelButtonText: 'انصراف',
            confirmButtonColor: '#ff9800',
            inputValidator: (value) => {
                if (!value) return 'لطفاً دلیل اعتراض را بنویسید.';
            }
        }).then(result => {
            if (result.isConfirmed) {
                fetch(`<?= url('/tasks') ?>/${execId}/dispute`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                    },
                    body: JSON.stringify({
                        reason: result.value,
                        _csrf_token: '<?= csrf_token() ?>'
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        notyf.success(data.message);
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        notyf.error(data.message);
                    }
                })
                .catch(() => notyf.error('خطا در ارتباط'));
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
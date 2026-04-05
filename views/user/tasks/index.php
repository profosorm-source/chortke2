<?php
// views/user/tasks/index.php
$title = 'انجام تسک';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-tasks.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">assignment</i> تسک‌های فعال</h4>
</div>

<!-- آمار کاربر -->
<div class="stats-row">
    <div class="stat-card stat-green">
        <i class="material-icons">check_circle</i>
        <div>
            <span class="stat-num"><?= number_format($stats->approved ?? 0) ?></span>
            <span class="stat-lbl">تایید شده</span>
        </div>
    </div>
    <div class="stat-card stat-orange">
        <i class="material-icons">hourglass_empty</i>
        <div>
            <span class="stat-num"><?= number_format(($stats->total ?? 0) - ($stats->approved ?? 0) - ($stats->rejected ?? 0) - ($stats->expired ?? 0)) ?></span>
            <span class="stat-lbl">در انتظار</span>
        </div>
    </div>
    <div class="stat-card stat-blue">
        <i class="material-icons">account_balance_wallet</i>
        <div>
            <span class="stat-num"><?= number_format($stats->total_earned ?? 0) ?></span>
            <span class="stat-lbl">کل درآمد</span>
        </div>
    </div>
</div>

<!-- هشدار حساب اجتماعی -->
<?php if (empty($socialAccounts)): ?>
    <div class="alert-box alert-warning">
        <i class="material-icons">warning</i>
        <div>
            <strong>توجه:</strong> برای انجام تسک‌هایی مثل فالو، لایک و سابسکرایب باید ابتدا
            <a href="<?= url('/social-accounts/create') ?>">حساب اجتماعی</a> خود را ثبت و تایید کنید.
        </div>
    </div>
<?php endif; ?>

<!-- لیست تسک‌ها -->
<?php if (empty($tasks)): ?>
    <div class="empty-state">
        <i class="material-icons">inbox</i>
        <h5>در حال حاضر تسک فعالی وجود ندارد</h5>
        <p>لطفاً بعداً مراجعه کنید.</p>
    </div>
<?php else: ?>
    <div class="tasks-grid">
        <?php foreach ($tasks as $task): ?>
            <div class="task-card" data-id="<?= e($task->id) ?>">
                <div class="task-card-top">
                    <div class="task-platform platform-<?= e($task->platform) ?>">
                        <?= e(social_platform_label($task->platform)) ?>
                    </div>
                    <span class="task-type-badge">
                        <?= e(ad_task_type_label($task->task_type)) ?>
                    </span>
                </div>

                <h5 class="task-title"><?= e($task->title) ?></h5>

                <?php if ($task->description): ?>
                    <p class="task-desc"><?= e(mb_substr($task->description, 0, 100)) ?><?= mb_strlen($task->description) > 100 ? '...' : '' ?></p>
                <?php endif; ?>

                <?php if ($task->target_username): ?>
                    <div class="task-target">
                        <i class="material-icons">person</i>
                        <span>@<?= e($task->target_username) ?></span>
                    </div>
                <?php endif; ?>

                <div class="task-card-bottom">
                    <div class="task-reward">
                        <i class="material-icons">paid</i>
                        <span><?= number_format($task->price_per_task) ?></span>
                        <small><?= $task->currency === 'usdt' ? 'تتر' : 'تومان' ?></small>
                    </div>

                    <div class="task-remaining">
                        <small><?= number_format($task->remaining_count) ?> باقیمانده</small>
                    </div>
                </div>

                <button class="btn btn-primary btn-block btn-start-task"
                        data-id="<?= e($task->id) ?>"
                        data-title="<?= e($task->title) ?>"
                        data-url="<?= e($task->target_url) ?>"
                        data-type="<?= e($task->task_type) ?>">
                    <i class="material-icons">play_arrow</i> شروع تسک
                </button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-start-task').forEach(btn => {
    btn.addEventListener('click', function() {
        const adId = this.dataset.id;
        const title = this.dataset.title;
        const targetUrl = this.dataset.url;

        Swal.fire({
            title: 'شروع تسک',
            html: `
                <p>آیا می‌خواهید تسک <strong>"${title}"</strong> را شروع کنید؟</p>
                <div style="text-align:right;font-size:12px;color:#666;margin-top:10px;">
                    <p>⏱ بعد از شروع، زمان محدودی برای انجام و ارسال مدرک دارید.</p>
                    <p>📸 باید اسکرین‌شات از انجام کار ارسال کنید.</p>
                    <p>⚠️ رفتار رباتیک باعث رد تسک می‌شود.</p>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'بله، شروع',
            cancelButtonText: 'انصراف',
            confirmButtonColor: '#4caf50'
        }).then(result => {
            if (result.isConfirmed) {
                // شروع تسک
                fetch('<?= url('/tasks/start') ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                    },
                    body: JSON.stringify({
                        ad_id: adId,
                        _csrf_token: '<?= csrf_token() ?>'
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        notyf.success(data.message);
                        // باز کردن لینک هدف
                        if (data.target_url) {
                            window.open(data.target_url, '_blank');
                        }
                        // ریدایرکت به صفحه انجام
                        if (data.execution && data.execution.id) {
                            setTimeout(() => {
                                window.location.href = '<?= url('/tasks') ?>/' + data.execution.id + '/execute';
                            }, 1500);
                        }
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
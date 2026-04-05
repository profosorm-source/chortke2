<?php
// views/admin/ad-tasks/show.php
$title = 'جزئیات تسک';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-ad-tasks.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">info</i> تسک #<?= e($task->id) ?></h4>
    <a href="<?= url('/admin/ad-tasks') ?>" class="btn btn-outline-sm"><i class="material-icons">arrow_forward</i> بازگشت</a>
</div>

<div class="detail-grid">
    <div class="card">
        <div class="card-header"><h5>اطلاعات تسک</h5></div>
        <div class="card-body">
            <div class="detail-row"><label>عنوان:</label><span><?= e($task->title) ?></span></div>
            <div class="detail-row"><label>سفارش‌دهنده:</label><span><?= e($task->advertiser_name ?? '') ?> (<?= e($task->advertiser_email ?? '') ?>)</span></div>
            <div class="detail-row"><label>پلتفرم:</label><span class="badge-sm platform-<?= e($task->platform) ?>"><?= e(social_platform_label($task->platform)) ?></span></div>
            <div class="detail-row"><label>نوع:</label><span><?= e(ad_task_type_label($task->task_type)) ?></span></div>
            <?php $safeUrl = preg_match('#^https?://#i', $task->target_url ?? '') ? $task->target_url : '#'; ?>
            <div class="detail-row"><label>لینک:</label><a href="<?= e($safeUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($task->target_url) ?></a></div>
            <div class="detail-row"><label>وضعیت:</label><span class="badge badge-<?= e(ad_status_badge($task->status)) ?>"><?= e(ad_status_label($task->status)) ?></span></div>
            <div class="detail-row"><label>قیمت واحد:</label><span><?= number_format($task->price_per_task) ?> <?= $task->currency === 'usdt' ? 'تتر' : 'تومان' ?></span></div>
            <div class="detail-row"><label>بودجه کل:</label><span><?= number_format($task->total_budget) ?></span></div>
            <div class="detail-row"><label>بودجه باقیمانده:</label><span class="text-primary"><?= number_format($task->remaining_budget) ?></span></div>
            <div class="detail-row"><label>پیشرفت:</label><span><?= e($task->completed_count) ?> / <?= e($task->total_count) ?></span></div>
            <div class="detail-row"><label>کمیسیون سایت:</label><span><?= e($task->site_commission_percent) ?>%</span></div>
            <div class="detail-row"><label>مالیات:</label><span><?= e($task->tax_percent) ?>%</span></div>
            <div class="detail-row"><label>تاریخ:</label><span><?= to_jalali($task->created_at) ?></span></div>
            <?php if ($task->rejection_reason): ?>
                <div class="detail-row"><label>دلیل رد:</label><span class="text-danger"><?= e($task->rejection_reason) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($task->description): ?>
        <div class="card">
            <div class="card-header"><h5>توضیحات</h5></div>
            <div class="card-body"><p><?= nl2br(e($task->description)) ?></p></div>
        </div>
    <?php endif; ?>
</div>

<!-- اجرای تسک‌ها -->
<div class="card mt-15">
    <div class="card-header"><h5><i class="material-icons">list</i> لیست اجراها (<?= count($executions) ?>)</h5></div>
    <div class="card-body">
        <?php if (empty($executions)): ?>
            <p class="text-muted">هنوز اجرایی ثبت نشده.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr><th>#</th><th>انجام‌دهنده</th><th>وضعیت</th><th>پاداش</th><th>تقلب</th><th>تاریخ</th><th>عملیات</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($executions as $ex): ?>
                            <tr>
                                <td><?= e($ex->id) ?></td>
                                <td><?= e($ex->executor_name ?? '—') ?></td>
                                <td><span class="badge badge-<?= e(task_execution_status_badge($ex->status)) ?>"><?= e(task_execution_status_label($ex->status)) ?></span></td>
                                <td><?= number_format($ex->reward_amount) ?></td>
                                <td><?= e(round($ex->fraud_score)) ?></td>
                                <td><?= to_jalali($ex->created_at) ?></td>
                                <td><a href="<?= url('/admin/task-executions/' . $ex->id) ?>" class="btn btn-xs btn-outline-secondary"><i class="material-icons">visibility</i></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- عملیات -->
<?php if ($task->status === 'pending'): ?>
    <div class="card mt-15">
        <div class="card-header"><h5>عملیات مدیریت</h5></div>
        <div class="card-body">
            <div class="action-buttons">
                <button class="btn btn-success" onclick="approveTask(<?= e($task->id) ?>)"><i class="material-icons">check</i> تایید و فعال‌سازی</button>
                <button class="btn btn-danger" onclick="rejectTask(<?= e($task->id) ?>)"><i class="material-icons">close</i> رد تسک</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function approveTask(id) {
    Swal.fire({title:'تایید',text:'تسک فعال شود؟',icon:'question',showCancelButton:true,confirmButtonText:'تایید',cancelButtonText:'انصراف',confirmButtonColor:'#4caf50'})
    .then(r => {
        if (r.isConfirmed) {
            fetch(`<?= url('/admin/ad-tasks') ?>/${id}/approve`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>'},
                body: JSON.stringify({_csrf_token: '<?= csrf_token() ?>'})
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) { notyf.success(d.message); setTimeout(() => location.reload(), 1000); }
                else notyf.error(d.message);
            })
            .catch(() => notyf.error('خطا در ارتباط با سرور'));
        }
    });
}

function rejectTask(id) {
    Swal.fire({title:'رد',input:'textarea',inputLabel:'دلیل رد:',showCancelButton:true,confirmButtonText:'رد',cancelButtonText:'انصراف',confirmButtonColor:'#f44336',inputValidator:v=>{if(!v)return'الزامی';}})
    .then(r => {
        if (r.isConfirmed) {
            fetch(`<?= url('/admin/ad-tasks') ?>/${id}/reject`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>'},
                body: JSON.stringify({reason: r.value, _csrf_token: '<?= csrf_token() ?>'})
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) { notyf.success(d.message); setTimeout(() => location.reload(), 1000); }
                else notyf.error(d.message);
            })
            .catch(() => notyf.error('خطا در ارتباط با سرور'));
        }
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
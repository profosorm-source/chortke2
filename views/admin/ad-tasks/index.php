<?php
// views/admin/ad-tasks/index.php
$title = 'مدیریت تسک‌ها';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-ad-tasks.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">assignment</i> مدیریت تسک‌ها</h4>
</div>

<!-- آمار -->
<div class="stats-row-4">
    <div class="mini-stat"><span class="ms-num"><?= number_format($stats->total ?? 0) ?></span><span class="ms-lbl">کل</span></div>
    <div class="mini-stat ms-green"><span class="ms-num"><?= number_format($stats->active ?? 0) ?></span><span class="ms-lbl">فعال</span></div>
    <div class="mini-stat ms-orange"><span class="ms-num"><?= number_format($stats->pending ?? 0) ?></span><span class="ms-lbl">در انتظار</span></div>
    <div class="mini-stat ms-blue"><span class="ms-num"><?= number_format($stats->total_budget ?? 0) ?></span><span class="ms-lbl">بودجه کل</span></div>
</div>

<!-- فیلتر -->
<div class="filter-card">
    <form method="GET" action="<?= url('/admin/ad-tasks') ?>" class="filter-form">
        <select name="status" class="form-control-sm">
            <option value="">همه وضعیت‌ها</option>
            <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
            <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>فعال</option>
            <option value="paused" <?= ($filters['status'] ?? '') === 'paused' ? 'selected' : '' ?>>متوقف</option>
            <option value="completed" <?= ($filters['status'] ?? '') === 'completed' ? 'selected' : '' ?>>تکمیل</option>
            <option value="cancelled" <?= ($filters['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>لغو</option>
            <option value="rejected" <?= ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>رد شده</option>
        </select>
        <select name="platform" class="form-control-sm">
            <option value="">همه پلتفرم‌ها</option>
            <option value="instagram" <?= ($filters['platform'] ?? '') === 'instagram' ? 'selected' : '' ?>>اینستاگرام</option>
            <option value="youtube" <?= ($filters['platform'] ?? '') === 'youtube' ? 'selected' : '' ?>>یوتیوب</option>
            <option value="telegram" <?= ($filters['platform'] ?? '') === 'telegram' ? 'selected' : '' ?>>تلگرام</option>
            <option value="tiktok" <?= ($filters['platform'] ?? '') === 'tiktok' ? 'selected' : '' ?>>تیک‌تاک</option>
            <option value="twitter" <?= ($filters['platform'] ?? '') === 'twitter' ? 'selected' : '' ?>>توییتر</option>
        </select>
        <input type="text" name="search" class="form-control-sm" placeholder="جستجو..." value="<?= e($filters['search'] ?? '') ?>">
        <button type="submit" class="btn btn-sm btn-primary"><i class="material-icons">search</i></button>
    </form>
    <span class="filter-count"><?= number_format($total) ?> مورد</span>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>عنوان</th>
                <th>تبلیغ‌دهنده</th>
                <th>پلتفرم</th>
                <th>نوع</th>
                <th>قیمت</th>
                <th>پیشرفت</th>
                <th>بودجه باقی</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tasks as $task): ?>
                <tr>
                    <td><?= e($task->id) ?></td>
                    <td><a href="<?= url('/admin/ad-tasks/' . $task->id) ?>"><?= e($task->title) ?></a></td>
                    <td><?= e($task->advertiser_name ?? '') ?></td>
                    <td><span class="badge-sm platform-<?= e($task->platform) ?>"><?= e(social_platform_label($task->platform)) ?></span></td>
                    <td><?= e(ad_task_type_label($task->task_type)) ?></td>
                    <td><?= number_format($task->price_per_task) ?></td>
                    <td><?= e($task->completed_count) ?>/<?= e($task->total_count) ?></td>
                    <td><?= number_format($task->remaining_budget) ?></td>
                    <td><span class="badge badge-<?= e(ad_status_badge($task->status)) ?>"><?= e(ad_status_label($task->status)) ?></span></td>
                    <td><?= to_jalali($task->created_at) ?></td>
                    <td>
                        <?php if ($task->status === 'pending'): ?>
                            <button class="btn btn-xs btn-success btn-approve-task" data-id="<?= e($task->id) ?>"><i class="material-icons">check</i></button>
                            <button class="btn btn-xs btn-danger btn-reject-task" data-id="<?= e($task->id) ?>"><i class="material-icons">close</i></button>
                        <?php else: ?>
                            <a href="<?= url('/admin/ad-tasks/' . $task->id) ?>" class="btn btn-xs btn-outline-secondary"><i class="material-icons">visibility</i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        // allowlist + sanitize برای جلوگیری از تزریق پارامتر
        $allowedParams = array_map(
            fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'),
            array_intersect_key($_GET, array_flip(['status', 'platform', 'search']))
        );
        for ($i = 1; $i <= $totalPages; $i++):
            $qs = $allowedParams;
            $qs['page'] = $i;
        ?>
            <a href="<?= url('/admin/ad-tasks?' . http_build_query($qs)) ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= e($i) ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-approve-task').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        Swal.fire({title: 'تایید تسک', text: 'تسک فعال شود؟', icon: 'question', showCancelButton: true, confirmButtonText: 'تایید', cancelButtonText: 'انصراف', confirmButtonColor: '#4caf50'})
        .then(r => {
            if (r.isConfirmed) {
                fetch(`<?= url('/admin/ad-tasks') ?>/${id}/approve`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>'},
                    body: JSON.stringify({_csrf_token: '<?= csrf_token() ?>'})
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) { notyf.success(d.message); setTimeout(() => location.reload(), 800); }
                    else notyf.error(d.message);
                })
                .catch(() => notyf.error('خطا در ارتباط با سرور'));
            }
        });
    });
});

document.querySelectorAll('.btn-reject-task').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        Swal.fire({title: 'رد تسک', input: 'textarea', inputLabel: 'دلیل رد:', showCancelButton: true, confirmButtonText: 'رد', cancelButtonText: 'انصراف', confirmButtonColor: '#f44336', inputValidator: v => {if (!v) return 'دلیل الزامی';}})
        .then(r => {
            if (r.isConfirmed) {
                fetch(`<?= url('/admin/ad-tasks') ?>/${id}/reject`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>'},
                    body: JSON.stringify({reason: r.value, _csrf_token: '<?= csrf_token() ?>'})
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) { notyf.success(d.message); setTimeout(() => location.reload(), 800); }
                    else notyf.error(d.message);
                })
                .catch(() => notyf.error('خطا در ارتباط با سرور'));
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
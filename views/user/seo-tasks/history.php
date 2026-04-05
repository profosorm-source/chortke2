<?php
// views/user/seo-tasks/history.php
$title = 'تاریخچه جستجو';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-seo-tasks.css') ?>">


<div class="page-header"><h4><i class="material-icons">history</i> تاریخچه جستجوی کلمات کلیدی</h4></div>

<div class="stats-row">
    <div class="stat-card stat-green"><span class="stat-num"><?= number_format($stats->completed ?? 0) ?></span><span class="stat-lbl">تکمیل</span></div>
    <div class="stat-card stat-blue"><span class="stat-num"><?= number_format($stats->total_earned ?? 0) ?></span><span class="stat-lbl">کل درآمد</span></div>
</div>

<?php if (empty($executions)): ?>
    <div class="empty-state"><i class="material-icons">inbox</i><h5>تاریخچه‌ای وجود ندارد</h5></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>#</th><th>کلمه</th><th>مدت</th><th>پاداش</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
            <tbody>
                <?php foreach ($executions as $ex): ?>
                    <tr>
                        <td><?= e($ex->id) ?></td>
                        <td><?= e($ex->keyword_text ?? $ex->search_query) ?></td>
                        <td><?= e($ex->total_duration) ?>ثانیه</td>
                        <td><?= number_format($ex->reward_amount) ?></td>
                        <td><span class="badge badge-<?= e(seo_execution_status_badge($ex->status)) ?>"><?= e(seo_execution_status_label($ex->status)) ?></span></td>
                        <td><?= to_jalali($ex->started_at) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="pagination"><?php for($i=1;$i<=$totalPages;$i++): ?><a href="<?=url('/seo-tasks/history?page='.$i)?>" class="page-link <?=$i===$page?'active':''?>"><?= e($i) ?></a><?php endfor; ?></div>
    <?php endif; ?>
<?php endif; ?>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
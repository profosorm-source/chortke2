<?php
$pageTitle = 'آمار اعلان‌ها';
include VIEW_PATH . '/layouts/admin.php';
?>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card p-3">
            <small class="text-muted">کل اعلان‌ها</small>
            <h4><?= number_format((int)$stats['total_sent']) ?></h4>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3">
            <small class="text-muted">خوانده شده</small>
            <h4 class="text-success"><?= number_format((int)$stats['total_read']) ?></h4>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3">
            <small class="text-muted">خوانده نشده</small>
            <h4 class="text-danger"><?= number_format((int)$stats['total_unread']) ?></h4>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5>آمار بر اساس نوع</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($stats['by_type'] as $type => $count): ?>
                <div class="col-md-3 mb-2">
                    <div class="p-3 bg-light rounded">
                        <small class="text-muted"><?= e($type) ?></small>
                        <div class="fw-bold"><?= number_format((int)$count) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5>آخرین اعلان‌ها</h5>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>کاربر</th>
                    <th>نوع</th>
                    <th>عنوان</th>
                    <th>خوانده شده</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($stats['recent'] as $n): ?>
                <tr>
                    <td><?= (int)$n->id ?></td>
                    <td><?= (int)$n->user_id ?></td>
                    <td><?= e($n->type) ?></td>
                    <td><?= e($n->title) ?></td>
                    <td><?= $n->is_read ? 'بله' : 'خیر' ?></td>
                    <td><?= to_jalali($n->created_at) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
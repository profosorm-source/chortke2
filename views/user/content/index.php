<?php $title = 'محتواهای من'; $layout = 'user'; ob_start(); ?>

<div class="content-header">
    <h4><i class="material-icons">video_library</i> کسب درآمد از استعداد</h4>
    <a href="<?= url('/content/create') ?>" class="btn btn-primary btn-sm">
        <i class="material-icons">add</i> ارسال محتوای جدید
    </a>
</div>

<!-- آمار کلی -->
<div class="stats-grid">
    <div class="stat-card stat-blue">
        <div class="stat-icon"><i class="material-icons">folder</i></div>
        <div class="stat-info">
            <span class="stat-label">کل محتواها</span>
            <span class="stat-value"><?= e($stats['total']) ?></span>
        </div>
    </div>
    <div class="stat-card stat-orange">
        <div class="stat-icon"><i class="material-icons">hourglass_empty</i></div>
        <div class="stat-info">
            <span class="stat-label">در انتظار</span>
            <span class="stat-value"><?= e($stats['pending']) ?></span>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon"><i class="material-icons">check_circle</i></div>
        <div class="stat-info">
            <span class="stat-label">منتشر شده</span>
            <span class="stat-value"><?= e($stats['published']) ?></span>
        </div>
    </div>
    <div class="stat-card stat-purple">
        <div class="stat-icon"><i class="material-icons">account_balance_wallet</i></div>
        <div class="stat-info">
            <span class="stat-label">درآمد دریافتی</span>
            <span class="stat-value"><?= number_format($totalRevenue) ?></span>
        </div>
    </div>
</div>

<?php if ($pendingRevenue > 0): ?>
<div class="alert alert-info">
    <i class="material-icons">info</i>
    <span>مبلغ <strong><?= number_format($pendingRevenue) ?></strong> در انتظار پرداخت است.</span>
</div>
<?php endif; ?>

<!-- فیلتر -->
<div class="card">
    <div class="card-header">
        <h5>لیست محتواها</h5>
        <div class="filter-tabs">
            <a href="<?= url('/content') ?>" class="tab <?= !$currentStatus ? 'active' : '' ?>">همه</a>
            <a href="<?= url('/content?status=pending') ?>" class="tab <?= $currentStatus === 'pending' ? 'active' : '' ?>">در انتظار</a>
            <a href="<?= url('/content?status=approved') ?>" class="tab <?= $currentStatus === 'approved' ? 'active' : '' ?>">تأیید شده</a>
            <a href="<?= url('/content?status=published') ?>" class="tab <?= $currentStatus === 'published' ? 'active' : '' ?>">منتشر شده</a>
            <a href="<?= url('/content?status=rejected') ?>" class="tab <?= $currentStatus === 'rejected' ? 'active' : '' ?>">رد شده</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($submissions)): ?>
            <div class="empty-state">
                <i class="material-icons">movie_creation</i>
                <p>هنوز محتوایی ارسال نکرده‌اید.</p>
                <a href="<?= url('/content/create') ?>" class="btn btn-primary">ارسال اولین محتوا</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>عنوان</th>
                            <th>پلتفرم</th>
                            <th>وضعیت</th>
                            <th>تاریخ ارسال</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $item): ?>
                        <tr>
                            <td><?= e($item->id) ?></td>
                            <td>
                                <a href="<?= url('/content/' . $item->id) ?>">
                                    <?= e($item->title) ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($item->platform === 'aparat'): ?>
                                    <span class="badge badge-info">آپارات</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">یوتیوب</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $statusLabels = [
                                    'pending' => ['در انتظار', 'badge-warning'],
                                    'under_review' => ['در حال بررسی', 'badge-info'],
                                    'approved' => ['تأیید شده', 'badge-success'],
                                    'published' => ['منتشر شده', 'badge-primary'],
                                    'rejected' => ['رد شده', 'badge-danger'],
                                    'suspended' => ['تعلیق', 'badge-dark'],
                                ];
                                $sl = $statusLabels[$item->status] ?? ['نامشخص', 'badge-secondary'];
                                ?>
                                <span class="badge <?= e($sl[1]) ?>"><?= e($sl[0]) ?></span>
                            </td>
                            <td><?= e(to_jalali($item->created_at ?? '')) ?></td>
                            <td>
                                <a href="<?= url('/content/' . $item->id) ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="material-icons">visibility</i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- صفحه‌بندی -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-wrapper">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="<?= url('/content?page=' . $i . ($currentStatus ? '&status=' . $currentStatus : '')) ?>"
                       class="page-link <?= $i === $currentPage ? 'active' : '' ?>"><?= e($i) ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
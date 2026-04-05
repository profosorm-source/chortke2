<?php $title = 'مدیریت محتوا'; $layout = 'admin'; ob_start(); ?>

<div class="content-header">
    <h4><i class="material-icons">video_library</i> مدیریت محتوا و استعداد</h4>
</div>

<!-- آمار -->
<div class="stats-grid">
    <div class="stat-card stat-orange">
        <div class="stat-icon"><i class="material-icons">hourglass_empty</i></div>
        <div class="stat-info">
            <span class="stat-label">در انتظار</span>
            <span class="stat-value"><?= e($stats->pending_count ?? 0) ?></span>
        </div>
    </div>
    <div class="stat-card stat-blue">
        <div class="stat-icon"><i class="material-icons">rate_review</i></div>
        <div class="stat-info">
            <span class="stat-label">در حال بررسی</span>
            <span class="stat-value"><?= e($stats->review_count ?? 0) ?></span>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon"><i class="material-icons">public</i></div>
        <div class="stat-info">
            <span class="stat-label">منتشر شده</span>
            <span class="stat-value"><?= e($stats->published_count ?? 0) ?></span>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon"><i class="material-icons">cancel</i></div>
        <div class="stat-info">
            <span class="stat-label">رد شده</span>
            <span class="stat-value"><?= e($stats->rejected_count ?? 0) ?></span>
        </div>
    </div>
</div>

<!-- فیلتر -->
<div class="card">
    <div class="card-header">
        <h5>لیست محتواها (<?= number_format($total) ?>)</h5>
        <div class="header-actions">
            <form method="GET" class="d-flex gap-2" style="display:flex; gap:8px;">
                <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                    <option value="approved" <?= ($filters['status'] ?? '') === 'approved' ? 'selected' : '' ?>>تأیید شده</option>
                    <option value="published" <?= ($filters['status'] ?? '') === 'published' ? 'selected' : '' ?>>منتشر شده</option>
                    <option value="rejected" <?= ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>رد شده</option>
                </select>
                <select name="platform" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="">همه پلتفرم‌ها</option>
                    <option value="aparat" <?= ($filters['platform'] ?? '') === 'aparat' ? 'selected' : '' ?>>آپارات</option>
                    <option value="youtube" <?= ($filters['platform'] ?? '') === 'youtube' ? 'selected' : '' ?>>یوتیوب</option>
                </select>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="جستجو..."
                       value="<?= e($filters['search'] ?? '') ?>">
                <button type="submit" class="btn btn-sm btn-primary"><i class="material-icons">search</i></button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($submissions)): ?>
            <div class="empty-state">
                <i class="material-icons">inbox</i>
                <p>محتوایی یافت نشد.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>کاربر</th>
                            <th>عنوان</th>
                            <th>پلتفرم</th>
                            <th>وضعیت</th>
                            <th>تاریخ</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $item): ?>
                        <tr>
                            <td><?= e($item->id) ?></td>
                            <td><?= e($item->user_name ?? 'نامشخص') ?></td>
                            <td>
                                <a href="<?= url('/admin/content/' . $item->id) ?>">
                                    <?= e(\mb_substr($item->title, 0, 40)) ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge <?= $item->platform === 'aparat' ? 'badge-info' : 'badge-danger' ?>">
                                    <?= $item->platform === 'aparat' ? 'آپارات' : 'یوتیوب' ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $statusLabels = [
                                    'pending' => ['در انتظار', 'badge-warning'],
                                    'under_review' => ['بررسی', 'badge-info'],
                                    'approved' => ['تأیید', 'badge-success'],
                                    'published' => ['منتشر', 'badge-primary'],
                                    'rejected' => ['رد', 'badge-danger'],
                                    'suspended' => ['تعلیق', 'badge-dark'],
                                ];
                                $sl = $statusLabels[$item->status] ?? ['؟', 'badge-secondary'];
                                ?>
                                <span class="badge <?= e($sl[1]) ?>"><?= e($sl[0]) ?></span>
                            </td>
                            <td><?= e(to_jalali($item->created_at ?? '')) ?></td>
                            <td>
                                <div class="btn-group">
                                    <a href="<?= url('/admin/content/' . $item->id) ?>" class="btn btn-sm btn-outline-primary" title="مشاهده">
                                        <i class="material-icons">visibility</i>
                                    </a>
                                    <?php if ($item->status === 'pending' || $item->status === 'under_review'): ?>
                                    <button class="btn btn-sm btn-outline-success" title="تأیید"
                                            onclick="approveContent(<?= e($item->id) ?>)">
                                        <i class="material-icons">check</i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" title="رد"
                                            onclick="rejectContent(<?= e($item->id) ?>)">
                                        <i class="material-icons">close</i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($item->status === 'approved'): ?>
                                    <button class="btn btn-sm btn-outline-info" title="ثبت انتشار"
                                            onclick="publishContent(<?= e($item->id) ?>)">
                                        <i class="material-icons">public</i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="pagination-wrapper">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php
                    $queryParams = \http_build_query(\array_merge($filters, ['page' => $i]));
                    ?>
                    <a href="<?= url('/admin/content?' . $queryParams) ?>"
                       class="page-link <?= $i === $currentPage ? 'active' : '' ?>"><?= e($i) ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function approveContent(id) {
    Swal.fire({
        title: 'تأیید محتوا',
        text: 'آیا از تأیید این محتوا مطمئن هستید؟',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'بله، تأیید کن',
        cancelButtonText: 'انصراف'
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/content/') ?>${id}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                }
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    notyf.success(res.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(res.message);
                }
            });
        }
    });
}

function rejectContent(id) {
    Swal.fire({
        title: 'رد محتوا',
        input: 'textarea',
        inputLabel: 'دلیل رد',
        inputPlaceholder: 'دلیل رد محتوا را وارد کنید (حداقل ۱۰ کاراکتر)...',
        inputAttributes: { minlength: 10, required: true },
        showCancelButton: true,
        confirmButtonText: 'رد کن',
        cancelButtonText: 'انصراف',
        confirmButtonColor: '#f44336',
        inputValidator: (value) => {
            if (!value || value.length < 10) return 'حداقل ۱۰ کاراکتر وارد کنید.';
        }
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/content/') ?>${id}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify({ reason: result.value })
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    notyf.success(res.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(res.message);
                }
            });
        }
    });
}

function publishContent(id) {
    Swal.fire({
        title: 'ثبت انتشار',
        html: `
            <div style="text-align:right; direction:rtl;">
                <div class="form-group" style="margin-bottom:10px;">
                    <label>لینک ویدیو منتشرشده</label>
                    <input id="swal-url" class="swal2-input" dir="ltr" placeholder="https://...">
                </div>
                <div class="form-group">
                    <label>نام کانال</label>
                    <input id="swal-channel" class="swal2-input" placeholder="نام کانال مجموعه">
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'ثبت انتشار',
        cancelButtonText: 'انصراف',
        preConfirm: () => ({
            published_url: document.getElementById('swal-url').value,
            channel_name: document.getElementById('swal-channel').value
        })
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/content/') ?>${id}/publish`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify(result.value)
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    notyf.success(res.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(res.message);
                }
            });
        }
    });
}
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
<?php $title = 'مدیریت بنرها و تبلیغات'; $layout = 'admin'; ob_start(); ?>

<div class="container-fluid">
    <!-- آمار کلی -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background: linear-gradient(135deg, #4fc3f7, #29b6f6);"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon" style="background: rgba(79,195,247,0.1); color: #4fc3f7;">
                        <span class="material-icons">view_carousel</span>
                    </div>
                    <div class="me-3">
                        <div class="stat-label">کل بنرها</div>
                        <div class="stat-value"><?= e($stats['total'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background: linear-gradient(135deg, #4caf50, #43a047);"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon" style="background: rgba(76,175,80,0.1); color: #4caf50;">
                        <span class="material-icons">check_circle</span>
                    </div>
                    <div class="me-3">
                        <div class="stat-label">بنرهای فعال</div>
                        <div class="stat-value"><?= e($stats['active'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background: linear-gradient(135deg, #ffa726, #ff9800);"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon" style="background: rgba(255,167,38,0.1); color: #ffa726;">
                        <span class="material-icons">ads_click</span>
                    </div>
                    <div class="me-3">
                        <div class="stat-label">کل کلیک‌ها</div>
                        <div class="stat-value"><?= number_format($stats['total_clicks'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stat-card">
                <div class="stat-card-accent" style="background: linear-gradient(135deg, #ab47bc, #9c27b0);"></div>
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon" style="background: rgba(171,71,188,0.1); color: #ab47bc;">
                        <span class="material-icons">visibility</span>
                    </div>
                    <div class="me-3">
                        <div class="stat-label">کل نمایش‌ها</div>
                        <div class="stat-value"><?= number_format($stats['total_impressions'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (($stats['expiring_soon'] ?? 0) > 0): ?>
        <div class="alert alert-warning d-flex align-items-center mb-4" style="border-right: 4px solid #ffa726; background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);">
            <span class="material-icons me-2" style="color: #f57c00;">warning</span>
            <span style="color: #e65100;"><?= e($stats['expiring_soon']) ?> بنر در ۳ روز آینده منقضی خواهد شد</span>
        </div>
    <?php endif; ?>

    <!-- فیلتر و جستجو -->
    <div class="card mb-4">
        <div class="card-body p-3">
            <form method="GET" action="<?= url('/admin/banners') ?>" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">جستجو</label>
                    <input type="text" name="search" class="form-control" placeholder="عنوان یا لینک..." value="<?= e($filters['search'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">جایگاه</label>
                    <select name="placement" class="form-select">
                        <option value="">همه</option>
                        <?php foreach ($placements as $p): ?>
                            <option value="<?= e($p->slug) ?>" <?= ($filters['placement'] ?? '') === $p->slug ? 'selected' : '' ?>><?= e($p->title) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">وضعیت</label>
                    <select name="is_active" class="form-select">
                        <option value="">همه</option>
                        <option value="1" <?= ($filters['is_active'] ?? '') === '1' ? 'selected' : '' ?>>فعال</option>
                        <option value="0" <?= ($filters['is_active'] ?? '') === '0' ? 'selected' : '' ?>>غیرفعال</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><span class="material-icons" style="font-size:16px;vertical-align:middle;">search</span> فیلتر</button>
                    <a href="<?= url('/admin/banners') ?>" class="btn btn-outline-secondary">پاک کردن</a>
                    <a href="<?= url('/admin/banners/create') ?>" class="btn btn-success"><span class="material-icons" style="font-size:16px;vertical-align:middle;">add</span> بنر جدید</a>
                    <a href="<?= url('/admin/banners/placements') ?>" class="btn btn-outline-info"><span class="material-icons" style="font-size:16px;vertical-align:middle;">dashboard</span> جایگاه‌ها</a>
                </div>
            </form>
        </div>
    </div>

    <!-- لیست بنرها -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">لیست بنرها (<?= number_format($total) ?> مورد)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th style="width:80px;">تصویر</th>
                            <th>عنوان</th>
                            <th>جایگاه</th>
                            <th>وضعیت</th>
                            <th>تاریخ</th>
                            <th>کلیک</th>
                            <th>نمایش</th>
                            <th>CTR</th>
                            <th style="width:180px;">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($banners)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">بنری یافت نشد</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($banners as $banner): ?>
                                <tr id="banner-row-<?= e($banner->id) ?>">
                                    <td><?= e($banner->id) ?></td>
                                    <td>
                                        <?php if ($banner->image_path): ?>
                                            <img src="<?= asset($banner->image_path) ?>" alt="<?= e($banner->title) ?>" style="width:60px;height:40px;object-fit:cover;border-radius:6px;">
                                        <?php elseif ($banner->type === 'code'): ?>
                                            <span class="badge bg-secondary">HTML</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= e($banner->title) ?></strong>
                                        <?php if ($banner->link): ?>
                                            <br><small class="text-muted"><?= e(\mb_strimwidth($banner->link, 0, 40, '...')) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $placementLabels = [
                                            'header' => 'هدر',
                                            'footer' => 'فوتر',
                                            'sidebar' => 'سایدبار',
                                            'homepage' => 'صفحه اصلی',
                                            'dashboard_user' => 'پنل کاربر',
                                            'dashboard_admin' => 'پنل ادمین',
                                        ];
                                        ?>
                                        <span class="badge bg-info"><?= e($placementLabels[$banner->placement] ?? $banner->placement) ?></span>
                                    </td>
                                    <td>
                                        <div class="form-check form-switch">
                                            <input type="checkbox" class="form-check-input toggle-banner" 
                                                   data-id="<?= e($banner->id) ?>"
                                                   <?= $banner->is_active ? 'checked' : '' ?>>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($banner->start_date): ?>
                                            <small>از: <?= e(to_jalali($banner->start_date)) ?></small><br>
                                        <?php endif; ?>
                                        <?php if ($banner->end_date): ?>
                                            <small>تا: <?= e(to_jalali($banner->end_date)) ?></small>
                                            <?php if (\strtotime($banner->end_date) < \time()): ?>
                                                <br><span class="badge bg-danger">منقضی</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <small class="text-muted">بدون محدودیت</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= number_format($banner->clicks) ?></strong></td>
                                    <td><?= number_format($banner->impressions) ?></td>
                                    <td>
                                        <span class="badge <?= $banner->ctr > 5 ? 'bg-success' : ($banner->ctr > 1 ? 'bg-warning' : 'bg-secondary') ?>">
                                            <?= e($banner->ctr) ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?= url("/admin/banners/{$banner->id}/edit") ?>" class="btn btn-outline-primary" title="ویرایش">
                                                <span class="material-icons" style="font-size:16px;">edit</span>
                                            </a>
                                            <button class="btn btn-outline-info btn-stats" data-id="<?= e($banner->id) ?>" title="آمار">
                                                <span class="material-icons" style="font-size:16px;">bar_chart</span>
                                            </button>
                                            <button class="btn btn-outline-danger btn-delete" data-id="<?= e($banner->id) ?>" data-title="<?= e($banner->title) ?>" title="حذف">
                                                <span class="material-icons" style="font-size:16px;">delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- صفحه‌بندی -->
        <?php if ($totalPages > 1): ?>
            <div class="card-footer d-flex justify-content-center">
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= url('/admin/banners?' . \http_build_query(\array_merge($filters, ['page' => $i]))) ?>"><?= e($i) ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- مودال آمار -->
<div class="modal fade" id="statsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">آمار بنر</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="stats-loading" class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                    <p class="mt-2">در حال بارگذاری...</p>
                </div>
                <div id="stats-content" style="display:none;">
                    <div class="row mb-3">
                        <div class="col-4 text-center">
                            <h4 id="stat-clicks" class="text-primary">0</h4>
                            <small class="text-muted">کل کلیک‌ها</small>
                        </div>
                        <div class="col-4 text-center">
                            <h4 id="stat-impressions" class="text-info">0</h4>
                            <small class="text-muted">کل نمایش‌ها</small>
                        </div>
                        <div class="col-4 text-center">
                            <h4 id="stat-ctr" class="text-success">0%</h4>
                            <small class="text-muted">نرخ کلیک</small>
                        </div>
                    </div>
                    <canvas id="clickChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle بنر
    document.querySelectorAll('.toggle-banner').forEach(function(el) {
        el.addEventListener('change', function() {
            const id = this.dataset.id;
            fetch('<?= url('/admin/banners/') ?>' + id + '/toggle', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (typeof notyf !== 'undefined') {
                        notyf.success(data.message);
                    }
                } else {
                    el.checked = !el.checked;
                    if (typeof notyf !== 'undefined') {
                        notyf.error(data.message || 'خطایی رخ داد');
                    }
                }
            })
            .catch(() => {
                el.checked = !el.checked;
            });
        });
    });

    // حذف بنر
    document.querySelectorAll('.btn-delete').forEach(function(el) {
        el.addEventListener('click', function() {
            const id = this.dataset.id;
            const title = this.dataset.title;

            Swal.fire({
                title: 'حذف بنر',
                text: 'آیا از حذف بنر "' + title + '" مطمئن هستید؟',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f44336',
                cancelButtonText: 'انصراف',
                confirmButtonText: 'بله، حذف شود'
            }).then(function(result) {
                if (result.isConfirmed) {
                    fetch('<?= url('/admin/banners/') ?>' + id + '/delete', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                        }
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('banner-row-' + id).remove();
                            if (typeof notyf !== 'undefined') {
                                notyf.success(data.message);
                            }
                        } else {
                            if (typeof notyf !== 'undefined') {
                                notyf.error(data.message || 'خطایی رخ داد');
                            }
                        }
                    });
                }
            });
        });
    });

    // آمار بنر
    let clickChart = null;
    document.querySelectorAll('.btn-stats').forEach(function(el) {
        el.addEventListener('click', function() {
            const id = this.dataset.id;
            const modal = new bootstrap.Modal(document.getElementById('statsModal'));
            
            document.getElementById('stats-loading').style.display = 'block';
            document.getElementById('stats-content').style.display = 'none';
            modal.show();

            fetch('<?= url('/admin/banners/') ?>' + id + '/stats')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('stat-clicks').textContent = data.banner.clicks.toLocaleString();
                    document.getElementById('stat-impressions').textContent = data.banner.impressions.toLocaleString();
                    document.getElementById('stat-ctr').textContent = data.banner.ctr + '%';

                    document.getElementById('stats-loading').style.display = 'none';
                    document.getElementById('stats-content').style.display = 'block';

                    // نمودار
                    if (clickChart) clickChart.destroy();
                    const ctx = document.getElementById('clickChart').getContext('2d');
                    clickChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.daily_clicks.map(d => d.date),
                            datasets: [{
                                label: 'تعداد کلیک',
                                data: data.daily_clicks.map(d => d.click_count),
                                borderColor: '#4fc3f7',
                                backgroundColor: 'rgba(79,195,247,0.1)',
                                tension: 0.4,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                y: { beginAtZero: true }
                            }
                        }
                    });
                }
            });
        });
    });
});
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
<?php
$title = 'مدیریت سفارش‌های استوری/پست';
$layout = 'admin';
ob_start();

$statusLabels = story_order_status_labels_map();
$statusClasses = story_order_status_classes_map();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="page-title mb-0">
                <i class="material-icons text-primary">camera_alt</i>
                مدیریت سفارش‌های استوری/پست
            </h4>
        </div>
        <a href="<?= url('/admin/stories/influencers') ?>" class="btn btn-outline-primary btn-sm">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">groups</i>
            مدیریت اینفلوئنسرها
        </a>
    </div>
</div>

<!-- KPI -->
<div class="row mt-3">
    <div class="col-md-3 mb-3">
        <div class="card" style="border-top:3px solid #2196f3;">
            <div class="card-body">
                <small class="text-muted">کل سفارش‌ها</small>
                <h5 class="mb-0" style="font-weight:bold;"><?= number_format($stats->total_orders ?? 0) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card" style="border-top:3px solid #4caf50;">
            <div class="card-body">
                <small class="text-muted">تکمیل‌شده</small>
                <h5 class="mb-0" style="font-weight:bold;"><?= number_format($stats->completed_orders ?? 0) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card" style="border-top:3px solid #ff9800;">
            <div class="card-body">
                <small class="text-muted">در جریان</small>
                <h5 class="mb-0" style="font-weight:bold;"><?= number_format($stats->active_orders ?? 0) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card" style="border-top:3px solid #f57c00;">
            <div class="card-body">
                <small class="text-muted">درآمد سایت</small>
                <h5 class="mb-0" style="font-weight:bold;"><?= number_format($stats->total_site_earning ?? 0) ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- فیلتر -->
<div class="card mt-2">
    <div class="card-body">
        <form method="GET" action="<?= url('/admin/stories') ?>">
            <div class="row">
                <div class="col-md-4 mb-2">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="جستجو (یوزرنیم/نام)" value="<?= e($filters['search'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">همه وضعیت‌ها</option>
                        <?php foreach ($statusLabels as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <select name="order_type" class="form-select form-select-sm">
                        <option value="">همه انواع</option>
                        <option value="story" <?= ($filters['order_type'] ?? '') === 'story' ? 'selected' : '' ?>>استوری</option>
                        <option value="post" <?= ($filters['order_type'] ?? '') === 'post' ? 'selected' : '' ?>>پست موقت</option>
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <button class="btn btn-primary btn-sm w-100">فیلتر</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- جدول سفارش‌ها -->
<div class="card mt-3 mb-4">
    <div class="card-header d-flex justify-content-between">
        <h6 class="card-title mb-0">لیست سفارش‌ها</h6>
        <span class="badge bg-info"><?= number_format($total) ?> رکورد</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:12px;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>تبلیغ‌دهنده</th>
                        <th>اینفلوئنسر</th>
                        <th>نوع</th>
                        <th>کد</th>
                        <th>مبلغ</th>
                        <th>کارمزد</th>
                        <th>مدرک</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">رکوردی یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $idx => $o): ?>
                    <tr>
                        <td class="text-muted"><?= (($page - 1) * 30) + $idx + 1 ?></td>
                        <td><?= e($o->customer_name ?? '—') ?></td>
                        <td dir="ltr">@<?= e($o->influencer_username ?? '—') ?></td>
                        <td><?= $o->order_type === 'story' ? 'استوری' : 'پست' ?> / <?= (int)$o->duration_hours ?>h</td>
                        <td><code dir="ltr"><?= e($o->verification_code ?? '') ?></code></td>
                        <td><?= $o->currency === 'usdt' ? number_format($o->price, 2) : number_format($o->price) ?></td>
                        <td style="color:#f57c00;"><?= $o->currency === 'usdt' ? number_format($o->site_fee_amount, 2) : number_format($o->site_fee_amount) ?></td>
                        <td>
                            <?php if (!empty($o->proof_screenshot)): ?>
                                <a class="btn btn-sm btn-outline-info" target="_blank"
                                   href="<?= url('/file/view/story-proofs/' . \basename($o->proof_screenshot)) ?>">
                                    <i class="material-icons" style="font-size:14px;">image</i>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= $statusClasses[$o->status] ?? '' ?>"><?= e($statusLabels[$o->status] ?? $o->status) ?></span></td>
                        <td style="font-size:10px;"><?= to_jalali($o->created_at ?? '') ?></td>
                        <td>
                            <?php if ($o->status === 'proof_submitted'): ?>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-success btn-verify" data-id="<?= e($o->id) ?>" data-decision="approve">
                                    <i class="material-icons" style="font-size:14px;">check</i>
                                </button>
                                <button class="btn btn-sm btn-danger btn-verify" data-id="<?= e($o->id) ?>" data-decision="reject">
                                    <i class="material-icons" style="font-size:14px;">close</i>
                                </button>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (($pages ?? 1) > 1): ?>
    <div class="card-footer d-flex justify-content-center">
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= \min((int)$pages, 20); $i++): ?>
                <li class="page-item <?= $i === (int)$page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= url('/admin/stories?page=' . $i) ?>"><?= e($i) ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-verify').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var orderId = this.dataset.id;
            var decision = this.dataset.decision;

            if (decision === 'reject') {
                Swal.fire({
                    title: 'رد مدرک',
                    input: 'text',
                    inputLabel: 'دلیل رد:',
                    showCancelButton: true,
                    confirmButtonColor: '#f44336',
                    confirmButtonText: 'رد مدرک',
                    cancelButtonText: 'انصراف',
                    inputValidator: function(v){ if(!v) return 'دلیل را وارد کنید'; }
                }).then(function(result){
                    if(result.isConfirmed) sendVerify(orderId, 'reject', result.value);
                });
            } else {
                Swal.fire({
                    title: 'تأیید مدرک',
                    text: 'مبلغ سهم اینفلوئنسر پرداخت می‌شود و فایل‌ها حذف خواهند شد.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'تأیید',
                    cancelButtonText: 'انصراف'
                }).then(function(result){
                    if(result.isConfirmed) sendVerify(orderId, 'approve', null);
                });
            }
        });
    });

    function sendVerify(orderId, decision, reason) {
        fetch('<?= url('/admin/stories/verify-proof') ?>', {
            method: 'POST',
            headers: {'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'},
            body: JSON.stringify({csrf_token:'<?= csrf_token() ?>', order_id: orderId, decision: decision, reason: reason})
        })
        .then(r => r.json())
        .then(function(data){
            var notyf = new Notyf({duration: 3500, position: {x:'left',y:'top'}});
            if (data.success) { notyf.success(data.message); setTimeout(() => location.reload(), 1200); }
            else notyf.error(data.message);
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
<?php
$title = 'درآمدهای محتوا';
$layout = 'admin';
ob_start();
$revenues = $revenues ?? [];
$financialStats = $financialStats ?? null;
$total = $total ?? 0;
$totalPages = $totalPages ?? 1;
$currentPage = $currentPage ?? 1;
$filters = $filters ?? [];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">درآمدهای محتوا <span class="badge bg-secondary ms-2"><?= number_format($total) ?></span></h4>
</div>

<!-- آمار مالی -->
<?php if ($financialStats): ?>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card text-center p-3">
        <div class="text-muted small">مجموع تخصیص یافته</div>
        <div class="fs-5 fw-bold"><?= number_format((float)($financialStats->total_allocated ?? 0)) ?> <small>تومان</small></div>
    </div></div>
    <div class="col-md-4"><div class="card text-center p-3">
        <div class="text-muted small">پرداخت شده</div>
        <div class="fs-5 fw-bold text-success"><?= number_format((float)($financialStats->total_paid ?? 0)) ?> <small>تومان</small></div>
    </div></div>
    <div class="col-md-4"><div class="card text-center p-3">
        <div class="text-muted small">در انتظار پرداخت</div>
        <div class="fs-5 fw-bold text-warning"><?= number_format((float)($financialStats->total_pending ?? 0)) ?> <small>تومان</small></div>
    </div></div>
</div>
<?php endif; ?>

<!-- فیلترها -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <select name="status" class="form-select form-select-sm" style="width:160px">
                <option value="">همه وضعیت‌ها</option>
                <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                <option value="paid"    <?= ($filters['status'] ?? '') === 'paid'    ? 'selected' : '' ?>>پرداخت شده</option>
                <option value="failed"  <?= ($filters['status'] ?? '') === 'failed'  ? 'selected' : '' ?>>ناموفق</option>
            </select>
            <input type="text" name="user_id" class="form-control form-control-sm" style="width:130px"
                   value="<?= e($filters['user_id'] ?? '') ?>" placeholder="شناسه کاربر...">
            <button type="submit" class="btn btn-primary btn-sm">فیلتر</button>
            <?php if (!empty($filters['status']) || !empty($filters['user_id'])): ?>
                <a href="<?= url('/admin/content/revenues') ?>" class="btn btn-outline-secondary btn-sm">حذف فیلتر</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- جدول -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>کاربر</th>
                    <th>محتوا</th>
                    <th>مبلغ</th>
                    <th>نوع</th>
                    <th>تاریخ</th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($revenues as $r): ?>
                <tr>
                    <td><?= (int)$r->id ?></td>
                    <td>
                        <a href="<?= url('/admin/users/' . (int)($r->user_id ?? 0)) ?>">
                            <?= e($r->full_name ?? $r->username ?? 'کاربر #' . (int)$r->user_id) ?>
                        </a>
                    </td>
                    <td>
                        <?php if (!empty($r->content_id)): ?>
                            <a href="<?= url('/admin/content/' . (int)$r->content_id) ?>">
                                محتوا #<?= (int)$r->content_id ?>
                            </a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="fw-bold"><?= number_format((float)($r->amount ?? 0)) ?> <small class="text-muted">تومان</small></td>
                    <td><span class="badge bg-info"><?= e($r->type ?? '—') ?></span></td>
                    <td><?= to_jalali($r->created_at) ?></td>
                    <td>
                        <?php
                        $stMap = [
                            'pending' => ['در انتظار', 'warning'],
                            'paid'    => ['پرداخت شده','success'],
                            'failed'  => ['ناموفق',    'danger'],
                        ];
                        $si = $stMap[$r->status ?? ''] ?? [e($r->status ?? '—'), 'secondary'];
                        ?>
                        <span class="badge bg-<?= e($si[1]) ?>"><?= e($si[0]) ?></span>
                    </td>
                    <td>
                        <?php if (($r->status ?? '') === 'pending'): ?>
                        <button class="btn btn-success btn-sm" onclick="doPay(<?= (int)$r->id ?>)">
                            <i class="material-icons align-middle" style="font-size:14px">payments</i> پرداخت
                        </button>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($revenues)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">هیچ رکوردی یافت نشد</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex gap-1 justify-content-center">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= e($i) ?><?= !empty($filters['status']) ? '&status=' . e($filters['status']) : '' ?>"
               class="btn btn-sm <?= $i === $currentPage ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <?= e($i) ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<script>
const csrf = '<?= csrf_token() ?>';
function doPay(id) {
    if (!confirm('پرداخت این درآمد؟')) return;
    fetch(`<?= url('/admin/content/revenues') ?>/${id}/pay`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }
    }).then(r => r.json()).then(res => {
        if (res.success) location.reload();
        else alert(res.message || 'خطا');
    });
}
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/admin.php'; ?>

<?php
$title = 'مدیریت اینفلوئنسرها';
$layout = 'admin';
ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0">
            <i class="material-icons text-primary">groups</i>
            مدیریت اینفلوئنسرها
        </h4>
        <a href="<?= url('/admin/stories') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت
        </a>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <form method="GET" action="<?= url('/admin/stories/influencers') ?>">
            <div class="row">
                <div class="col-md-4 mb-2">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="جستجو (یوزرنیم/نام)" value="<?= e($filters['search'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                        <option value="verified" <?= ($filters['status'] ?? '') === 'verified' ? 'selected' : '' ?>>تأیید شده</option>
                        <option value="rejected" <?= ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>رد شده</option>
                        <option value="suspended" <?= ($filters['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>تعلیق</option>
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <button class="btn btn-primary btn-sm w-100">فیلتر</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card mt-3 mb-4">
    <div class="card-header d-flex justify-content-between">
        <h6 class="card-title mb-0">لیست پیج‌ها</h6>
        <span class="badge bg-info"><?= number_format($total) ?> رکورد</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:12px;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>پیج</th>
                        <th>کاربر</th>
                        <th>فالوور</th>
                        <th>تعرفه استوری</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($profiles)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">رکوردی یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($profiles as $idx => $p): ?>
                    <?php
                        $avatar = null;
                        if (!empty($p->profile_image)) {
                            $avatar = url('/file/view/influencer-profiles/' . \basename($p->profile_image));
                        }
                        $statusMap = [
                            'pending' => ['در انتظار', 'badge-warning'],
                            'verified' => ['تأیید شده', 'badge-success'],
                            'rejected' => ['رد شده', 'badge-danger'],
                            'suspended' => ['تعلیق', 'badge-danger'],
                        ];
                        $st = $statusMap[$p->status] ?? [$p->status, 'badge-secondary'];
                    ?>
                    <tr>
                        <td class="text-muted"><?= (($page - 1) * 30) + $idx + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:40px;height:40px;border-radius:50%;overflow:hidden;border:1px solid #eee;background:#f5f5f5;">
                                    <?php if ($avatar): ?>
                                        <img src="<?= e($avatar) ?>" style="width:100%;height:100%;object-fit:cover;">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                                            <i class="material-icons" style="font-size:20px;opacity:0.35;">person</i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div dir="ltr"><strong>@<?= e($p->username) ?></strong></div>
                                    <a href="<?= e($p->page_url) ?>" target="_blank" rel="noopener" dir="ltr" style="font-size:10px;"><?= e($p->page_url) ?></a>
                                </div>
                            </div>
                        </td>
                        <td><?= e($p->full_name ?? '—') ?></td>
                        <td><span class="badge bg-info"><?= number_format($p->follower_count) ?></span></td>
                        <td>
                            <?= $p->currency === 'usdt'
                                ? number_format($p->story_price_24h, 2) . ' USDT'
                                : number_format($p->story_price_24h) . ' تومان' ?>
                        </td>
                        <td><span class="badge <?= e($st[1]) ?>"><?= e($st[0]) ?></span></td>
                        <td style="font-size:10px;"><?= to_jalali($p->created_at ?? '') ?></td>
                        <td>
                            <?php if ($p->status === 'pending'): ?>
                                <button class="btn btn-sm btn-success btn-approve" data-id="<?= e($p->id) ?>" data-decision="approve">
                                    <i class="material-icons" style="font-size:14px;">check</i>
                                </button>
                                <button class="btn btn-sm btn-danger btn-approve" data-id="<?= e($p->id) ?>" data-decision="reject">
                                    <i class="material-icons" style="font-size:14px;">close</i>
                                </button>
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
                    <a class="page-link" href="<?= url('/admin/stories/influencers?page=' . $i) ?>"><?= e($i) ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.btn-approve').forEach(function(btn){
        btn.addEventListener('click', function(){
            var profileId = this.dataset.id;
            var decision = this.dataset.decision;

            if (decision === 'reject') {
                Swal.fire({
                    title: 'رد پیج',
                    input: 'text',
                    inputLabel: 'دلیل رد:',
                    showCancelButton: true,
                    confirmButtonColor: '#f44336',
                    confirmButtonText: 'رد',
                    cancelButtonText: 'انصراف',
                    inputValidator: function(v){ if(!v) return 'دلیل را وارد کنید'; }
                }).then(function(result){
                    if(result.isConfirmed) send(profileId, 'reject', result.value);
                });
            } else {
                Swal.fire({
                    title: 'تأیید پیج',
                    text: 'پس از تأیید، این پیج در لیست کاربران نمایش داده می‌شود.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'تأیید',
                    cancelButtonText: 'انصراف'
                }).then(function(result){
                    if(result.isConfirmed) send(profileId, 'approve', null);
                });
            }
        });
    });

    function send(profileId, decision, reason) {
        fetch('<?= url('/admin/stories/approve-influencer') ?>', {
            method: 'POST',
            headers: {'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'},
            body: JSON.stringify({csrf_token:'<?= csrf_token() ?>', profile_id: profileId, decision: decision, reason: reason})
        })
        .then(r => r.json())
        .then(function(data){
            var notyf = new Notyf({duration: 3000, position: {x:'left',y:'top'}});
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
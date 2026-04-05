<?php
// views/admin/seo-keywords/index.php
$title = 'کلمات کلیدی SEO';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-seo-keywords.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">search</i> کلمات کلیدی SEO</h4>
    <a href="<?= url('/admin/seo-keywords/create') ?>" class="btn btn-primary btn-sm"><i class="material-icons">add</i> کلمه جدید</a>
</div>

<div class="stats-row-4">
    <div class="mini-stat"><span class="ms-num"><?= number_format($stats->total ?? 0) ?></span><span class="ms-lbl">کل</span></div>
    <div class="mini-stat ms-green"><span class="ms-num"><?= number_format($stats->active ?? 0) ?></span><span class="ms-lbl">فعال</span></div>
    <div class="mini-stat ms-blue"><span class="ms-num"><?= number_format($stats->total_executions ?? 0) ?></span><span class="ms-lbl">کل اجرا</span></div>
    <div class="mini-stat ms-orange"><span class="ms-num"><?= number_format($stats->today_executions ?? 0) ?></span><span class="ms-lbl">امروز</span></div>
</div>

<div class="filter-card">
    <form method="GET" action="<?= url('/admin/seo-keywords') ?>" class="filter-form">
        <select name="is_active" class="form-control-sm">
            <option value="">همه</option>
            <option value="1" <?= ($filters['is_active'] ?? '') === '1' ? 'selected' : '' ?>>فعال</option>
            <option value="0" <?= ($filters['is_active'] ?? '') === '0' ? 'selected' : '' ?>>غیرفعال</option>
        </select>
        <input type="text" name="search" class="form-control-sm" placeholder="جستجو..." value="<?= e($filters['search'] ?? '') ?>">
        <button type="submit" class="btn btn-sm btn-primary"><i class="material-icons">search</i></button>
    </form>
    <span class="filter-count"><?= number_format($total) ?> مورد</span>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead><tr><th>#</th><th>کلمه</th><th>URL هدف</th><th>پاداش</th><th>اجرا امروز</th><th>اجرا کل</th><th>اولویت</th><th>وضعیت</th><th>عملیات</th></tr></thead>
        <tbody>
            <?php foreach ($keywords as $kw): ?>
                <tr>
                    <td><?= e($kw->id) ?></td>
                    <td><strong><?= e($kw->keyword) ?></strong></td>
                    <td><a href="<?= sanitize_url($kw->target_url) ?>" target="_blank" class="ltr-text"><?= e(mb_substr($kw->target_url, 0, 40)) ?></a></td>
                    <td><?= number_format($kw->reward_amount) ?></td>
                    <td><?= e($kw->today_executions) ?>/<?= e($kw->daily_budget) ?></td>
                    <td><?= number_format($kw->total_executions) ?></td>
                    <td><?= e($kw->priority) ?></td>
                    <td>
                        <button class="btn btn-xs <?= $kw->is_active ? 'btn-success' : 'btn-secondary' ?> btn-toggle" data-id="<?= e($kw->id) ?>">
                            <?= $kw->is_active ? 'فعال' : 'غیرفعال' ?>
                        </button>
                    </td>
                    <td>
                        <a href="<?= url('/admin/seo-keywords/' . $kw->id . '/edit') ?>" class="btn btn-xs btn-outline-secondary"><i class="material-icons">edit</i></a>
                        <button class="btn btn-xs btn-danger btn-del-kw" data-id="<?= e($kw->id) ?>"><i class="material-icons">delete</i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination"><?php
        // فقط پارامترهای مجاز را در URL pagination قرار می‌دهیم — از $_GET مستقیم استفاده نمی‌شود
        $allowedQs = array_filter([
            'status'   => htmlspecialchars($_GET['status']   ?? '', ENT_QUOTES, 'UTF-8'),
            'platform' => htmlspecialchars($_GET['platform'] ?? '', ENT_QUOTES, 'UTF-8'),
            'search'   => htmlspecialchars($_GET['search']   ?? '', ENT_QUOTES, 'UTF-8'),
        ]);
        for ($i = 1; $i <= $totalPages; $i++) {
            $qs = $allowedQs;
            $qs['page'] = $i;
    ?><a href="<?= url('/admin/seo-keywords?' . http_build_query($qs)) ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= e($i) ?></a><?php } ?></div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-toggle').forEach(btn=>{btn.addEventListener('click',function(){const id=this.dataset.id;fetch(`<?=url('/admin/seo-keywords')?>/${id}/toggle`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({_csrf_token:'<?=csrf_token()?>'})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),500);}else notyf.error(d.message);});});});

document.querySelectorAll('.btn-del-kw').forEach(btn=>{btn.addEventListener('click',function(){const id=this.dataset.id;Swal.fire({title:'حذف',text:'مطمئن هستید؟',icon:'warning',showCancelButton:true,confirmButtonText:'حذف',cancelButtonText:'انصراف',confirmButtonColor:'#f44336'}).then(r=>{if(r.isConfirmed){fetch(`<?=url('/admin/seo-keywords')?>/${id}/delete`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({_csrf_token:'<?=csrf_token()?>'})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),500);}else notyf.error(d.message);});}});});});
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
<?php
// views/user/seo-tasks/index.php
$title = 'جستجوی کلمات کلیدی';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-seo-tasks.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">search</i> جستجوی کلمات کلیدی</h4>
</div>

<div class="stats-row">
    <div class="stat-card stat-green"><span class="stat-num"><?= number_format($stats->completed ?? 0) ?></span><span class="stat-lbl">تکمیل شده</span></div>
    <div class="stat-card stat-blue"><span class="stat-num"><?= number_format($stats->total_earned ?? 0) ?></span><span class="stat-lbl">کل درآمد</span></div>
    <div class="stat-card stat-orange"><span class="stat-num"><?= e($todayCount) ?></span><span class="stat-lbl">امروز</span></div>
</div>

<div class="alert-box alert-info mb-15">
    <i class="material-icons">info</i>
    <div>
        <strong>راهنما:</strong> کلمه کلیدی را انتخاب کنید. سیستم آن را در گوگل جستجو کرده و صفحه هدف را برای شما باز می‌کند.
        سپس به‌صورت خودکار صفحه را مرور می‌کنید. <strong>از صفحه خارج نشوید</strong> تا پاداش دریافت کنید.
    </div>
</div>

<?php if (empty($keywords)): ?>
    <div class="empty-state"><i class="material-icons">inbox</i><h5>کلمه‌ای برای جستجو وجود ندارد</h5><p>لطفاً بعداً مراجعه کنید.</p></div>
<?php else: ?>
    <div class="seo-grid">
        <?php foreach ($keywords as $kw): ?>
            <div class="seo-card" data-id="<?= e($kw->id) ?>">
                <div class="seo-keyword">
                    <i class="material-icons">search</i>
                    <span><?= e($kw->keyword) ?></span>
                </div>
                <div class="seo-meta">
                    <span><i class="material-icons">link</i> <?= e(parse_url($kw->target_url, PHP_URL_HOST) ?? $kw->target_url) ?></span>
                    <span><i class="material-icons">timer</i> ~<?= e($kw->total_browse_seconds) ?>ثانیه</span>
                </div>
                <?php if ($kw->target_position > 0): ?>
                    <div class="seo-position">رتبه فعلی: <strong><?= e($kw->target_position) ?></strong></div>
                <?php endif; ?>
                <div class="seo-reward">
                    <i class="material-icons">paid</i>
                    <span><?= number_format($kw->reward_amount) ?></span>
                    <small><?= $kw->currency === 'usdt' ? 'تتر' : 'تومان' ?></small>
                </div>
                <button class="btn btn-primary btn-block btn-start-seo" data-id="<?= e($kw->id) ?>" data-keyword="<?= e($kw->keyword) ?>">
                    <i class="material-icons">play_arrow</i> شروع جستجو
                </button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-start-seo').forEach(btn=>{
    btn.addEventListener('click',function(){
        const id=this.dataset.id, kw=this.dataset.keyword;
        Swal.fire({title:'شروع جستجو',html:`<p>کلمه: <strong>"${kw}"</strong></p><p style="font-size:12px;color:#666;">صفحه هدف در تب جدید باز شده و به‌صورت خودکار مرور می‌شود. لطفاً تب را نبندید.</p>`,icon:'question',showCancelButton:true,confirmButtonText:'شروع',cancelButtonText:'انصراف',confirmButtonColor:'#4caf50'})
        .then(r=>{if(r.isConfirmed){
            fetch('<?=url('/seo-tasks/start')?>',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({keyword_id:id,_csrf_token:'<?=csrf_token()?>'})})
            .then(r=>r.json()).then(d=>{
                if(d.success){
                    notyf.success(d.message);
                    if(d.execution&&d.execution.id){
                        setTimeout(()=>{window.location.href='<?=url('/seo-tasks')?>/' + d.execution.id + '/execute';},800);
                    }
                }else notyf.error(d.message);
            }).catch(()=>notyf.error('خطا'));
        }});
    });
});
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
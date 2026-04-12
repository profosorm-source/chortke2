<?php
// views/user/seo-tasks/execute.php
$title = 'مرور صفحه';
$layout = 'user';
ob_start();
$googleUrl = 'https://www.google.com/search?q=' . urlencode($keyword->keyword);
?>

<div class="page-header">
    <h4><i class="material-icons">search</i> مرور صفحه — <?= e($keyword->keyword) ?></h4>
</div>

<div class="alert-box alert-warning mb-15">
    <i class="material-icons">warning</i>
    <div>
        <strong>توجه:</strong> از صفحه خارج نشوید. سیستم به‌صورت خودکار صفحه را مرور می‌کند. پس از تکمیل، پاداش به حساب شما واریز می‌شود.
    </div>
</div>

<!-- پیشرفت -->
<div class="seo-progress-card">
    <div class="seo-progress-bar"><div class="seo-progress-fill" id="seoProgressFill" style="width:0%"></div></div>
    <div class="seo-progress-info">
        <span id="seoElapsed">00:00</span>
        <span id="seoStatus">▶ در حال مرور...</span>
        <span id="seoPercent">0%</span>
    </div>
</div>

<!-- اطلاعات -->
<div class="card mt-15">
    <div class="card-body">
        <div class="info-row"><label>کلمه کلیدی:</label><span><strong><?= e($keyword->keyword) ?></strong></span></div>
        <div class="info-row"><label>صفحه هدف:</label><a href="<?= sanitize_url($keyword->target_url) ?>" target="_blank" class="ltr-text"><?= e($keyword->target_url) ?></a></div>
        <div class="info-row"><label>پاداش:</label><span class="text-success"><?= number_format($execution->reward_amount) ?> <?= $execution->reward_currency === 'usdt' ? 'تتر' : 'تومان' ?></span></div>
    </div>
</div>

<!-- محتوای صفحه هدف (iframe) -->
<div class="card mt-15">
    <div class="card-header"><h5><i class="material-icons">web</i> صفحه هدف</h5></div>
    <div class="card-body p-0">
        <iframe id="targetFrame" src="<?= sanitize_url($keyword->target_url) ?>" class="target-iframe" sandbox="allow-same-origin allow-scripts"></iframe>
    </div>
</div>

<script src="<?= asset('assets/js/seo-scroll.js') ?>"></script>
<script>
const seoEngine = new SEOScrollEngine({
    executionId: <?= e($execution->id) ?>,
    csrfToken: '<?= csrf_token() ?>',
    completeUrl: '<?= url('/seo-tasks/' . $execution->id . '/complete') ?>',
    targetUrl: <?= json_encode(sanitize_url($keyword->target_url)) ?>,
    scrollMin: <?= e($keyword->scroll_min_seconds) ?>,
    scrollMax: <?= e($keyword->scroll_max_seconds) ?>,
    pauseMin: <?= e($keyword->pause_min_seconds) ?>,
    pauseMax: <?= e($keyword->pause_max_seconds) ?>,
    totalBrowse: <?= e($keyword->total_browse_seconds) ?>,
});

window.addEventListener('unload', () => seoEngine.destroy());
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
<?php
// views/user/tasks/execute-video.php
$title = 'تماشای ویدیو';
$layout = 'user';
ob_start();

$deadlineTimestamp = strtotime($execution->deadline_at);
$remainingSeconds = max(0, $deadlineTimestamp - time());

// استخراج video ID از URL
$videoId = '';
if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $task->target_url, $matches)) {
    $videoId = $matches[1];
}

// مدت ویدیو (ثانیه) — تنظیم از ادمین یا پیش‌فرض 180 ثانیه
$videoDuration = (int) ($task->restrictions ? (json_decode($task->restrictions)->video_duration ?? 180) : 180);
?>

<div class="page-header">
    <h4><i class="material-icons">play_circle</i> تماشای ویدیو</h4>
    <a href="<?= url('/tasks') ?>" class="btn btn-outline-sm">
        <i class="material-icons">arrow_forward</i> بازگشت
    </a>
</div>

<!-- هشدار قوانین -->
<div class="alert-box alert-warning">
    <i class="material-icons">warning</i>
    <div>
        <strong>قوانین تماشای ویدیو:</strong>
        <ul class="rules-list">
            <li>ویدیو را تا انتها و بدون رد کردن (Skip) تماشا کنید</li>
            <li>سرعت پخش را تغییر ندهید (1x)</li>
            <li>از صفحه خارج نشوید و تب دیگری باز نکنید</li>
            <li>پنجره مرورگر را کوچک (Minimize) نکنید</li>
            <li>رفتار رباتیک باعث رد تسک و جریمه می‌شود</li>
        </ul>
    </div>
</div>

<!-- تایمر -->
<div class="timer-box" id="timerBox">
    <i class="material-icons">timer</i>
    <span id="countdown"><?= gmdate('i:s', $remainingSeconds) ?></span>
    <small>زمان باقیمانده تسک</small>
</div>

<!-- ویدیو -->
<div class="video-container">
    <?php if ($videoId): ?>
        <div class="video-wrapper">
            <iframe id="ytVideoFrame"
                    src="https://www.youtube.com/embed/<?= e($videoId) ?>?autoplay=1&modestbranding=1&rel=0&disablekb=1"
                    frameborder="0"
                    allow="autoplay; encrypted-media"
                    allowfullscreen>
            </iframe>
        </div>
    <?php else: ?>
        <div class="alert-box alert-danger">
            <i class="material-icons">error</i>
            <span>لینک ویدیو نامعتبر است.</span>
        </div>
    <?php endif; ?>
</div>

<!-- UI پیشرفت -->
<div id="videoTaskUI" class="mt-15"></div>

<!-- اطلاعات تسک -->
<div class="card mt-15">
    <div class="card-body">
        <div class="task-info-row">
            <span><i class="material-icons">info</i> <?= e($task->title) ?></span>
            <span class="reward-badge">
                <i class="material-icons">paid</i>
                <?= number_format($execution->reward_amount) ?>
                <?= $execution->reward_currency === 'usdt' ? 'تتر' : 'تومان' ?>
            </span>
        </div>
    </div>
</div>

<!-- فرم ارسال مدرک -->
<div class="card mt-15" id="submitSection" style="opacity:0.5;pointer-events:none;">
    <div class="card-header">
        <h5><i class="material-icons">camera_alt</i> ارسال مدرک</h5>
    </div>
    <div class="card-body">
        <div class="alert-box alert-info mb-15">
            <i class="material-icons">info</i>
            <span>پس از تکمیل تماشا، اسکرین‌شات از صفحه ویدیو بگیرید و ارسال کنید.</span>
        </div>

        <div class="form-group">
            <label>اسکرین‌شات <span class="required">*</span></label>
            <div class="upload-area">
                <input type="file" id="proofImageVideo" accept="image/*" required>
                <div class="upload-placeholder">
                    <i class="material-icons">cloud_upload</i>
                    <p>تصویر را انتخاب کنید</p>
                </div>
                <img id="previewImageVideo" class="preview-img" style="display:none">
            </div>
        </div>

        <button id="btnSubmitVideo" class="btn btn-primary btn-block" disabled>
            <i class="material-icons">send</i> ارسال مدرک
        </button>
    </div>
</div>

<script src="<?= asset('assets/js/youtube-task.js') ?>"></script>
<script>
// تایمر Deadline
let remaining = <?= e($remainingSeconds) ?>;
const countdownEl = document.getElementById('countdown');
const deadlineInterval = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
        clearInterval(deadlineInterval);
        countdownEl.textContent = '00:00';
        document.getElementById('timerBox').style.background = 'linear-gradient(135deg, #ef5350, #e53935)';
        if (typeof notyf !== 'undefined') notyf.error('زمان تسک به پایان رسید!');
        return;
    }
    const m = Math.floor(remaining / 60).toString().padStart(2, '0');
    const s = (remaining % 60).toString().padStart(2, '0');
    countdownEl.textContent = m + ':' + s;
}, 1000);

// YouTube Task Controller
const ytTask = new YouTubeTaskController({
    executionId: <?= e($execution->id) ?>,
    csrfToken: '<?= csrf_token() ?>',
    submitUrl: '<?= url('/tasks/' . $execution->id . '/submit') ?>',
    videoUrl: '<?= e($task->target_url) ?>',
    videoDuration: <?= e($videoDuration) ?>,
    minWatchPercent: 90
});

// فعال کردن بخش ارسال پس از تکمیل
const checkComplete = setInterval(() => {
    if (ytTask.completed) {
        document.getElementById('submitSection').style.opacity = '1';
        document.getElementById('submitSection').style.pointerEvents = 'auto';
        clearInterval(checkComplete);
    }
}, 1000);

// پیش‌نمایش تصویر
document.getElementById('proofImageVideo').addEventListener('change', function() {
    if (this.files && this.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.getElementById('previewImageVideo');
            img.src = e.target.result;
            img.style.display = 'block';
        };
        reader.readAsDataURL(this.files[0]);
    }
});

// ارسال
document.getElementById('btnSubmitVideo').addEventListener('click', function() {
    if (!document.getElementById('proofImageVideo').files[0]) {
        notyf.error('لطفاً اسکرین‌شات ارسال کنید.');
        return;
    }
    ytTask.submit();
});

// پاکسازی هنگام خروج
window.addEventListener('unload', () => ytTask.destroy());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
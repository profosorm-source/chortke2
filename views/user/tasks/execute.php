<?php
// views/user/tasks/execute.php
$title = 'انجام تسک';
$layout = 'user';
ob_start();

$deadlineTimestamp = strtotime($execution->deadline_at);
$remainingSeconds = max(0, $deadlineTimestamp - time());
?>

<div class="page-header">
    <h4><i class="material-icons">assignment_turned_in</i> انجام تسک</h4>
    <a href="<?= url('/tasks') ?>" class="btn btn-outline-sm">
        <i class="material-icons">arrow_forward</i> بازگشت
    </a>
</div>

<div class="execute-container">
    <!-- تایمر -->
    <div class="timer-box" id="timerBox">
        <i class="material-icons">timer</i>
        <span id="countdown"><?= gmdate('i:s', $remainingSeconds) ?></span>
        <small>زمان باقیمانده</small>
    </div>

    <!-- اطلاعات تسک -->
    <div class="card">
        <div class="card-header">
            <h5><?= e($task->title) ?></h5>
            <div class="task-badges">
                <span class="badge platform-<?= e($task->platform) ?>">
                    <?= e(social_platform_label($task->platform)) ?>
                </span>
                <span class="badge badge-info">
                    <?= e(ad_task_type_label($task->task_type)) ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <?php if ($task->description): ?>
                <p class="task-description"><?= nl2br(e($task->description)) ?></p>
            <?php endif; ?>

            <?php if ($task->sample_image): ?>
                <div class="sample-image">
                    <label>تصویر نمونه:</label>
                    <img src="<?= url('/file/view/ad-tasks/' . basename($task->sample_image)) ?>" alt="نمونه">
                </div>
            <?php endif; ?>

            <div class="target-link-box">
                <i class="material-icons">link</i>
                <a href="<?= sanitize_url($task->target_url) ?>" target="_blank" id="targetLink">
                    <?= e($task->target_url) ?>
                </a>
                <button class="btn btn-sm btn-outline-primary" id="openTargetBtn" data-url="<?= e($task->target_url) ?>"
                        onclick="var u=this.getAttribute('data-url');if(u&&u!=='#')window.open(u,'_blank')">
                    <i class="material-icons">open_in_new</i> باز کردن
                </button>
            </div>

            <div class="reward-info">
                <i class="material-icons">paid</i>
                پاداش: <strong><?= number_format($execution->reward_amount) ?></strong>
                <?= $execution->reward_currency === 'usdt' ? 'تتر' : 'تومان' ?>
            </div>
        </div>
    </div>

    <!-- فرم ارسال مدرک -->
    <div class="card mt-20">
        <div class="card-header">
            <h5><i class="material-icons">camera_alt</i> ارسال مدرک</h5>
        </div>
        <div class="card-body">
            <div class="alert-box alert-info">
                <i class="material-icons">info</i>
                <div>
                    <strong>راهنما:</strong>
                    <ul class="hint-list">
                        <li>کار را کامل انجام دهید (فالو/لایک/سابسکرایب/...)</li>
                        <li>اسکرین‌شات بگیرید که نشان دهد کار انجام شده</li>
                        <li>تصویر را آپلود و ارسال کنید</li>
                        <li>رفتار رباتیک (خیلی سریع) باعث رد تسک می‌شود</li>
                    </ul>
                </div>
            </div>

            <form id="submitProofForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label>اسکرین‌شات مدرک <span class="required">*</span></label>
                    <div class="upload-area" id="uploadArea">
                        <input type="file" name="proof_image" id="proofImage" accept="image/*" required>
                        <div class="upload-placeholder" id="uploadPlaceholder">
                            <i class="material-icons">cloud_upload</i>
                            <p>تصویر را اینجا بکشید یا کلیک کنید</p>
                            <small>فرمت: JPG, PNG — حداکثر ۳ مگابایت</small>
                        </div>
                        <img id="previewImage" class="preview-img" style="display:none">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="btnSubmit" disabled>
                    <i class="material-icons">send</i> ارسال مدرک
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// تایمر
let remaining = <?= e($remainingSeconds) ?>;
const countdownEl = document.getElementById('countdown');
const timerBox = document.getElementById('timerBox');

const timerInterval = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
        clearInterval(timerInterval);
        countdownEl.textContent = '۰۰:۰۰';
        timerBox.classList.add('expired');
        document.getElementById('btnSubmit').disabled = true;
        notyf.error('زمان تسک به پایان رسید!');
        return;
    }
    const m = Math.floor(remaining / 60).toString().padStart(2, '0');
    const s = (remaining % 60).toString().padStart(2, '0');
    countdownEl.textContent = m + ':' + s;
}, 1000);

// پیش‌نمایش تصویر
const proofInput = document.getElementById('proofImage');
const previewImg = document.getElementById('previewImage');
const placeholder = document.getElementById('uploadPlaceholder');
const btnSubmit = document.getElementById('btnSubmit');

proofInput.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
            placeholder.style.display = 'none';
            btnSubmit.disabled = false;
        };
        reader.readAsDataURL(this.files[0]);
    }
});

// ثبت زمان حضور در صفحه
const pageLoadTime = Date.now();

// ارسال فرم
document.getElementById('submitProofForm').addEventListener('submit', function(e) {
    e.preventDefault();

    if (remaining <= 0) {
        notyf.error('زمان تسک به پایان رسیده است.');
        return;
    }

    const formData = new FormData();
    formData.append('proof_image', proofInput.files[0]);
    formData.append('_csrf_token', '<?= csrf_token() ?>');
    formData.append('time_on_page', Math.floor((Date.now() - pageLoadTime) / 1000));

    btnSubmit.disabled = true;
    btnSubmit.innerHTML = '<i class="material-icons spin">sync</i> در حال ارسال...';

    fetch('<?= url('/tasks/' . $execution->id . '/submit') ?>', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            notyf.success(data.message);
            clearInterval(timerInterval);
            setTimeout(() => window.location.href = '<?= url('/tasks/history') ?>', 2000);
        } else {
            notyf.error(data.message);
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i class="material-icons">send</i> ارسال مدرک';
        }
    })
    .catch(() => {
        notyf.error('خطا در ارتباط');
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<i class="material-icons">send</i> ارسال مدرک';
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
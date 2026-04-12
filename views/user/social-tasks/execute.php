<?php
$layout = 'user';
ob_start();
$execution = $execution ?? null;
$task      = $task      ?? null;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0"><i class="material-icons align-middle me-1">task_alt</i> انجام تسک</h4>
        <p class="text-muted mb-0 small"><?= e($task->title ?? '') ?></p>
    </div>
    <a href="<?= url('/social-tasks') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_back</i> بازگشت
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card mb-3">
            <div class="card-body">
                <!-- اطلاعات تسک -->
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div class="d-flex gap-2 mb-1">
                            <span class="badge bg-info text-dark"><?= e($task->platform ?? '') ?></span>
                            <span class="badge bg-secondary"><?= e($task->task_type ?? '') ?></span>
                        </div>
                        <h5 class="mb-0"><?= e($task->title ?? '') ?></h5>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold text-success fs-5"><?= number_format($task->reward ?? 0) ?> تومان</div>
                        <small class="text-muted">پاداش</small>
                    </div>
                </div>

                <?php if (!empty($task->description)): ?>
                    <div class="alert alert-light border small mb-3" style="white-space:pre-wrap;"><?= nl2br(e($task->description)) ?></div>
                <?php endif; ?>

                <!-- تایمر -->
                <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-light rounded">
                    <span class="text-muted small">زمان سپری‌شده</span>
                    <span class="fw-bold fs-5" id="timer">00:00</span>
                </div>

                <!-- لینک هدف -->
                <?php if (!empty($task->target_url)): ?>
                    <div class="mb-3">
                        <a href="<?= e($task->target_url) ?>" target="_blank" id="btn-goto-task"
                           class="btn btn-primary w-100">
                            <i class="material-icons align-middle" style="font-size:16px;">open_in_new</i>
                            رفتن به صفحه هدف
                        </a>
                        <small class="text-muted d-block mt-1 text-center">
                            پس از انجام تسک، برگردید و مدرک ارسال کنید.
                        </small>
                    </div>
                <?php endif; ?>

                <hr>
                <h6 class="fw-bold mb-3">ارسال مدرک</h6>

                <div id="submit-area" style="display:none;">
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسکرین‌شات <span class="text-danger">*</span></label>
                        <input type="file" id="proof-file" class="form-control" accept="image/*">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">توضیح تکمیلی</label>
                        <textarea id="proof-text" class="form-control" rows="2" placeholder="اختیاری..."></textarea>
                    </div>
                    <button id="btn-submit" class="btn btn-success w-100">
                        <i class="material-icons align-middle" style="font-size:16px;">send</i>
                        ارسال مدرک و دریافت پاداش
                    </button>
                </div>

                <div id="goto-hint" class="text-center text-muted small">
                    ابتدا روی دکمه بالا کلیک کنید و تسک را انجام دهید.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const executionId = <?= (int)($execution->id ?? 0) ?>;
    const expectedTime = <?= (int)($task->expected_time ?? 60) ?>;
    const csrfToken = '<?= csrf_token() ?>';

    // ── تایمر ──
    let elapsed = 0;
    let activeTime = 0;
    let isActive = true;
    const timerEl = document.getElementById('timer');

    const tick = setInterval(() => {
        elapsed++;
        if (isActive) activeTime++;
        const m = String(Math.floor(elapsed / 60)).padStart(2, '0');
        const s = String(elapsed % 60).padStart(2, '0');
        timerEl.textContent = `${m}:${s}`;
        if (elapsed >= expectedTime) {
            document.getElementById('submit-area').style.display = 'block';
            document.getElementById('goto-hint').style.display = 'none';
        }
    }, 1000);

    // ── behavior signals ──
    let signals = {
        tap_count: 0, scroll_count: 0, scroll_pauses: 0,
        hesitation_count: 0, app_blur_count: 0, touch_pauses: 0,
        touch_timing_variance: 0, natural_delay_count: 0,
    };
    let lastActionTime = Date.now();
    let actionDelays = [];

    document.addEventListener('click', () => {
        signals.tap_count++;
        const delay = Date.now() - lastActionTime;
        actionDelays.push(delay);
        if (delay > 800) signals.hesitation_count++;
        if (delay > 300) signals.natural_delay_count++;
        lastActionTime = Date.now();
    });

    let lastScroll = Date.now();
    document.addEventListener('scroll', () => {
        signals.scroll_count++;
        if (Date.now() - lastScroll > 500) signals.scroll_pauses++;
        lastScroll = Date.now();
    });

    document.addEventListener('visibilitychange', () => {
        isActive = !document.hidden;
        if (document.hidden) signals.app_blur_count++;
    });

    // رفتن به هدف → نشان دادن submit پس از بازگشت
    const gotoBtn = document.getElementById('btn-goto-task');
    if (gotoBtn) {
        gotoBtn.addEventListener('click', () => {
            setTimeout(() => {
                document.getElementById('submit-area').style.display = 'block';
                document.getElementById('goto-hint').style.display = 'none';
            }, expectedTime * 1000);
        });
    }

    // ── ارسال نهایی ──
    document.getElementById('btn-submit')?.addEventListener('click', async function () {
        this.disabled = true;
        this.textContent = 'در حال ارسال...';

        clearInterval(tick);

        // محاسبه avg_action_delay
        const avgDelay = actionDelays.length
            ? Math.round(actionDelays.reduce((a, b) => a + b, 0) / actionDelays.length)
            : 0;

        const payload = {
            active_time: activeTime,
            interactions: ['click', signals.scroll_count > 0 ? 'scroll' : null].filter(Boolean),
            behavior_signals: {
                ...signals,
                active_time: activeTime,
                session_duration: elapsed,
                avg_action_delay_ms: avgDelay,
            },
        };

        const formData = new FormData();
        formData.append('_token', csrfToken);
        formData.append('data', JSON.stringify(payload));
        const fileInput = document.getElementById('proof-file');
        if (fileInput?.files[0]) formData.append('proof_file', fileInput.files[0]);
        const textArea = document.getElementById('proof-text');
        if (textArea?.value) formData.append('proof_text', textArea.value);

        try {
            const res = await fetch(`<?= url('/social-tasks') ?>/${executionId}/submit`, {
                method: 'POST',
                headers: {'X-CSRF-Token': csrfToken},
                body: formData,
            });
            const data = await res.json();
            if (data.success) {
                window.location.href = `<?= url('/social-tasks') ?>?submitted=1`;
            } else {
                alert(data.message || 'خطا در ارسال');
                this.disabled = false;
                this.textContent = 'ارسال مدرک و دریافت پاداش';
            }
        } catch (e) {
            alert('خطای اتصال');
            this.disabled = false;
            this.textContent = 'ارسال مدرک و دریافت پاداش';
        }
    });
})();
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.user');

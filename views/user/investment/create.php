<?php $title = 'سرمایه‌گذاری جدید'; $layout = 'user'; ob_start(); ?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-investment.css') ?>">


<div class="content-header">
    <h4><i class="material-icons">add_chart</i> سرمایه‌گذاری جدید</h4>
    <a href="<?= url('/investment') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons">arrow_back</i> بازگشت
    </a>
</div>

<?php if ($isDepositLocked): ?>
<div class="alert alert-danger">
    <i class="material-icons">lock</i>
    <span>به دلیل برداشت اخیر، تا ۷ روز امکان سرمایه‌گذاری وجود ندارد.</span>
</div>
<?php else: ?>

<!-- هشدار ریسک -->
<div class="card">
    <div class="card-header" style="background: linear-gradient(135deg, #fff3e0, #ffe0b2);">
        <h5 style="color: #e65100;"><i class="material-icons">warning</i> هشدار ریسک — حتماً مطالعه کنید</h5>
    </div>
    <div class="card-body">
        <div class="risk-warning-box">
            <?= \nl2br(e($riskWarning)) ?>
        </div>
    </div>
</div>

<!-- فرم -->
<div class="card mt-4">
    <div class="card-header">
        <h5>اطلاعات سرمایه‌گذاری</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info mb-3">
            <i class="material-icons">info</i>
            <span>حداقل: <strong><?= number_format($settings['min_amount']) ?></strong> تتر | حداکثر: <strong><?= number_format($settings['max_amount']) ?></strong> تتر | برداشت: هر <strong><?= e($settings['withdrawal_cooldown']) ?></strong> روز</span>
        </div>

        <form id="investForm">
            <div class="form-group">
                <label for="amount">مبلغ سرمایه‌گذاری (USDT) <span class="text-danger">*</span></label>
                <input type="number" name="amount" id="amount" class="form-control" dir="ltr"
                       min="<?= e($settings['min_amount']) ?>" max="<?= e($settings['max_amount']) ?>"
                       step="0.01" placeholder="مثال: 100" required>
            </div>

            <div class="form-check mt-3">
                <input type="checkbox" name="risk_accepted" id="risk_accepted" value="1" class="form-check-input" required>
                <label for="risk_accepted" class="form-check-label">
                    <strong style="color:#e65100;">هشدار ریسک را مطالعه کردم و با آگاهی کامل از احتمال ضرر، سرمایه‌گذاری می‌کنم.</strong>
                </label>
            </div>

            <div class="form-actions mt-4">
                <button type="submit" id="submitBtn" class="btn btn-primary" disabled>
                    <i class="material-icons">trending_up</i> ثبت سرمایه‌گذاری
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('investForm');
    const submitBtn = document.getElementById('submitBtn');
    const riskCheck = document.getElementById('risk_accepted');

    riskCheck.addEventListener('change', function() {
        submitBtn.disabled = !this.checked;
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="material-icons spin">refresh</i> در حال ثبت...';

        const formData = new FormData(form);
        const data = {};
        formData.forEach((v, k) => data[k] = v);

        fetch('<?= url('/investment/store') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '<?= csrf_token() ?>'
            },
            body: JSON.stringify(data)
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                notyf.success(res.message);
                setTimeout(() => window.location.href = '<?= url('/investment') ?>', 1500);
            } else {
                notyf.error(res.message);
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="material-icons">trending_up</i> ثبت سرمایه‌گذاری';
            }
        })
        .catch(() => {
            notyf.error('خطا در ارتباط.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="material-icons">trending_up</i> ثبت سرمایه‌گذاری';
        });
    });
});
</script>

<?php endif; ?>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
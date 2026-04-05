<?php $title = 'ارسال محتوای جدید'; $layout = 'user'; ob_start(); ?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-content.css') ?>">


<div class="content-header">
    <h4><i class="material-icons">cloud_upload</i> ارسال محتوای جدید</h4>
    <a href="<?= url('/content') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons">arrow_back</i> بازگشت
    </a>
</div>

<!-- راهنما -->
<div class="alert alert-info">
    <i class="material-icons">info</i>
    <div>
        <strong>نحوه کار:</strong>
        <ol style="margin: 10px 0 0; padding-right: 20px;">
            <li>ویدیوی خود را در آپارات آپلود کنید</li>
            <li>لینک ویدیو را در فرم زیر وارد کنید</li>
            <li>پس از تأیید مدیریت، ویدیو در کانال‌های مجموعه منتشر خواهد شد</li>
            <li>درآمد شما از <strong>ماه سوم</strong> به بعد محاسبه و پرداخت می‌شود</li>
            <li>هرچه فعال‌تر باشید، سهم درآمد شما بیشتر خواهد بود</li>
        </ol>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5>فرم ارسال محتوا</h5>
    </div>
    <div class="card-body">
        <form id="contentForm" method="POST">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="platform">پلتفرم <span class="text-danger">*</span></label>
                    <select name="platform" id="platform" class="form-control" required>
                        <option value="">انتخاب کنید...</option>
                        <option value="aparat">آپارات</option>
                        <option value="youtube">یوتیوب</option>
                    </select>
                    <small class="form-text text-muted">ویدیو باید قبلاً در پلتفرم مورد نظر آپلود شده باشد.</small>
                </div>
                <div class="form-group col-md-6">
                    <label for="category">دسته‌بندی</label>
                    <select name="category" id="category" class="form-control">
                        <option value="">انتخاب کنید...</option>
                        <option value="comedy">طنز و کمدی</option>
                        <option value="education">آموزشی</option>
                        <option value="tech">تکنولوژی</option>
                        <option value="cooking">آشپزی</option>
                        <option value="music">موسیقی</option>
                        <option value="vlog">ولاگ</option>
                        <option value="gaming">بازی</option>
                        <option value="art">هنر و خلاقیت</option>
                        <option value="sport">ورزشی</option>
                        <option value="other">سایر</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="video_url">لینک ویدیو <span class="text-danger">*</span></label>
                <input type="url" name="video_url" id="video_url" class="form-control" dir="ltr"
                       placeholder="https://www.aparat.com/v/..." required>
                <small class="form-text text-muted" id="urlHint">لینک کامل ویدیو را از مرورگر کپی کنید.</small>
            </div>

            <div class="form-group">
                <label for="title">عنوان ویدیو <span class="text-danger">*</span></label>
                <input type="text" name="title" id="title" class="form-control" maxlength="255"
                       placeholder="عنوان ویدیوی خود را وارد کنید" required>
            </div>

            <div class="form-group">
                <label for="description">توضیحات</label>
                <textarea name="description" id="description" class="form-control" rows="4" maxlength="2000"
                          placeholder="توضیح مختصری درباره ویدیو بنویسید..."></textarea>
                <small class="form-text text-muted"><span id="descCount">0</span>/2000</small>
            </div>

            <!-- تعهدنامه -->
            <div class="agreement-box">
                <h6><i class="material-icons">gavel</i> تعهدنامه همکاری محتوایی</h6>
                <div class="agreement-text">
                    <?= \nl2br(e($agreementText)) ?>
                </div>
                <div class="form-check mt-3">
                    <input type="checkbox" name="agreement_accepted" id="agreement_accepted" value="1" class="form-check-input" required>
                    <label for="agreement_accepted" class="form-check-label">
                        <strong>تمامی شرایط فوق را مطالعه کردم و می‌پذیرم.</strong>
                    </label>
                </div>
            </div>

            <div class="form-actions mt-4">
                <button type="submit" id="submitBtn" class="btn btn-primary" disabled>
                    <i class="material-icons">send</i> ارسال محتوا
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('contentForm');
    const submitBtn = document.getElementById('submitBtn');
    const agreementCheck = document.getElementById('agreement_accepted');
    const descField = document.getElementById('description');
    const descCount = document.getElementById('descCount');
    const platformSelect = document.getElementById('platform');
    const urlHint = document.getElementById('urlHint');

    // فعال‌سازی دکمه با تیک تعهدنامه
    agreementCheck.addEventListener('change', function() {
        submitBtn.disabled = !this.checked;
    });

    // شمارش کاراکتر توضیحات
    descField.addEventListener('input', function() {
        descCount.textContent = this.value.length;
    });

    // تغییر راهنما بر اساس پلتفرم
    platformSelect.addEventListener('change', function() {
        const hints = {
            'aparat': 'مثال: https://www.aparat.com/v/abcdef',
            'youtube': 'مثال: https://www.youtube.com/watch?v=abcdef'
        };
        urlHint.textContent = hints[this.value] || 'لینک کامل ویدیو را وارد کنید.';
    });

    // ارسال فرم AJAX
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="material-icons spin">refresh</i> در حال ارسال...';

        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => data[key] = value);

        fetch('<?= url('/content/store') ?>', {
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
                if (window.notyf) notyf.success(res.message);
                setTimeout(() => window.location.href = '<?= url('/content') ?>', 1500);
            } else {
                if (window.notyf) notyf.error(res.message || 'خطایی رخ داد.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="material-icons">send</i> ارسال محتوا';
            }
        })
        .catch(() => {
            if (window.notyf) notyf.error('خطا در ارتباط با سرور.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="material-icons">send</i> ارسال محتوا';
        });
    });
});
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
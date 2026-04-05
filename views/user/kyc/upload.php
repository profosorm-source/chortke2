<?php
$title = 'آپلود مدارک احراز هویت';
$layout = 'user';
ob_start();
?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <?php if (!($canSubmit ?? true)): ?>
            <!-- نمایش خطا -->
            <div class="alert alert-danger">
                <i class="material-icons" style="font-size: 18px; vertical-align: middle;">error</i>
                <?= e($error ?? 'شما مجاز به ثبت درخواست نیستید') ?>
            </div>
            <a href="<?= url('/kyc') ?>" class="btn btn-secondary">بازگشت</a>

        <?php else: ?>
            <!-- فرم آپلود -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">آپلود مدارک احراز هویت</h5>
                </div>
                <div class="card-body">
                    <!-- هشدار -->
                    <div class="alert alert-warning mb-4">
                        <i class="material-icons" style="font-size: 18px; vertical-align: middle;">warning</i>
                        <strong>توجه:</strong> اطلاعات وارد شده باید دقیقاً مطابق با مدارک هویتی شما باشد.
                        ارسال مدارک جعلی منجر به مسدود شدن حساب کاربری خواهد شد.
                    </div>

                    <!-- راهنمای تصویری -->
                    <div class="card mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <div class="card-body text-white">
                            <h6 class="mb-3">
                                <i class="material-icons" style="font-size: 18px; vertical-align: middle;">info</i>
                                راهنمای تصویر احراز هویت
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-4 text-center">
                                    <div class="bg-white bg-opacity-10 rounded p-3">
                                        <i class="material-icons" style="font-size: 40px;">credit_card</i>
                                        <p class="small mb-0 mt-2">کارت ملی خود را در دست بگیرید</p>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center">
                                    <div class="bg-white bg-opacity-10 rounded p-3">
                                        <i class="material-icons" style="font-size: 40px;">note</i>
                                        <p class="small mb-0 mt-2">برگه دست‌نوشته تهیه کنید</p>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center">
                                    <div class="bg-white bg-opacity-10 rounded p-3">
                                        <i class="material-icons" style="font-size: 40px;">photo_camera</i>
                                        <p class="small mb-0 mt-2">سلفی با کارت و برگه بگیرید</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
<!-- نمونه تصویر -->
            <div class="card mt-4">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="material-icons" style="font-size: 18px; vertical-align: middle;">image</i>
                        نمونه تصویر صحیح
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <img src="<?= asset('images/kyc-sample-correct.jpg') ?>" 
                                 class="img-fluid rounded border border-success border-2" 
                                 alt="نمونه صحیح">
                            <p class="text-center text-success mt-2">
                                <i class="material-icons" style="font-size: 18px; vertical-align: middle;">check_circle</i>
                                صحیح
                            </p>
                        </div>
                        <div class="col-md-6">
                            <img src="<?= asset('images/kyc-sample-wrong.jpg') ?>" 
                                 class="img-fluid rounded border border-danger border-2" 
                                 alt="نمونه غلط">
                            <p class="text-center text-danger mt-2">
                                <i class="material-icons" style="font-size: 18px; vertical-align: middle;">cancel</i>
                                غلط (کارت یا برگه واضح نیست)
                            </p>
                        </div>
                    </div>
                </div>
            </div>
                    <!-- Form-based: POST + Redirect -->
                    <form method="POST" action="<?= url('/kyc/submit') ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>

                        <div class="row g-3">
                            <!-- کد ملی -->
                            <div class="col-md-6">
                                <label class="form-label">کد ملی <span class="text-danger">*</span></label>
                                <input type="text" name="national_code" class="form-control" 
                                       maxlength="10"  required
  inputmode="numeric"
  pattern="[0-9]{10}"
  placeholder="1234567890">
                                <?php
                                $session = \Core\Session::getInstance();
                                $errors = $session->getFlash('errors');
                                if (isset($errors['national_code'])):
                                ?>
                                    <div class="text-danger small mt-1"><?= e($errors['national_code'][0]) ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- تاریخ تولد -->
                            <div class="col-md-6">
                                <label class="form-label">تاریخ تولد <span class="text-danger">*</span></label>
                                <input type="date" name="birth_date" class="form-control" 
                                       required max="<?= \date('Y-m-d', \strtotime('-18 years')) ?>">
                                <?php if (isset($errors['birth_date'])): ?>
                                    <div class="text-danger small mt-1"><?= e($errors['birth_date'][0]) ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- تصویر احراز هویت (یک عکس) -->
                            <div class="col-12">
                                <label class="form-label">
                                    تصویر احراز هویت 
                                    <span class="text-danger">*</span>
                                </label>
                                
                                <div class="alert alert-info mb-3">
                                    <h6 class="mb-2">
                                        <i class="material-icons" style="font-size: 18px; vertical-align: middle;">checklist</i>
                                        مراحل تهیه تصویر:
                                    </h6>
                                    <ol class="mb-0 small ps-3">
                                        <li>کارت ملی خود را در دست بگیرید (روی کارت باید واضح باشد)</li>
                                        <li>در یک برگه سفید بنویسید: 
                                            <strong>"<?= config('app.name') ?> - <?= to_jalali(\date('Y/m/d')) ?>"</strong>
                                        </li>
                                        <li>سلفی بگیرید که هم صورت شما، هم کارت ملی و هم برگه دست‌نوشته در تصویر باشند</li>
                                        <li>تصویر باید واضح، نورپردازی مناسب و بدون فیلتر باشد</li>
                                    </ol>
                                </div>

                                <input type="file" 
                                       name="verification_image" 
                                       class="form-control" 
                                       accept="image/jpeg,image/jpg,image/png" 
                                       required
                                       id="verificationImage">
                                <small class="text-muted">حداکثر 5MB - فرمت JPG یا PNG</small>
                                
                                <?php if (isset($errors['verification_image'])): ?>
                                    <div class="text-danger small mt-1"><?= e($errors['verification_image'][0]) ?></div>
                                <?php endif; ?>

                                <!-- پیش‌نمایش -->
                                <div id="imagePreview" class="mt-3"></div>
                            </div>

                            <!-- تأییدیه -->
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="confirm" required>
                                    <label class="form-check-label" for="confirm">
                                        تأیید می‌کنم که تمام اطلاعات وارد شده صحیح و مطابق با مدارک هویتی من است
                                        و تصویر ارسالی توسط خودم و در زمان حال گرفته شده است.
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="material-icons" style="font-size: 18px; vertical-align: middle;">send</i>
                                ارسال درخواست
                            </button>
                            <a href="<?= url('/kyc') ?>" class="btn btn-secondary">انصراف</a>
                        </div>
                    </form>
                </div>
            </div>

            
        <?php endif; ?>
    </div>
</div>

<script>
// پیش‌نمایش تصویر
document.getElementById('verificationImage').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const previewDiv = document.getElementById('imagePreview');

    if (file) {
        // بررسی حجم
        if (file.size > 5 * 1024 * 1024) {
            alert('حجم فایل نباید بیشتر از 5MB باشد');
            this.value = '';
            previewDiv.innerHTML = '';
            return;
        }

        // بررسی فرمت
        if (!['image/jpeg', 'image/jpg', 'image/png'].includes(file.type)) {
            alert('فقط فرمت‌های JPG و PNG مجاز است');
            this.value = '';
            previewDiv.innerHTML = '';
            return;
        }

        // پیش‌نمایش
        const reader = new FileReader();
        reader.onload = function(e) {
            previewDiv.innerHTML = `
                <div class="card">
                    <div class="card-body">
                        <h6 class="mb-3">پیش‌نمایش تصویر:</h6>
                        <img src="${e.target.result}" class="img-fluid rounded border" style="max-height: 400px;">
                        <div class="alert alert-warning mt-3 mb-0">
                            <small>
                                <strong>بررسی کنید:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>صورت شما واضح است؟</li>
                                    <li>کارت ملی قابل خواندن است؟</li>
                                    <li>نوشته روی برگه خوانا است?</li>
                                    <li>تصویر نوری مناسب دارد؟</li>
                                </ul>
                            </small>
                        </div>
                    </div>
                </div>
            `;
        };
        reader.readAsDataURL(file);
    } else {
        previewDiv.innerHTML = '';
    }
});

// ماسک کد ملی (اعداد فارسی)
document.querySelector('input[name="national_code"]').addEventListener('input', function (e) {
    // فقط عدد انگلیسی
    e.target.value = e.target.value.replace(/[^0-9]/g, '');
});
    // فقط اعداد
    value = value.replace(/[^0-9۰-۹]/g, '');
    
    // نمایش به صورت فارسی
    let display = '';
    for (let char of value) {
        if (char >= '0' && char <= '9') {
            display += persianDigits[parseInt(char)];
        } else {
            display += char;
        }
    }
    
    e.target.value = display;
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
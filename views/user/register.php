<?php
use Core\Session;

$session = Session::getInstance();

$errors = $session->getFlash('errors') ?? [];
$old    = $session->getFlash('old') ?? [];

$flashError   = $flashError;
$flashSuccess = $flashSuccess;
$flashWarning = $flashWarning;

$refVal = old('referral_code');
if ($refVal === null || $refVal === '') {
    $refVal = $referralCode ?? '';
}
ob_start();
?>

<div class="auth-card">
    <div class="auth-header">
        <?php $__authLogo = site_logo('main'); ?>
        <?php if ($__authLogo): ?>
            <a href="<?= url('/') ?>">
                <img src="<?= e($__authLogo) ?>" alt="<?= e(setting('site_name','چرتکه')) ?>" style="max-height:60px;max-width:180px;object-fit:contain;margin-bottom:8px">
            </a>
        <?php else: ?>
            <h3>🎯 <?= e(setting('site_name','چرتکه')) ?></h3>
        <?php endif; ?>
        <p>ایجاد حساب کاربری جدید</p>
    </div>
    
    <div class="auth-body">
	<?php if ($flashError): ?>
  <div class="flash-box flash-danger">
    <div class="flash-title"><i class="material-icons">error</i> خطا</div>
    <div class="flash-msg"><?= e($flashError) ?></div>
  </div>
<?php endif; ?>
        <form method="POST" action="<?= url('/register') ?>">
            <?= csrf_field() ?>
            
            <div class="form-group">
                <label class="form-label">نام و نام خانوادگی *</label>
                <input type="text" name="full_name" class="form-control" value="<?= e($old['full_name'] ?? '') ?>" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">ایمیل *</label>
                <input type="email" name="email" class="form-control" value="<?= e($old['email'] ?? '') ?>" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">موبایل *</label>
                <input type="text" name="mobile" class="form-control" value="<?= e($old['mobile'] ?? '') ?>" placeholder="09123456789" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">رمز عبور *</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">تکرار رمز عبور *</label>
                <input type="password" name="password_confirmation" class="form-control" required>
            </div>
            <div class="mb-3">
  <label class="form-label">کد معرف (اختیاری)</label>
  <input
  type="text"
  name="referral_code"
  class="form-control"
  value="<?= e($refVal) ?>"
  placeholder="کد معرف (اختیاری)"
  maxlength="32"
  autocomplete="off"
/>
  <small class="text-muted">اگر با لینک دعوت وارد شده باشید این بخش به صورت خودکار پر می‌شود.</small>
</div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="terms" id="terms" required>
                <label class="form-check-label" for="terms">
                    <a href="<?= url('/terms') ?>" target="_blank" style="color: var(--primary);">قوانین و مقررات</a> را می‌پذیرم
                </label>
            </div>
            
<?php if (!empty($captchaType)): ?>
            <div style="margin-bottom:16px;">
                <?= captcha_field($captchaType) ?>
                <?php if ($captchaType === 'recaptcha_v2'): ?>
                    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                <?php endif; ?>
            </div>
<?php endif; ?>
            <button type="submit" class="btn btn-primary">
                ثبت‌نام
            </button>
        </form>
    </div>
    
    <div class="auth-footer">
        حساب کاربری دارید؟ <a href="<?= url('/login') ?>">وارد شوید</a>
    </div>
</div>

<div id="toast-container"></div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/auth.php';
?>
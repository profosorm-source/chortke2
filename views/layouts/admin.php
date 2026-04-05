<?php
// ─── Admin Panel Layout ─────────────────────────────────────
// navbar و sidebar از فایل‌های جداگانه در partials/admin/ بارگذاری می‌شوند

use Core\Session;

$session     = Session::getInstance();
$currentUser = $user ?? null;
$flashSuccess = $session->getFlash('success');
$flashError   = $session->getFlash('error');

$fullName    = 'مدیر';
$firstLetter = 'م';
if ($currentUser && isset($currentUser->full_name) && !empty($currentUser->full_name)) {
    $fullName    = $currentUser->full_name;
    $firstLetter = mb_substr($fullName, 0, 1, 'UTF-8');
}
$roleNames = [
    'admin'   => 'مدیر کل',
    'support' => 'پشتیبان',
    'user'    => 'کاربر',
];
$userRole = isset($currentUser->role) ? ($roleNames[$currentUser->role] ?? 'کاربر') : 'مدیر';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'پنل مدیریت') ?> | <?= e(setting('site_name', 'چرتکه')) ?></title>
    <meta name="csrf-token" content="<?= csrf_token() ?>">

    <!-- Favicon (از تنظیمات سیستم) -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" type="image/png" href="<?= asset('images/favicon.png') ?>">
    <?php endif; ?>

    <!-- Material Icons (local) -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/material-icons.css') ?>">
    <!-- Vazirmatn Font (local) -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/chortke.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/sweetalert2/sweetalert2.min.css') ?>">

    <?= $styles ?? '' ?>
</head>
<body>

<?php require __DIR__ . '/../partials/admin/sidebar.php'; ?>

<!-- Main Content -->
<div class="main-content">

    <?php require __DIR__ . '/../partials/admin/navbar.php'; ?>

    <!-- Content -->
    <div class="content-wrapper">
        <div id="toast-container"></div>

        <?= $content ?? '' ?>
    </div>
</div>

<script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/sweetalert2/sweetalert2.all.min.js') ?>"></script>
<script src="<?= asset('assets/js/swal-init.js') ?>?v=<?= time() ?>"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>

<script>
window.csrfToken = "<?= csrf_token() ?>";

const notyf = new Notyf({
    duration: 5000,
    position: { x: 'left', y: 'top' },
    dismissible: true
});

<?php if ($flashSuccess): ?>
    notyf.success(<?= json_encode((string)$flashSuccess, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
<?php endif; ?>
<?php if ($flashError): ?>
    notyf.error(<?= json_encode((string)$flashError, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
<?php endif; ?>

// Sidebar Collapse Toggle (Admin)
document.addEventListener('DOMContentLoaded', function () {
    const menuSections = document.querySelectorAll('.menu-section');
    menuSections.forEach(section => {
        const title    = section.querySelector('.section-title');
        const hasActive = section.querySelector('.submenu li.active');
        if (hasActive) section.classList.add('open');
        title && title.addEventListener('click', () => section.classList.toggle('open'));
    });
});
</script>

<?= $scripts ?? '' ?>
<?= captcha_refresh_script() ?>
</body>
</html>
<?php
$pageTitle = 'اعلان‌ها';
include VIEW_PATH . '/layouts/user.php';
?>
<?php
$title = $title ?? 'اعلان‌ها';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-notifications.css') ?>">


<div class="notifications-page">
    <!-- هدر -->
    <div class="page-header mb-4">
        <div>
            <h4><i class="fas fa-bell"></i> اعلان‌ها</h4>
            <p class="text-muted mb-0">
                <span class="badge bg-danger"><?= e($unread_count) ?></span>
                اعلان خوانده نشده
            </p>
        </div>
        <div>
            <button class="btn btn-outline-primary" id="markAllReadBtn">
                <i class="fas fa-check-double"></i> علامت همه به عنوان خوانده شده
            </button>
            <a href="<?= url('/notifications/preferences') ?>" class="btn btn-outline-secondary">
                <i class="fas fa-cog"></i> تنظیمات
            </a>
        </div>
    </div>
    
    <!-- لیست اعلان‌ها -->
    <div class="notifications-list">
        <?php if (!empty($notifications)): ?>
            <?php foreach ($notifications as $notif): ?>
            <div class="notification-item <?= $notif->is_read ? 'read' : 'unread' ?> priority-<?= e($notif->priority) ?>" 
                 data-id="<?= e($notif->id) ?>">
                <div class="notif-icon <?= e($notif->type) ?>">
                    <?php
                    $icons = [
                        'system' => 'fa-info-circle',
                        'task' => 'fa-tasks',
                        'payment' => 'fa-credit-card',
                        'withdrawal' => 'fa-hand-holding-usd',
                        'deposit' => 'fa-arrow-down',
                        'investment' => 'fa-chart-line',
                        'lottery' => 'fa-gift',
                        'referral' => 'fa-users',
                        'kyc' => 'fa-id-card',
                        'security' => 'fa-shield-alt'
                    ];
                    $icon = $icons[$notif->type] ?? 'fa-bell';
                    ?>
                    <i class="fas <?= e($icon) ?>"></i>
                </div>
                
                <div class="notif-content">
                    <h6><?= e($notif->title) ?></h6>
                    <p><?= e($notif->message) ?></p>
                    
                    <?php if ($notif->action_url && $notif->action_text): ?>
                    <a href="<?= e($notif->action_url) ?>" class="notif-action">
                        <?= e($notif->action_text) ?> <i class="fas fa-arrow-left"></i>
                    </a>
                    <?php endif; ?>
                    
                    <div class="notif-meta">
                        <small><i class="far fa-clock"></i> <?= to_jalali($notif->created_at) ?></small>
                        <?php if ($notif->priority === 'urgent'): ?>
                            <span class="badge bg-danger">فوری</span>
                        <?php elseif ($notif->priority === 'high'): ?>
                            <span class="badge bg-warning">مهم</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="notif-actions">
                    <?php if (!$notif->is_read): ?>
                    <button class="btn btn-sm btn-outline-primary mark-read" 
                            data-id="<?= e($notif->id) ?>"
                            title="علامت به عنوان خوانده شده">
                        <i class="fas fa-check"></i>
                    </button>
                    <?php endif; ?>
                    
                    <button class="btn btn-sm btn-outline-secondary archive-notif" 
                            data-id="<?= e($notif->id) ?>"
                            title="آرشیو">
                        <i class="fas fa-archive"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-bell-slash fa-4x text-muted mb-3"></i>
                <p class="text-muted">اعلانی وجود ندارد</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const notyf = new Notyf({ duration: 2500, position: { x: 'right', y: 'top' } });

    function parseJsonBody() {
        // helper برای ارسال JSON با رعایت قانون پروژه
        return {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '<?= csrf_token() ?>'
            }
        };
    }

    // Mark as read
    document.querySelectorAll('.mark-read').forEach(btn => {
        btn.addEventListener('click', async function () {
            const id = this.dataset.id;

            try {
                const res = await fetch('<?= url('/notifications/mark-read') ?>', {
                    method: 'POST',
                    ...parseJsonBody(),
                    body: JSON.stringify({ notification_id: id })
                });

                const data = await res.json();

                if (data.success) {
                    notyf.success(data.message);
                    const item = document.querySelector('.notification-item[data-id="' + id + '"]');
                    if (item) item.classList.remove('unread');
                    this.remove();

                    // refresh unread badge
                    refreshUnreadCount();
                } else {
                    notyf.error(data.message || 'خطا');
                }
            } catch (e) {
                notyf.error('خطا در ارتباط با سرور');
            }
        });
    });

    // Archive
    document.querySelectorAll('.archive-notif').forEach(btn => {
        btn.addEventListener('click', async function () {
            const id = this.dataset.id;

            confirmAction({
                type: 'confirm',
                title: 'آرشیو اعلان',
                text: 'آیا این اعلان آرشیو شود؟',
                confirmButtonText: 'بله، آرشیو شود',
                onConfirm: async () => {
                    try {
                        const res = await fetch('<?= url('/notifications/archive') ?>', {
                            method: 'POST',
                            ...parseJsonBody(),
                            body: JSON.stringify({ notification_id: id })
                        });

                        const data = await res.json();

                        if (data.success) {
                            notyf.success(data.message);
                            const item = document.querySelector('.notification-item[data-id="' + id + '"]');
                            if (item) item.remove();
                            refreshUnreadCount();
                        } else {
                            notyf.error(data.message || 'خطا');
                        }
                    } catch (e) {
                        notyf.error('خطا در ارتباط با سرور');
                    }
                }
            });
        });
    });

    // Mark all read
    document.getElementById('markAllReadBtn')?.addEventListener('click', function () {
        confirmAction({
            type: 'confirm',
            title: 'خوانده شدن همه اعلان‌ها',
            text: 'همه اعلان‌ها به عنوان خوانده شده علامت‌گذاری شوند؟',
            confirmButtonText: 'بله، انجام شود',
            onConfirm: async () => {
                try {
                    const res = await fetch('<?= url('/notifications/mark-all-read') ?>', {
                        method: 'POST',
                        ...parseJsonBody(),
                        body: JSON.stringify({})
                    });

                    const data = await res.json();
                    if (data.success) {
                        notyf.success(data.message);
                        setTimeout(() => location.reload(), 800);
                    } else {
                        notyf.error('خطا');
                    }
                } catch (e) {
                    notyf.error('خطا در ارتباط با سرور');
                }
            }
        });
    });

    async function refreshUnreadCount() {
        try {
            const res = await fetch('<?= url('/notifications/unread-count') ?>', { method: 'GET' });
            const data = await res.json();
            if (data.success) {
                document.getElementById('unreadCountBadge').textContent = data.count;
            }
        } catch (e) {}
    }
});
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../layouts/user.php';
?>
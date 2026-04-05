<?php
$pageTitle = 'تیکت #' . $ticket->id;
ob_start();

use App\Enums\TicketStatus;
use App\Enums\TicketPriority;
?>

<div class="container-fluid">
    <div class="row">
        <!-- جزئیات تیکت -->
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">جزئیات تیکت</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <th>شماره:</th>
                            <td><strong>#<?= e($ticket->id) ?></strong></td>
                        </tr>
                        <tr>
                            <th>دسته:</th>
                            <td>
                                <i class="material-icons" style="font-size: 16px; vertical-align: middle;">
                                    <?= e($ticket->category_icon) ?>
                                </i>
                                <?= e($ticket->category_name) ?>
                            </td>
                        </tr>
                        <tr>
                            <th>اولویت:</th>
                            <td>
                                <span class="badge <?= e(TicketPriority::badgeClass($ticket->priority)) ?>">
                                    <?= e(TicketPriority::label($ticket->priority)) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>وضعیت:</th>
                            <td>
                                <span class="badge <?= e(TicketStatus::badgeClass($ticket->status)) ?>">
                                    <?= e(TicketStatus::label($ticket->status)) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>تاریخ ایجاد:</th>
                            <td><?= to_jalali($ticket->created_at) ?></td>
                        </tr>
                        <tr>
                            <th>آخرین بروزرسانی:</th>
                            <td><?= to_jalali($ticket->updated_at) ?></td>
                        </tr>
                        <?php if ($ticket->closed_at): ?>
                        <tr>
                            <th>تاریخ بسته شدن:</th>
                            <td><?= to_jalali($ticket->closed_at) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <?php if ($ticket->status !== 'closed'): ?>
                    <button class="btn btn-danger btn-block" onclick="closeTicket(<?= e($ticket->id) ?>)">
                        <i class="material-icons">close</i>
                        بستن تیکت
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- پیام‌ها -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><?= e($ticket->subject) ?></h5>
                </div>
                <div class="card-body">
                    <!-- لیست پیام‌ها -->
                    <div class="messages-container" id="messagesContainer">
                        <?php foreach ($messages as $message): ?>
                        <div class="message-item <?= $message->is_admin ? 'admin-message' : 'user-message' ?>">
                            <div class="message-header">
                                <div class="d-flex align-items-center">
                                    <div class="avatar">
                                        <?= $message->is_admin ? '👨‍💼' : '👤' ?>
                                    </div>
                                    <div class="ml-2">
                                        <strong><?= $message->is_admin ? 'پشتیبانی' : e($message->full_name) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= to_jalali($message->created_at) ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="message-body">
                                <?= nl2br(e($message->message)) ?>
                            </div>
                            
                            <!-- فایل‌های پیوست -->
                            <?php if ($message->attachments): ?>
                                <?php $attachments = json_decode($message->attachments, true); ?>
                                <?php if (!empty($attachments)): ?>
                                <div class="attachments mt-2">
                                    <?php foreach ($attachments as $file): ?>
                                        <a href="<?= url($file['path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="material-icons">attach_file</i>
                                            <?= e($file['name']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- فرم پاسخ -->
                    <?php if ($ticket->status !== 'closed'): ?>
                    <hr>
                    <form id="replyForm">
                        <input type="hidden" name="ticket_id" value="<?= e($ticket->id) ?>">
                        
                        <div class="form-group">
                            <label>پاسخ شما:</label>
                            <textarea name="message" class="form-control" rows="4" required></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="material-icons">send</i>
                            ارسال پاسخ
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-secondary text-center">
                        این تیکت بسته شده است.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ارسال پاسخ با AJAX
document.getElementById('replyForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {
        ticket_id: formData.get('ticket_id'),
        message: formData.get('message')
    };
    
    fetch('<?= url('/tickets/reply') ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '<?= csrf_token() ?>'
        },
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            notyf.success(data.message);
            setTimeout(() => location.reload(), 1000);
        } else {
            notyf.error(data.message);
        }
    });
});

// بستن تیکت
function closeTicket(id) {
    Swal.fire({
        title: 'بستن تیکت',
        text: 'آیا مطمئن هستید که می‌خواهید این تیکت را ببندید؟',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'بله، ببند',
        cancelButtonText: 'انصراف'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= url('/tickets/close') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify({ id: id })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('موفق', data.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('خطا', data.message, 'error');
                }
            });
        }
    });
}

// اسکرول به آخرین پیام
window.addEventListener('load', function() {
    const container = document.getElementById('messagesContainer');
    container.scrollTop = container.scrollHeight;
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layouts/user.php';
?>
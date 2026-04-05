<?php
$pageTitle = 'تیکت‌های پشتیبانی';
ob_start();

use App\Enums\TicketStatus;
use App\Enums\TicketPriority;
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">تیکت‌های پشتیبانی</h4>
        <a href="<?= url('/tickets/create') ?>" class="btn btn-primary">
            <i class="material-icons">add</i>
            ایجاد تیکت جدید
        </a>
    </div>

    <?php if ($unreadCount > 0): ?>
    <div class="alert alert-info">
        <i class="material-icons">notifications</i>
        شما <?= to_jalali($unreadCount, '', true) ?> پاسخ خوانده نشده دارید.
    </div>
    <?php endif; ?>

    <!-- فیلتر -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="<?= url('/tickets') ?>" class="row align-items-end">
                <div class="col-md-3">
                    <label>وضعیت</label>
                    <select name="status" class="form-control">
                        <option value="">همه</option>
                        <?php foreach (TicketStatus::all() as $s): ?>
                            <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                                <?= TicketStatus::label($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-block">فیلتر</button>
                </div>
            </form>
        </div>
    </div>

    <!-- لیست -->
    <?php if (empty($tickets)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="material-icons" style="font-size: 64px; color: #ccc;">support_agent</i>
                <p class="text-muted mt-3">شما هنوز تیکتی ثبت نکرده‌اید.</p>
                <a href="<?= url('/tickets/create') ?>" class="btn btn-primary mt-3">
                    ایجاد اولین تیکت
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>موضوع</th>
                                <th>دسته</th>
                                <th>اولویت</th>
                                <th>وضعیت</th>
                                <th>آخرین بروزرسانی</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td><?= e($ticket->id) ?></td>
                                <td>
                                    <a href="<?= url('/tickets/show/' . $ticket->id) ?>">
                                        <?= e($ticket->subject) ?>
                                    </a>
                                    <?php if ($ticket->last_reply_by === 'admin'): ?>
                                        <span class="badge badge-danger badge-sm">جدید</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <i class="material-icons" style="font-size: 16px; vertical-align: middle;">
                                        <?= e($ticket->category_icon) ?>
                                    </i>
                                    <?= e($ticket->category_name) ?>
                                </td>
                                <td>
                                    <span class="badge <?= e(TicketPriority::badgeClass($ticket->priority)) ?>">
                                        <?= e(TicketPriority::label($ticket->priority)) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= e(TicketStatus::badgeClass($ticket->status)) ?>">
                                        <?= e(TicketStatus::label($ticket->status)) ?>
                                    </span>
                                </td>
                                <td><?= to_jalali($ticket->updated_at) ?></td>
                                <td>
                                    <a href="<?= url('/tickets/show/' . $ticket->id) ?>" class="btn btn-sm btn-info">
                                        <i class="material-icons">visibility</i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <nav>
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= url('/tickets?status=' . $status . '&page=' . $i) ?>">
                                <?= to_jalali($i, '', true) ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layouts/user.php';
?>
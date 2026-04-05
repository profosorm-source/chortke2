<?php $title = 'سرمایه‌گذاری'; $layout = 'user'; ob_start(); ?>

<div class="content-header">
    <h4><i class="material-icons">trending_up</i> سرمایه‌گذاری</h4>
    <?php if (!$activeInvestment && !$isDepositLocked): ?>
    <a href="<?= url('/investment/create') ?>" class="btn btn-primary btn-sm">
        <i class="material-icons">add</i> سرمایه‌گذاری جدید
    </a>
    <?php endif; ?>
</div>

<!-- هشدار ریسک -->
<div class="alert alert-warning">
    <i class="material-icons">warning</i>
    <span><strong>هشدار:</strong> سرمایه‌گذاری در بازارهای مالی دارای ریسک بالایی است. احتمال ضرر تا ۱۰۰٪ وجود دارد. فقط پولی را سرمایه‌گذاری کنید که توان از دست دادنش را دارید.</span>
</div>

<?php if ($isDepositLocked): ?>
<div class="alert alert-info">
    <i class="material-icons">lock</i>
    <span>به دلیل برداشت اخیر، تا ۷ روز امکان سرمایه‌گذاری جدید ندارید.</span>
</div>
<?php endif; ?>

<?php if ($activeInvestment): ?>
<!-- کارت سرمایه‌گذاری فعال -->
<div class="card">
    <div class="card-header">
        <h5><i class="material-icons">account_balance</i> پلن فعال</h5>
        <span class="badge badge-success">فعال</span>
    </div>
    <div class="card-body">
        <div class="stats-grid">
            <div class="stat-card stat-blue">
                <div class="stat-icon"><i class="material-icons">attach_money</i></div>
                <div class="stat-info">
                    <span class="stat-label">سرمایه اولیه</span>
                    <span class="stat-value"><?= number_format($activeInvestment->amount, 2) ?> <small>USDT</small></span>
                </div>
            </div>
            <div class="stat-card <?= $activeInvestment->current_balance >= $activeInvestment->amount ? 'stat-green' : 'stat-red' ?>">
                <div class="stat-icon"><i class="material-icons">account_balance_wallet</i></div>
                <div class="stat-info">
                    <span class="stat-label">موجودی فعلی</span>
                    <span class="stat-value"><?= number_format($activeInvestment->current_balance, 2) ?> <small>USDT</small></span>
                </div>
            </div>
            <div class="stat-card stat-green">
                <div class="stat-icon"><i class="material-icons">trending_up</i></div>
                <div class="stat-info">
                    <span class="stat-label">مجموع سود</span>
                    <span class="stat-value"><?= number_format($activeInvestment->total_profit, 2) ?></span>
                </div>
            </div>
            <div class="stat-card stat-red">
                <div class="stat-icon"><i class="material-icons">trending_down</i></div>
                <div class="stat-info">
                    <span class="stat-label">مجموع ضرر</span>
                    <span class="stat-value"><?= number_format($activeInvestment->total_loss, 2) ?></span>
                </div>
            </div>
        </div>

        <div class="mt-3" style="display:flex; gap:10px; flex-wrap:wrap;">
            <div style="font-size:13px; color:#666;">
                <strong>تاریخ شروع:</strong> <?= e(to_jalali($activeInvestment->start_date ?? '')) ?>
            </div>
            <?php if ($activeInvestment->last_profit_date): ?>
            <div style="font-size:13px; color:#666;">
                <strong>آخرین محاسبه:</strong> <?= e(to_jalali($activeInvestment->last_profit_date)) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- دکمه برداشت -->
        <div class="mt-4" style="display:flex; gap:10px;">
            <?php if ($canWithdraw['allowed']): ?>
                <?php
                $profit = $activeInvestment->current_balance - $activeInvestment->amount;
                ?>
                <?php if ($profit > 0): ?>
                <button class="btn btn-success" onclick="requestWithdrawal('profit_only')">
                    <i class="material-icons">savings</i> برداشت سود (<?= number_format($profit, 2) ?> USDT)
                </button>
                <?php endif; ?>
                <button class="btn btn-outline-danger" onclick="requestWithdrawal('full_close')">
                    <i class="material-icons">exit_to_app</i> بستن و برداشت کامل
                </button>
            <?php else: ?>
                <div class="alert alert-info" style="margin:0;">
                    <i class="material-icons">schedule</i>
                    <span><?= e($canWithdraw['reason'] ?? '') ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body text-center" style="padding:40px;">
        <i class="material-icons" style="font-size:60px; color:#ccc;">trending_up</i>
        <h5 style="margin-top:15px;">هنوز سرمایه‌گذاری نکرده‌اید</h5>
        <p style="color:#999;">با سرمایه‌گذاری در بازار طلا و فارکس، از سود هفتگی بهره‌مند شوید.</p>
        <?php if (!$isDepositLocked): ?>
        <a href="<?= url('/investment/create') ?>" class="btn btn-primary">شروع سرمایه‌گذاری</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- تاریخچه سود/ضرر -->
<?php if (!empty($profitHistory)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">history</i> تاریخچه سود و ضرر</h5>
        <a href="<?= url('/investment/profit-history') ?>" class="btn btn-sm btn-outline-primary">مشاهده همه</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>دوره</th>
                        <th>درصد</th>
                        <th>سود/ضرر خالص</th>
                        <th>موجودی بعد</th>
                        <th>نوع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profitHistory as $p): ?>
                    <tr>
                        <td><?= e($p->period) ?></td>
                        <td dir="ltr"><?= $p->profit_loss_percent > 0 ? '+' : '' ?><?= e($p->profit_loss_percent) ?>%</td>
                        <td class="<?= $p->net_amount >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $p->net_amount >= 0 ? '+' : '' ?><?= number_format($p->net_amount, 2) ?> USDT
                        </td>
                        <td><?= number_format($p->balance_after, 2) ?></td>
                        <td>
                            <span class="badge <?= $p->type === 'profit' ? 'badge-success' : 'badge-danger' ?>">
                                <?= $p->type === 'profit' ? 'سود' : 'ضرر' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- آخرین تریدها -->
<?php if (!empty($recentTrades)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">candlestick_chart</i> آخرین معاملات</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>جفت ارز</th>
                        <th>جهت</th>
                        <th>قیمت باز</th>
                        <th>قیمت بسته</th>
                        <th>سود/ضرر</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTrades as $t): ?>
                    <tr>
                        <td><?= e($t->pair) ?></td>
                        <td>
                            <span class="badge <?= $t->direction === 'buy' ? 'badge-success' : 'badge-danger' ?>">
                                <?= $t->direction === 'buy' ? 'خرید' : 'فروش' ?>
                            </span>
                        </td>
                        <td dir="ltr"><?= number_format($t->open_price, 2) ?></td>
                        <td dir="ltr"><?= number_format($t->close_price, 2) ?></td>
                        <td class="<?= $t->profit_loss_percent >= 0 ? 'text-success' : 'text-danger' ?>" dir="ltr">
                            <?= $t->profit_loss_percent >= 0 ? '+' : '' ?><?= e($t->profit_loss_percent) ?>%
                        </td>
                        <td><?= e(to_jalali($t->close_time ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- درخواست‌های برداشت -->
<?php if (!empty($withdrawals)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">receipt_long</i> درخواست‌های برداشت</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>مبلغ</th>
                        <th>نوع</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($withdrawals as $w): ?>
                    <tr>
                        <td><?= number_format($w->amount, 2) ?> USDT</td>
                        <td><?= $w->withdrawal_type === 'profit_only' ? 'برداشت سود' : 'بستن کامل' ?></td>
                        <td>
                            <?php
                            $wsl = [
                                'pending' => ['در انتظار', 'badge-warning'],
                                'approved' => ['تأیید شده', 'badge-info'],
                                'completed' => ['واریز شده', 'badge-success'],
                                'rejected' => ['رد شده', 'badge-danger'],
                            ][$w->status] ?? ['؟', 'badge-secondary'];
                            ?>
                            <span class="badge <?= e($wsl[1]) ?>"><?= e($wsl[0]) ?></span>
                        </td>
                        <td><?= e(to_jalali($w->created_at ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function requestWithdrawal(type) {
    const typeLabel = type === 'profit_only' ? 'برداشت سود' : 'بستن و برداشت کامل';
    const warning = type === 'full_close'
        ? 'با بستن کامل، سرمایه‌گذاری شما خاتمه می‌یابد و ۷ روز امکان سرمایه‌گذاری مجدد ندارید.'
        : 'پس از برداشت سود، ۷ روز امکان واریز مجدد ندارید.';

    Swal.fire({
        title: typeLabel,
        text: warning,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'تأیید و ارسال درخواست',
        cancelButtonText: 'انصراف'
    }).then(result => {
        if (result.isConfirmed) {
            fetch('<?= url('/investment/withdraw') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify({ withdrawal_type: type })
            })
            .then(r => r.json())
            .then(res => {
                res.success ? notyf.success(res.message) : notyf.error(res.message);
                if (res.success) setTimeout(() => location.reload(), 1500);
            });
        }
    });
}
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
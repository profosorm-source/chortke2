<?php
$title = 'زیرمجموعه‌گیری و کمیسیون';
$layout = 'user';
ob_start();
?>

<div class="content-header">
    <h4 class="page-title mb-1">
        <i class="material-icons text-primary">people</i>
        زیرمجموعه‌گیری و کمیسیون
    </h4>
    <p class="text-muted mb-0" style="font-size:12px;">با دعوت دوستان خود، از درآمد آن‌ها کمیسیون دریافت کنید</p>
</div>

<!-- لینک دعوت -->
<div class="card mt-3">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="material-icons text-primary" style="font-size:18px;vertical-align:middle;">link</i>
            لینک دعوت اختصاصی شما
        </h6>
    </div>
    <div class="card-body">
        <div class="input-group">
            <input type="text" class="form-control" id="referral-link" 
                   value="<?= e($referralLink) ?>" readonly dir="ltr" style="text-align:left;">
            <button class="btn btn-primary" onclick="copyReferralLink()" id="btn-copy">
                <i class="material-icons" style="font-size:16px;vertical-align:middle;">content_copy</i>
                کپی لینک
            </button>
        </div>
        <small class="text-muted mt-1 d-block">
            کد دعوت شما: <strong dir="ltr"><?= e($user->referral_code ?? '—') ?></strong>
        </small>
    </div>
</div>

<!-- آمار کلی -->
<div class="row mt-3">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <div class="stat-icon mb-2" style="background:rgba(76,175,80,0.1);border-radius:10px;width:50px;height:50px;display:inline-flex;align-items:center;justify-content:center;">
                    <i class="material-icons" style="color:#4caf50;font-size:24px;">people</i>
                </div>
                <h5 class="mb-0" style="font-size:24px;font-weight:bold;"><?= number_format($referredCount) ?></h5>
                <small class="text-muted">تعداد زیرمجموعه</small>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <div class="stat-icon mb-2" style="background:rgba(33,150,243,0.1);border-radius:10px;width:50px;height:50px;display:inline-flex;align-items:center;justify-content:center;">
                    <i class="material-icons" style="color:#2196f3;font-size:24px;">payments</i>
                </div>
                <h5 class="mb-0" style="font-size:24px;font-weight:bold;">
                    <?= number_format($stats->total_earned_irt ?? 0) ?>
                </h5>
                <small class="text-muted">کل درآمد (تومان)</small>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <div class="stat-icon mb-2" style="background:rgba(255,152,0,0.1);border-radius:10px;width:50px;height:50px;display:inline-flex;align-items:center;justify-content:center;">
                    <i class="material-icons" style="color:#ff9800;font-size:24px;">schedule</i>
                </div>
                <h5 class="mb-0" style="font-size:24px;font-weight:bold;">
                    <?= number_format($stats->pending_irt ?? 0) ?>
                </h5>
                <small class="text-muted">در انتظار پرداخت (تومان)</small>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <div class="stat-icon mb-2" style="background:rgba(156,39,176,0.1);border-radius:10px;width:50px;height:50px;display:inline-flex;align-items:center;justify-content:center;">
                    <i class="material-icons" style="color:#9c27b0;font-size:24px;">currency_bitcoin</i>
                </div>
                <h5 class="mb-0" style="font-size:24px;font-weight:bold;">
                    <?= number_format($stats->total_earned_usdt ?? 0, 2) ?>
                </h5>
                <small class="text-muted">کل درآمد (USDT)</small>
            </div>
        </div>
    </div>
</div>

<!-- درصدهای کمیسیون -->
<div class="card mt-3">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="material-icons text-primary" style="font-size:18px;vertical-align:middle;">percent</i>
            درصد کمیسیون فعلی
        </h6>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($percents as $type => $percent): ?>
            <div class="col-md-3 col-6 mb-2">
                <div class="p-3 rounded" style="background:#f8f9fa;text-align:center;">
                    <strong style="font-size:20px;color:#4fc3f7;"><?= e($percent) ?>%</strong>
                    <br>
                    <small class="text-muted"><?= e($sourceTypes[$type] ?? $type) ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="alert mt-3 mb-0" style="background:linear-gradient(135deg,#e3f2fd 0%,#bbdefb 100%);border:none;font-size:13px;">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;color:#1976d2;">info</i>
            با هر فعالیت درآمدزای زیرمجموعه مستقیم شما، درصد مشخصی به‌عنوان کمیسیون به کیف پول شما واریز می‌شود.
        </div>
    </div>
</div>

<!-- لیست زیرمجموعه‌ها -->
<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">
            <i class="material-icons text-primary" style="font-size:18px;vertical-align:middle;">group</i>
            زیرمجموعه‌های شما
        </h6>
        <span class="badge bg-info"><?= number_format($referredCount) ?> نفر</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>نام</th>
                        <th>تاریخ عضویت</th>
                        <th>درآمد شما (تومان)</th>
                        <th>درآمد شما (USDT)</th>
                        <th>تعداد کمیسیون</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($referredUsers)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="material-icons" style="font-size:40px;opacity:0.3;">person_add</i>
                            <p class="mt-2 mb-0">هنوز زیرمجموعه‌ای ندارید. لینک دعوت خود را به اشتراک بگذارید!</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($referredUsers as $idx => $ru): ?>
                    <tr>
                        <td class="text-muted"><?= $idx + 1 ?></td>
                        <td><?= e($ru->full_name ?? '—') ?></td>
                        <td style="font-size:12px;"><?= to_jalali($ru->joined_at ?? '') ?></td>
                        <td>
                            <span class="text-success"><?= number_format($ru->earned_irt ?? 0) ?></span>
                        </td>
                        <td>
                            <span class="text-info"><?= number_format($ru->earned_usdt ?? 0, 2) ?></span>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?= number_format($ru->commission_count ?? 0) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- آخرین کمیسیون‌ها -->
<div class="card mt-3 mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">
            <i class="material-icons text-primary" style="font-size:18px;vertical-align:middle;">receipt_long</i>
            آخرین کمیسیون‌ها
        </h6>
        <span class="badge bg-primary"><?= number_format($stats->total_count ?? 0) ?> تراکنش</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>زیرمجموعه</th>
                        <th>منبع</th>
                        <th>مبلغ اصلی</th>
                        <th>درصد</th>
                        <th>کمیسیون</th>
                        <th>ارز</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentCommissions)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            <i class="material-icons" style="font-size:40px;opacity:0.3;">hourglass_empty</i>
                            <p class="mt-2 mb-0">هنوز کمیسیونی ثبت نشده است.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($recentCommissions as $idx => $c): ?>
                    <tr>
                        <td class="text-muted"><?= $idx + 1 ?></td>
                        <td style="font-size:12px;"><?= e($c->referred_name ?? '—') ?></td>
                        <td>
                            <span class="badge" style="background:#e3f2fd;color:#1976d2;font-size:10px;">
                                <?= e(($c->source_label ?? $c->source_type)) ?>
                            </span>
                        </td>
                        <td style="font-size:12px;">
                            <?= $c->currency === 'usdt' ? number_format($c->source_amount, 2) : number_format($c->source_amount) ?>
                        </td>
                        <td style="font-size:12px;"><?= e($c->commission_percent) ?>%</td>
                        <td>
                            <strong class="text-success" style="font-size:13px;">
                                <?= $c->currency === 'usdt' ? number_format($c->commission_amount, 2) : number_format($c->commission_amount) ?>
                            </strong>
                        </td>
                        <td>
                            <span class="badge" style="background:#f5f5f5;color:#666;font-size:10px;">
                                <?= $c->currency === 'usdt' ? 'USDT' : 'تومان' ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $statusLabels = ['pending' => 'در انتظار', 'paid' => 'پرداخت شده', 'cancelled' => 'لغو', 'failed' => 'ناموفق'];
                            $statusClasses = ['pending' => 'badge-warning', 'paid' => 'badge-success', 'cancelled' => 'badge-danger', 'failed' => 'badge-danger'];
                            ?>
                            <span class="badge <?= $statusClasses[$c->status] ?? 'badge-secondary' ?>">
                                <?= e($statusLabels[$c->status] ?? $c->status) ?>
                            </span>
                        </td>
                        <td style="font-size:11px;"><?= to_jalali($c->created_at ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function copyReferralLink() {
    var input = document.getElementById('referral-link');
    input.select();
    input.setSelectionRange(0, 99999);
    
    navigator.clipboard.writeText(input.value).then(function() {
        var btn = document.getElementById('btn-copy');
        btn.innerHTML = '<i class="material-icons" style="font-size:16px;vertical-align:middle;">check</i> کپی شد!';
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-success');
        
        setTimeout(function() {
            btn.innerHTML = '<i class="material-icons" style="font-size:16px;vertical-align:middle;">content_copy</i> کپی لینک';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-primary');
        }, 2000);
        
        var notyf = new Notyf({duration: 2000, position: {x:'left', y:'top'}});
        notyf.success('لینک دعوت کپی شد!');
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/' . $layout . '.php';
?>
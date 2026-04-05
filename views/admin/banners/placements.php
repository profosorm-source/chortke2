<?php $title = 'مدیریت جایگاه‌های بنر'; $layout = 'admin'; ob_start(); ?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><span class="material-icons me-1" style="vertical-align:middle;">dashboard</span> مدیریت جایگاه‌های بنر</h4>
        <a href="<?= url('/admin/banners') ?>" class="btn btn-outline-secondary">
            <span class="material-icons" style="font-size:14px;vertical-align:middle;">arrow_forward</span> بازگشت به بنرها
        </a>
    </div>

    <div class="row">
        <?php foreach ($placements as $placement): ?>
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card h-100" id="placement-<?= e($placement->id) ?>">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><?= e($placement->title) ?></h6>
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input toggle-placement"
                                   data-id="<?= e($placement->id) ?>"
                                   <?= $placement->is_active ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-2"><?= e($placement->description ?? '') ?></p>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-muted">شناسه (slug):</small>
                            <code><?= e($placement->slug) ?></code>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-muted">بنرهای فعال:</small>
                            <span class="badge bg-primary"><?= $placement->active_banners ?? 0 ?> / <?= e($placement->max_banners) ?></span>
                        </div>

                        <div class="mb-3">
                            <label class="form-label mb-1" style="font-size:12px;">حداکثر بنر</label>
                            <input type="number" class="form-control form-control-sm placement-field" data-id="<?= e($placement->id) ?>" data-field="max_banners" value="<?= e($placement->max_banners) ?>" min="1" max="20">
                        </div>

                        <div class="mb-3">
                            <label class="form-label mb-1" style="font-size:12px;">سرعت چرخش (میلی‌ثانیه)</label>
                            <input type="number" class="form-control form-control-sm placement-field" data-id="<?= e($placement->id) ?>" data-field="rotation_speed" value="<?= e($placement->rotation_speed) ?>" min="1000" max="30000" step="500">
                        </div>

                        <div class="row">
                            <div class="col-6">
                                <label class="form-label mb-1" style="font-size:12px;">حداکثر عرض (px)</label>
                                <input type="number" class="form-control form-control-sm placement-field" data-id="<?= e($placement->id) ?>" data-field="max_width" value="<?= $placement->max_width ?? '' ?>" min="0">
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1" style="font-size:12px;">حداکثر ارتفاع (px)</label>
                                <input type="number" class="form-control form-control-sm placement-field" data-id="<?= e($placement->id) ?>" data-field="max_height" value="<?= $placement->max_height ?? '' ?>" min="0">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-sm btn-primary w-100 save-placement" data-id="<?= e($placement->id) ?>">
                            <span class="material-icons" style="font-size:14px;vertical-align:middle;">save</span> ذخیره تغییرات
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle جایگاه
    document.querySelectorAll('.toggle-placement').forEach(function(el) {
        el.addEventListener('change', function() {
            const id = this.dataset.id;
            fetch('<?= url('/admin/banners/placements/') ?>' + id + '/toggle', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (typeof notyf !== 'undefined') notyf.success(data.message);
                } else {
                    el.checked = !el.checked;
                    if (typeof notyf !== 'undefined') notyf.error(data.message || 'خطایی رخ داد');
                }
            })
            .catch(() => { el.checked = !el.checked; });
        });
    });

    // ذخیره جایگاه
    document.querySelectorAll('.save-placement').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const card = document.getElementById('placement-' + id);
            const fields = card.querySelectorAll('.placement-field');
            const data = {};

            fields.forEach(function(field) {
                const fieldName = field.dataset.field;
                data[fieldName] = field.value ? parseInt(field.value) : null;
            });

            fetch('<?= url('/admin/banners/placements/') ?>' + id + '/update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (typeof notyf !== 'undefined') notyf.success(data.message);
                } else {
                    if (typeof notyf !== 'undefined') notyf.error(data.message || 'خطایی رخ داد');
                }
            });
        });
    });
});
</script>

<?php $content = ob_get_clean(); include __DIR__ . '/../../layouts/' . $layout . '.php'; ?>
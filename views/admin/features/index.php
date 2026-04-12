<?php
$pageTitle = 'مدیریت پیشرفته فیچرها';
ob_start();
?>

<style>
.feature-card {
    transition: all 0.3s ease;
    border-right: 4px solid transparent;
}

.feature-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border-right-color: #007bff;
}

.feature-card.enabled {
    border-right-color: #28a745;
}

.feature-card.disabled {
    border-right-color: #dc3545;
}

.badge-pill {
    padding: 0.4em 0.8em;
}

.percentage-slider {
    width: 100%;
}

.percentage-display {
    font-size: 1.2em;
    font-weight: bold;
    color: #007bff;
}

.tag-input {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 0.25rem;
    min-height: 45px;
}

.tag {
    background: #007bff;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.tag .remove {
    cursor: pointer;
    font-weight: bold;
}

.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 2em;
    font-weight: bold;
}

.stat-label {
    opacity: 0.9;
    font-size: 0.9em;
}
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>
            <i class="material-icons">flag</i>
            مدیریت پیشرفته فیچرها (Feature Flags)
        </h4>
        <button class="btn btn-primary" onclick="showCreateModal()">
            <i class="material-icons">add</i>
            افزودن فیچر جدید
        </button>
    </div>

    <div class="stats-card">
        <h5 class="mb-3">
            <i class="material-icons">analytics</i>
            آمار فیچرها
        </h5>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-value"><?= count($features) ?></div>
                <div class="stat-label">کل فیچرها</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">
                    <?= count(array_filter($features, fn($f) => $f->enabled)) ?>
                </div>
                <div class="stat-label">فعال</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">
                    <?= count(array_filter($features, fn($f) => !$f->enabled)) ?>
                </div>
                <div class="stat-label">غیرفعال</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">
                    <?= count(array_filter($features, fn($f) => $f->enabled_for_roles)) ?>
                </div>
                <div class="stat-label">محدود به نقش</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">
                    <?= count(array_filter($features, fn($f) => $f->enabled_percentage < 100)) ?>
                </div>
                <div class="stat-label">A/B Testing</div>
            </div>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="material-icons">info</i>
        <strong>راهنما:</strong> 
        با Feature Flags می‌توانید بخش‌های مختلف را بدون تغییر کد فعال/غیرفعال کنید، 
        فیچرها را محدود به نقش‌ها یا کاربران خاص کنید، و A/B Testing انجام دهید.
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <input type="text" id="searchFeature" class="form-control" 
                           placeholder="جستجوی نام یا توضیحات فیچر...">
                </div>
                <div class="col-md-3">
                    <select id="filterStatus" class="form-control">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="enabled">فعال</option>
                        <option value="disabled">غیرفعال</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select id="filterType" class="form-control">
                        <option value="">همه انواع</option>
                        <option value="role">محدود به نقش</option>
                        <option value="user">محدود به کاربر</option>
                        <option value="percentage">A/B Testing</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div id="featuresContainer">
        <?php foreach ($features as $feature): ?>
            <?php
            $rolesArray = $feature->enabled_for_roles ? json_decode($feature->enabled_for_roles, true) : [];
            $usersArray = $feature->enabled_for_users ? json_decode($feature->enabled_for_users, true) : [];
            ?>
            <div class="card feature-card <?= $feature->enabled ? 'enabled' : 'disabled' ?> mb-3" 
                 data-name="<?= e($feature->name) ?>"
                 data-status="<?= $feature->enabled ? 'enabled' : 'disabled' ?>"
                 data-has-role="<?= !empty($rolesArray) ? '1' : '0' ?>"
                 data-has-user="<?= !empty($usersArray) ? '1' : '0' ?>"
                 data-percentage="<?= $feature->enabled_percentage ?>">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <h5 class="mb-2">
                                <code class="text-primary"><?= e($feature->name) ?></code>
                            </h5>
                            <p class="text-muted mb-2"><?= e($feature->description) ?></p>
                            
                            <div class="mt-2">
                                <span class="badge <?= $feature->enabled ? 'badge-success' : 'badge-secondary' ?> badge-pill">
                                    <i class="material-icons" style="font-size: 16px;">
                                        <?= $feature->enabled ? 'toggle_on' : 'toggle_off' ?>
                                    </i>
                                    <?= $feature->enabled ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </div>
                        </div>

                        <div class="col-md-5">
                            <div class="row">
                                <div class="col-6">
                                    <small class="text-muted">درصد فعال‌سازی:</small>
                                    <div class="percentage-display">
                                        <?= e($feature->enabled_percentage) ?>%
                                    </div>
                                    <?php if ($feature->enabled_percentage < 100): ?>
                                        <small class="text-info">
                                            <i class="material-icons" style="font-size: 14px;">science</i>
                                            A/B Testing
                                        </small>
                                    <?php endif; ?>
                                </div>

                                <div class="col-6">
                                    <small class="text-muted">محدودیت‌ها:</small>
                                    <div class="mt-1">
                                        <?php if (!empty($rolesArray)): ?>
                                            <span class="badge badge-info badge-pill" 
                                                  title="محدود به نقش‌های: <?= implode(', ', $rolesArray) ?>">
                                                <i class="material-icons" style="font-size: 14px;">group</i>
                                                <?= count($rolesArray) ?> نقش
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($usersArray)): ?>
                                            <span class="badge badge-warning badge-pill"
                                                  title="محدود به <?= count($usersArray) ?> کاربر">
                                                <i class="material-icons" style="font-size: 14px;">person</i>
                                                <?= count($usersArray) ?> کاربر
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if (empty($rolesArray) && empty($usersArray)): ?>
                                            <span class="badge badge-secondary badge-pill">
                                                <i class="material-icons" style="font-size: 14px;">public</i>
                                                عمومی
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3 text-left">
                            <button class="btn btn-sm <?= $feature->enabled ? 'btn-danger' : 'btn-success' ?>" 
                                    onclick="toggleFeature('<?= e($feature->name) ?>')">
                                <i class="material-icons">power_settings_new</i>
                                <?= $feature->enabled ? 'غیرفعال' : 'فعال' ?>
                            </button>
                            
                            <button class="btn btn-sm btn-info" 
                                    onclick="showEditModal(<?= htmlspecialchars(json_encode($feature)) ?>)">
                                <i class="material-icons">edit</i>
                                ویرایش پیشرفته
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="editFeatureModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="material-icons">tune</i>
                    ویرایش پیشرفته فیچر: <code id="modalFeatureName"></code>
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="editFeatureForm">
                    <input type="hidden" id="editFeatureName">
                    
                    <div class="form-group">
                        <label>
                            <i class="material-icons">description</i>
                            توضیحات
                        </label>
                        <textarea id="editDescription" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="material-icons">percent</i>
                            درصد فعال‌سازی (A/B Testing)
                        </label>
                        <div class="row align-items-center">
                            <div class="col-9">
                                <input type="range" id="editPercentage" class="percentage-slider" 
                                       min="0" max="100" value="100" step="5">
                            </div>
                            <div class="col-3">
                                <input type="number" id="editPercentageValue" class="form-control" 
                                       min="0" max="100" value="100">
                            </div>
                        </div>
                        <small class="text-muted">
                            با مقدار کمتر از 100، فقط درصدی از کاربران دسترسی خواهند داشت (برای تست تدریجی)
                        </small>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="material-icons">group</i>
                            محدودیت براساس نقش (Role)
                        </label>
                        <div class="tag-input" id="rolesTagInput">
                            <input type="text" id="roleInput" class="border-0 flex-grow-1" 
                                   placeholder="نقش را تایپ کرده و Enter بزنید..." 
                                   style="outline: none; min-width: 200px;">
                        </div>
                        <small class="text-muted">
                            مثال: admin, moderator, premium_user
                        </small>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="material-icons">person</i>
                            محدودیت براساس شناسه کاربر (User ID)
                        </label>
                        <div class="tag-input" id="usersTagInput">
                            <input type="text" id="userInput" class="border-0 flex-grow-1" 
                                   placeholder="User ID را تایپ کرده و Enter بزنید..." 
                                   style="outline: none; min-width: 200px;">
                        </div>
                        <small class="text-muted">
                            مثال: 123, 456, 789
                        </small>
                    </div>

                    <div class="alert alert-light">
                        <strong>
                            <i class="material-icons">preview</i>
                            پیش‌نمایش:
                        </strong>
                        <div id="settingsPreview" class="mt-2"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">بستن</button>
                <button type="button" class="btn btn-primary" onclick="saveFeature()">
                    <i class="material-icons">save</i>
                    ذخیره تنظیمات
                </button>
            </div>
        </div>
    </div>
</div>

<script>
class TagInput {
    constructor(containerId, inputId) {
        this.container = document.getElementById(containerId);
        this.input = document.getElementById(inputId);
        this.tags = [];
        
        this.input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && this.input.value.trim()) {
                e.preventDefault();
                this.addTag(this.input.value.trim());
                this.input.value = '';
            }
        });
    }
    
    addTag(value) {
        if (!this.tags.includes(value)) {
            this.tags.push(value);
            this.render();
            updatePreview();
        }
    }
    
    removeTag(value) {
        this.tags = this.tags.filter(t => t !== value);
        this.render();
        updatePreview();
    }
    
    setTags(tags) {
        this.tags = tags || [];
        this.render();
    }
    
    getTags() {
        return this.tags;
    }
    
    render() {
        const existingTags = this.container.querySelectorAll('.tag');
        existingTags.forEach(tag => tag.remove());
        
        this.tags.forEach(tag => {
            const tagEl = document.createElement('div');
            tagEl.className = 'tag';
            tagEl.innerHTML = `
                ${tag}
                <span class="remove" onclick="removeTagFromInput('${this.container.id}', '${tag}')">&times;</span>
            `;
            this.container.insertBefore(tagEl, this.input);
        });
    }
}

const rolesTagInput = new TagInput('rolesTagInput', 'roleInput');
const usersTagInput = new TagInput('usersTagInput', 'userInput');

function removeTagFromInput(containerId, value) {
    if (containerId === 'rolesTagInput') {
        rolesTagInput.removeTag(value);
    } else {
        usersTagInput.removeTag(value);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const slider = document.getElementById('editPercentage');
    const numberInput = document.getElementById('editPercentageValue');
    
    slider?.addEventListener('input', () => {
        numberInput.value = slider.value;
        updatePreview();
    });
    
    numberInput?.addEventListener('input', () => {
        slider.value = numberInput.value;
        updatePreview();
    });
});

function updatePreview() {
    const percentage = document.getElementById('editPercentageValue')?.value || 100;
    const roles = rolesTagInput.getTags();
    const users = usersTagInput.getTags();
    
    let preview = '<ul class="mb-0">';
    
    if (percentage < 100) {
        preview += `<li>فقط <strong>${percentage}%</strong> از کاربران دسترسی دارند (A/B Testing)</li>`;
    } else {
        preview += `<li>همه کاربران دسترسی دارند (${percentage}%)</li>`;
    }
    
    if (roles.length > 0) {
        preview += `<li>محدود به نقش‌های: <code>${roles.join(', ')}</code></li>`;
    }
    
    if (users.length > 0) {
        preview += `<li>محدود به کاربران با ID: <code>${users.join(', ')}</code></li>`;
    }
    
    if (roles.length === 0 && users.length === 0) {
        preview += '<li>بدون محدودیت خاص</li>';
    }
    
    preview += '</ul>';
    
    document.getElementById('settingsPreview').innerHTML = preview;
}

function showEditModal(feature) {
    document.getElementById('modalFeatureName').textContent = feature.name;
    document.getElementById('editFeatureName').value = feature.name;
    document.getElementById('editDescription').value = feature.description || '';
    
    const percentage = feature.enabled_percentage || 100;
    document.getElementById('editPercentage').value = percentage;
    document.getElementById('editPercentageValue').value = percentage;
    
    const roles = feature.enabled_for_roles ? JSON.parse(feature.enabled_for_roles) : [];
    rolesTagInput.setTags(roles);
    
    const users = feature.enabled_for_users ? JSON.parse(feature.enabled_for_users) : [];
    usersTagInput.setTags(users.map(String));
    
    updatePreview();
    $('#editFeatureModal').modal('show');
}

function saveFeature() {
    const name = document.getElementById('editFeatureName').value;
    const description = document.getElementById('editDescription').value;
    const percentage = parseInt(document.getElementById('editPercentageValue').value);
    const roles = rolesTagInput.getTags();
    const users = usersTagInput.getTags().map(id => parseInt(id));
    
    fetch('<?= url('/admin/features/update') ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '<?= csrf_token() ?>'
        },
        body: JSON.stringify({
            name: name,
            description: description,
            enabled_percentage: percentage,
            enabled_for_roles: roles,
            enabled_for_users: users
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            notyf.success('تنظیمات با موفقیت ذخیره شد');
            $('#editFeatureModal').modal('hide');
            setTimeout(() => location.reload(), 1000);
        } else {
            notyf.error(data.message || 'خطا در ذخیره تنظیمات');
        }
    })
    .catch(err => {
        notyf.error('خطا در ارتباط با سرور');
        console.error(err);
    });
}

function toggleFeature(name) {
    Swal.fire({
        title: 'تغییر وضعیت فیچر',
        text: `آیا مطمئن هستید؟`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'بله',
        cancelButtonText: 'خیر'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= url('/admin/features/toggle') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify({ name: name })
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
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('searchFeature');
    const statusFilter = document.getElementById('filterStatus');
    const typeFilter = document.getElementById('filterType');
    
    function filterFeatures() {
        const searchTerm = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        const type = typeFilter.value;
        
        document.querySelectorAll('.feature-card').forEach(card => {
            const name = card.dataset.name.toLowerCase();
            const cardStatus = card.dataset.status;
            const hasRole = card.dataset.hasRole === '1';
            const hasUser = card.dataset.hasUser === '1';
            const percentage = parseInt(card.dataset.percentage);
            
            let show = true;
            
            if (searchTerm && !name.includes(searchTerm)) {
                show = false;
            }
            
            if (status && cardStatus !== status) {
                show = false;
            }
            
            if (type === 'role' && !hasRole) show = false;
            if (type === 'user' && !hasUser) show = false;
            if (type === 'percentage' && percentage >= 100) show = false;
            
            card.style.display = show ? 'block' : 'none';
        });
    }
    
    searchInput?.addEventListener('input', filterFeatures);
    statusFilter?.addEventListener('change', filterFeatures);
    typeFilter?.addEventListener('change', filterFeatures);
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layouts/admin.php';
?>

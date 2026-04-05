<?php $title = 'مدیریت Online Store'; $layout = 'admin'; ob_start(); ?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="page-title mb-1"><span class="material-icons text-primary">storefront</span> مدیریت Online Store</h4>
      <p class="text-muted mb-0" style="font-size:12px;">مدیریت آگهی‌های خرید و فروش پیج/کانال — فقط USDT</p>
    </div>
  </div>

  <!-- فیلترها -->
  <div class="card mb-3">
    <div class="card-body py-2">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
          <select name="status" class="form-select form-select-sm">
            <option value="">همه وضعیت‌ها</option>
            <?php foreach($statuses as $k=>$v): ?>
              <option value="<?= e($k) ?>" <?= ($filters['status']??'')===$k?'selected':'' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary btn-sm w-100">فیلتر</button>
        </div>
        <div class="col-md-2">
          <a href="<?= url('/admin/online-store') ?>" class="btn btn-outline-secondary btn-sm w-100">پاک‌سازی</a>
        </div>
      </form>
    </div>
  </div>

  <!-- جدول آگهی‌ها -->
  <div class="card">
    <div class="card-body p-0">
      <?php if(empty($listings)): ?>
        <div class="text-center py-5 text-muted"><span class="material-icons" style="font-size:48px;">storefront</span><br>هیچ آگهی‌ای یافت نشد.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>فروشنده</th>
              <th>عنوان</th>
              <th>پلتفرم</th>
              <th>قیمت (USDT)</th>
              <th>وضعیت</th>
              <th>تاریخ ثبت</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($listings as $l): ?>
            <tr>
              <td><?= e($l->id) ?></td>
              <td><?= e($l->seller_name ?? $l->seller_id) ?></td>
              <td><?= e(mb_substr($l->title,0,40)) ?><?= mb_strlen($l->title)>40?'...':'' ?></td>
              <td><span class="badge bg-info"><?= e($l->platform) ?></span></td>
              <td class="fw-bold text-success"><?= number_format($l->price_usdt,2) ?> USDT</td>
              <td>
                <?php $sc=['pending'=>'warning','active'=>'success','in_escrow'=>'primary','sold'=>'secondary','rejected'=>'danger','cancelled'=>'secondary','disputed'=>'danger']; ?>
                <span class="badge bg-<?= $sc[$l->status]??'secondary' ?>"><?= e($statuses[$l->status]??$l->status) ?></span>
              </td>
              <td style="font-size:12px;"><?= e(substr($l->created_at??'',0,10)) ?></td>
              <td>
                <div class="d-flex gap-1">
                  <a href="<?= url("/online-store/{$l->id}") ?>" target="_blank" class="btn btn-outline-info btn-sm" title="مشاهده">
                    <span class="material-icons" style="font-size:16px;">visibility</span>
                  </a>
                  <?php if($l->status==='pending'): ?>
                  <button class="btn btn-success btn-sm btn-approve" data-id="<?= e($l->id) ?>" title="تایید">
                    <span class="material-icons" style="font-size:16px;">check</span>
                  </button>
                  <button class="btn btn-danger btn-sm btn-reject" data-id="<?= e($l->id) ?>" title="رد">
                    <span class="material-icons" style="font-size:16px;">close</span>
                  </button>
                  <?php endif; ?>
                  <?php if($l->status==='disputed'): ?>
                  <a href="<?= url("/admin/online-store/{$l->id}/dispute") ?>" class="btn btn-warning btn-sm" title="رسیدگی اختلاف">
                    <span class="material-icons" style="font-size:16px;">gavel</span>
                  </a>
                  <?php endif; ?>
                  <?php if($l->status==='in_escrow'): ?>
                  <button class="btn btn-primary btn-sm btn-release" data-id="<?= e($l->id) ?>" title="آزادسازی وجه">
                    <span class="material-icons" style="font-size:16px;">payments</span>
                  </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <!-- Pagination -->
      <?php if($pages>1): ?>
      <div class="d-flex justify-content-center p-3">
        <nav><ul class="pagination pagination-sm mb-0">
          <?php for($i=1;$i<=$pages;$i++): ?>
          <li class="page-item <?= $i==$page?'active':'' ?>">
            <a class="page-link" href="?page=<?= $i ?>&status=<?= e($filters['status']??'') ?>"><?= $i ?></a>
          </li>
          <?php endfor; ?>
        </ul></nav>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal رد آگهی -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title">رد آگهی</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <textarea id="rejectReason" class="form-control" rows="3" placeholder="دلیل رد (اختیاری)"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger btn-sm" id="confirmReject">رد کن</button>
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">لغو</button>
      </div>
    </div>
  </div>
</div>

<script>
let rejectId = null;
document.querySelectorAll('.btn-approve').forEach(btn => {
  btn.addEventListener('click', function() {
    if(!confirm('آیا این آگهی را تایید می‌کنید؟')) return;
    fetch(`/admin/online-store/${this.dataset.id}/approve`, {method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''}})
      .then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert('خطا'); });
  });
});
document.querySelectorAll('.btn-reject').forEach(btn => {
  btn.addEventListener('click', function() {
    rejectId = this.dataset.id;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
  });
});
document.getElementById('confirmReject')?.addEventListener('click', function() {
  if(!rejectId) return;
  const reason = document.getElementById('rejectReason').value;
  fetch(`/admin/online-store/${rejectId}/reject`, {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''},body:JSON.stringify({reason})})
    .then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert('خطا'); });
});
document.querySelectorAll('.btn-release').forEach(btn => {
  btn.addEventListener('click', function() {
    if(!confirm('آزادسازی وجه به فروشنده؟')) return;
    fetch(`/admin/online-store/${this.dataset.id}/release-funds`, {method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''}})
      .then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert('خطا'); });
  });
});
</script>

<?php $content = ob_get_clean(); include base_path('views/layouts/admin.php'); ?>

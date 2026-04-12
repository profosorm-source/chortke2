<?php if (empty($banners)) return; ?>
<div class="banner-static" data-placement="<?= htmlspecialchars($placementSlug) ?>">
    <?php foreach ($banners as $banner): ?>
        <div class="banner-item" data-banner-id="<?= $banner->id ?>">
            <?php if ($banner->image_path): ?>
                <a href="<?= htmlspecialchars($banner->link ?? '#') ?>" target="<?= htmlspecialchars($banner->target ?? '_blank') ?>" onclick="registerBannerClick(<?= $banner->id ?>)">
                    <img src="<?= htmlspecialchars($banner->image_path) ?>" alt="<?= htmlspecialchars($banner->alt_text ?? $banner->title) ?>" loading="lazy">
                </a>
            <?php elseif ($banner->custom_code): ?>
                <div class="banner-custom"><?= $banner->custom_code ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<style>
.banner-static{width:100%;margin:20px 0}
.banner-item{margin-bottom:15px;border-radius:8px;overflow:hidden}
.banner-item img{width:100%;height:auto;display:block;transition:transform 0.3s}
.banner-item a:hover img{transform:scale(1.02)}
</style>

<script>
function registerBannerClick(id){fetch('/api/banner-click',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({banner_id:id})})}
</script>

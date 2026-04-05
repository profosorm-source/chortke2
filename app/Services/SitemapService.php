<?php

namespace App\Services;

use App\Models\Page;

class SitemapService
{
    private Page $pageModel;
    
    public function __construct(
        \App\Models\Page $pageModel
    )
    {
        $this->pageModel = $pageModel;
    }
    
    /**
     * تولید Sitemap
     */
    public function generate(): string
    {
        $baseUrl = setting('site_url', 'http://localhost');
        $pages = $this->pageModel->getAll();
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        // صفحه اصلی
        $xml .= $this->addUrl($baseUrl, '1.0', 'daily', date('Y-m-d'));
        
        // صفحات استاتیک
        foreach ($pages as $page) {
            if ($page->is_active) {
                $url = $baseUrl . '/pages/' . $page->slug;
                $xml .= $this->addUrl($url, '0.8', 'weekly', date('Y-m-d', strtotime($page->updated_at)));
            }
        }
        
        // سایر صفحات عمومی
        $publicPages = [
            '/login' => ['priority' => '0.7', 'changefreq' => 'monthly'],
            '/register' => ['priority' => '0.7', 'changefreq' => 'monthly']
        ];
        
        foreach ($publicPages as $path => $config) {
            $xml .= $this->addUrl(
                $baseUrl . $path,
                $config['priority'],
                $config['changefreq'],
                date('Y-m-d')
            );
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }
    
    /**
     * افزودن URL
     */
    private function addUrl(string $loc, string $priority, string $changefreq, string $lastmod): string
    {
        $xml = "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($loc) . "</loc>\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
        $xml .= "    <priority>{$priority}</priority>\n";
        $xml .= "  </url>\n";
        
        return $xml;
    }
    
    /**
     * ذخیره فایل
     */
    public function save(): bool
    {
        $xml = $this->generate();
        $path = __DIR__ . '/../../public/sitemap.xml';
        
        return file_put_contents($path, $xml) !== false;
    }
}
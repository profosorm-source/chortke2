<?php

namespace App\Controllers;

use App\Services\SitemapService;
use App\Controllers\BaseController;

class SitemapController extends BaseController
{
    private SitemapService $sitemapService;
    
    public function __construct(
        \App\Services\SitemapService $sitemapService)
    {
        parent::__construct();
        $this->sitemapService = $sitemapService;
    }
    
    /**
     * نمایش Sitemap
     */
    public function index()
    {
        $xml = $this->sitemapService->generate();
        
                $this->response->header('Content-Type', 'application/xml; charset=utf-8');
        
        echo $xml;
    }
}
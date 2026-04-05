<?php

namespace Core;

class Application
{
    private static ?Application $instance = null;

    public Container $container;
    public Database  $db;
    public Router    $router;
    public Request   $request;
    public Response  $response;
    public Session   $session;
    public ExceptionHandler $exceptionHandler;
    public array $config;

    private function __construct()
    {
        // ── ۱. Config ────────────────────────────────────────────
        $this->config = require __DIR__ . '/../config/config.php';

        // ── ۲. Session — getInstance + start (یک‌جا، یک‌بار) ──────
        $this->session = Session::getInstance();
        $this->session->start();

        // ── ۳. ExceptionHandler — فقط یک‌بار در کل lifecycle ────
        //    index.php دیگر ExceptionHandler::register() صدا نمی‌زند
        $this->exceptionHandler = new ExceptionHandler();
        ExceptionHandler::register();

        // ── ۴. Core Objects ──────────────────────────────────────
        $this->request  = new Request();
        $this->response = new Response();
        $this->router   = new Router($this->request, $this->response);

        // ── ۵. Database ──────────────────────────────────────────
        try {
            $this->db = Database::getInstance();
        } catch (\Exception $e) {
            if (env('APP_DEBUG')) {
                die("خطای اتصال به دیتابیس: " . $e->getMessage());
            } else {
                die("خطای سیستمی. لطفاً با پشتیبانی تماس بگیرید.");
            }
        }

        // ── ۶. Container — ثبت singletonهای هسته ────────────────
        $this->container = Container::getInstance();
        $this->registerCoreBindings();

        // ── ۷. Maintenance Mode ──────────────────────────────────
        if (env('MAINTENANCE_MODE') === 'true' || env('MAINTENANCE_MODE') === true) {
            if (!$this->session->get('is_admin')) {
                http_response_code(503);
                $view = __DIR__ . '/../views/errors/503.php';
                file_exists($view)
                    ? require $view
                    : require __DIR__ . '/../views/errors/maintenance.php';
                exit;
            }
        }
    }

    /**
     * ثبت singleton‌های هسته در Container
     * هر کدی که Container::make() می‌زند،
     * همین instance‌ها را دریافت می‌کند.
     */
    private function registerCoreBindings(): void
    {
        $c = $this->container;

        // ── Core singletons — instance\u200cهای آماده ─────────────────
        $c->instance(Application::class, $this);
        $c->instance(Container::class,   $c);
        $c->instance(Request::class,     $this->request);
        $c->instance(Response::class,    $this->response);
        $c->instance(Session::class,     $this->session);
        $c->instance(Database::class,    $this->db);
        $c->instance(Router::class,      $this->router);

        // ── App-level singletons — یک بار در طول request ────────
        // هر Controller که AuthService یا User نیاز دارد،
        // همین instance را دریافت می\u200cکند (نه instance جدید)
        $c->singleton(\App\Services\AuthService::class);
        $c->singleton(\App\Models\User::class);
    }
    /**
     * دریافت کاربر لاگین‌شده
     *
     * از Container → User Model می‌خواند (نه مستقیم از DB)
     */
    public function user(): ?object
    {
        $userId = $this->session->get('user_id');
        if (!$userId) {
            return null;
        }
        try {
            $userModel = $this->container->make(\App\Models\User::class);
            return $userModel->find((int) $userId);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __clone() {}
    
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    public function run(): void
    {
        $this->router->dispatch();
    }
}
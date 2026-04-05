<?php
namespace Core;

/**
 * Event Dispatcher
 * 
 * مدیریت رویدادها و شنوندگان
 */
class EventDispatcher
{
    private static $instance = null;
    private $listeners = [];

    /**
     * دریافت Instance (Singleton)
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    /**
     * ثبت Listener
     */
    public function listen($eventName, $listener, $priority = 0)
    {
        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }
        
        $this->listeners[$eventName][] = [
            'listener' => $listener,
            'priority' => $priority
        ];
        
        // مرتب‌سازی بر اساس اولویت
        usort($this->listeners[$eventName], function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
    }

    /**
     * ارسال رویداد
     */
    public function dispatch($eventName, $event = null)
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }
        
        // اگر Event شیء نبود، آن را به آرایه تبدیل کن
        if (!$event instanceof Event) {
            $event = new GenericEvent($event);
        }
        
        foreach ($this->listeners[$eventName] as $item) {
            $listener = $item['listener'];
            
            // اجرای Listener
            if (is_callable($listener)) {
                $listener($event);
            } elseif (is_string($listener) && class_exists($listener)) {
                $listenerInstance = new $listener();
                if (method_exists($listenerInstance, 'handle')) {
                    $listenerInstance->handle($event);
                }
            }
            
            // بررسی توقف انتشار
            if ($event->isPropagationStopped()) {
                break;
            }
        }
        
        // لاگ رویداد
        logger('event', "Event dispatched: {$eventName}", [
            'data' => $event->getData()
        ]);
    }

    /**
     * حذف Listener
     */
    public function forget($eventName)
    {
        unset($this->listeners[$eventName]);
    }

    /**
     * دریافت تمام Listeners
     */
    public function getListeners($eventName = null)
    {
        if ($eventName === null) {
            return $this->listeners;
        }
        
        return $this->listeners[$eventName] ?? [];
    }

    /**
     * جلوگیری از Clone
     */
    private function __clone() {}

    /**
     * جلوگیری از Unserialize
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}

/**
 * Generic Event (برای رویدادهای ساده)
 */
class GenericEvent extends Event
{
    // فقط از کلاس پایه استفاده می‌کند
}
<?php
namespace Core;

/**
 * Schema Builder
 * 
 * ساخت و مدیریت ساختار جداول
 */
class Schema
{
    private static $db;

    /**
     * ایجاد جدول
     */
    public static function create($table, callable $callback)
    {
        self::$db = Database::getInstance();
        
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $sql = $blueprint->toSql('create');
        
        try {
            self::$db->query($sql);
            logger('info', "Table created: {$table}");
        } catch (\Exception $e) {
            logger('error', "Failed to create table {$table}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * بررسی وجود جدول
     */
    public static function hasTable($table)
    {
        self::$db = Database::getInstance();
        
        $sql = "SHOW TABLES LIKE ?";
        $result = self::$db->select($sql, [$table]);
        
        return !empty($result);
    }

    /**
     * حذف جدول
     */
    public static function drop($table)
    {
        self::$db = Database::getInstance();
        
        $sql = "DROP TABLE IF EXISTS {$table}";
        
        try {
            self::$db->query($sql);
            logger('info', "Table dropped: {$table}");
        } catch (\Exception $e) {
            logger('error', "Failed to drop table {$table}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ویرایش جدول
     */
    public static function table($table, callable $callback)
    {
        self::$db = Database::getInstance();
        
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $sql = $blueprint->toSql('alter');
        
        try {
            self::$db->query($sql);
            logger('info', "Table altered: {$table}");
        } catch (\Exception $e) {
            logger('error', "Failed to alter table {$table}: " . $e->getMessage());
            throw $e;
        }
    }
}
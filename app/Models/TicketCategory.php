<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class TicketCategory extends Model {
/**
     * دریافت همه دسته‌ها
     */
    public function getAll(): array
    {
        $sql = "SELECT * FROM ticket_categories 
                WHERE is_active = TRUE 
                ORDER BY display_order ASC";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * دریافت با ID
     */
    public function findById(int $id): ?object
    {
        $sql = "SELECT * FROM ticket_categories WHERE id = ?";
        return $this->db->fetch($sql, [$id]);
    }
}
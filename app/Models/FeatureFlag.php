<?php

namespace App\Models;
use Core\Model;
use Core\Database;

class FeatureFlag extends Model 
{
    private static array $cachedFeatures = [];
    private static bool $loaded = false;
    private array $decodedCache = [];
    private static array $userRoleCache = [];
    
    private const ALLOWED_UPDATE_FIELDS = [
        'enabled',
        'description',
        'enabled_percentage',
        'enabled_for_roles',
        'enabled_for_users',
        'metadata'
    ];
    
    private function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }
        
        $sql = "SELECT * FROM feature_flags ORDER BY name ASC";
        $features = $this->db->fetchAll($sql);
        
        foreach ($features as $feature) {
            self::$cachedFeatures[$feature->name] = $feature;
        }
        
        self::$loaded = true;
    }
    
    private function clearCache(): void
    {
        self::$cachedFeatures = [];
        self::$loaded = false;
        $this->decodedCache = [];
    }
    
    public function getAll(): array
    {
        $this->loadAll();
        return array_values(self::$cachedFeatures);
    }
    
    public function findByName(string $name): ?object
    {
        $this->loadAll();
        return self::$cachedFeatures[$name] ?? null;
    }
    
    private function getDecodedRoles(object $feature): array
    {
        $cacheKey = "roles_{$feature->name}";
        
        if (!isset($this->decodedCache[$cacheKey])) {
            $this->decodedCache[$cacheKey] = $feature->enabled_for_roles 
                ? json_decode($feature->enabled_for_roles, true) ?? []
                : [];
        }
        
        return $this->decodedCache[$cacheKey];
    }
    
    private function getDecodedUsers(object $feature): array
    {
        $cacheKey = "users_{$feature->name}";
        
        if (!isset($this->decodedCache[$cacheKey])) {
            $this->decodedCache[$cacheKey] = $feature->enabled_for_users 
                ? json_decode($feature->enabled_for_users, true) ?? []
                : [];
        }
        
        return $this->decodedCache[$cacheKey];
    }
    
    private function getUserRole(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        
        if (!isset(self::$userRoleCache[$userId])) {
            $user = $this->db->fetch("SELECT role FROM users WHERE id = ?", [$userId]);
            self::$userRoleCache[$userId] = $user?->role;
        }
        
        return self::$userRoleCache[$userId];
    }
    
    public function isEnabled(string $name, ?int $userId = null, ?string $role = null): bool
    {
        $feature = $this->findByName($name);
        
        if (!$feature) {
            error_log("Feature flag '$name' not found in database");
            return false;
        }
        
        if (!$feature->enabled) {
            return false;
        }
        
        $allowedRoles = $this->getDecodedRoles($feature);
        if (!empty($allowedRoles)) {
            if ($role === null) {
                $role = $this->getUserRole($userId);
            }
            
            if (!$role || !in_array($role, $allowedRoles, true)) {
                return false;
            }
        }
        
        $allowedUsers = $this->getDecodedUsers($feature);
        if (!empty($allowedUsers)) {
            if (!$userId || !in_array($userId, $allowedUsers, true)) {
                return false;
            }
        }
        
        if ($feature->enabled_percentage < 100) {
            if ($userId) {
                $hash = crc32($userId . $name);
                $userPercentage = ($hash % 100) + 1;
                
                if ($userPercentage > $feature->enabled_percentage) {
                    return false;
                }
            } else {
                if (rand(1, 100) > $feature->enabled_percentage) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    public function isEnabledForUser(string $name, ?int $userId = null): bool
    {
        return $this->isEnabled($name, $userId);
    }
    
    public function toggle(string $name): bool
    {
        $feature = $this->findByName($name);
        
        if (!$feature) {
            return false;
        }
        
        $newStatus = !$feature->enabled;
        
        $sql = "UPDATE feature_flags SET enabled = ?, updated_at = NOW() WHERE name = ?";
        $result = $this->db->query($sql, [$newStatus ? 1 : 0, $name]);
        
        if ($result) {
            $this->clearCache();
        }
        
        return $result;
    }
    
    public function update(string $name, array $data): bool
    {
        $fields = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            if (!in_array($key, self::ALLOWED_UPDATE_FIELDS, true)) {
                throw new \InvalidArgumentException("Invalid field for update: $key");
            }
            
            if (in_array($key, ['enabled_for_roles', 'enabled_for_users', 'metadata'])) {
                $value = json_encode($value);
            }
            
            if ($key === 'enabled_percentage') {
                $value = max(0, min(100, (int)$value));
            }
            
            if ($key === 'enabled') {
                $value = $value ? 1 : 0;
            }
            
            $fields[] = "$key = ?";
            $params[] = $value;
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = NOW()";
        $params[] = $name;
        
        $sql = "UPDATE feature_flags SET " . implode(', ', $fields) . " WHERE name = ?";
        
        $result = $this->db->query($sql, $params);
        
        if ($result) {
            $this->clearCache();
        }
        
        return $result;
    }
    
    public function create(array $data): bool
    {
        $required = ['name', 'description'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Field '$field' is required");
            }
        }
        
        if ($this->findByName($data['name'])) {
            throw new \InvalidArgumentException("Feature '{$data['name']}' already exists");
        }
        
        $defaults = [
            'enabled' => false,
            'enabled_percentage' => 100,
            'enabled_for_roles' => null,
            'enabled_for_users' => null,
            'metadata' => null
        ];
        
        $data = array_merge($defaults, $data);
        
        foreach (['enabled_for_roles', 'enabled_for_users', 'metadata'] as $field) {
            if (is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }
        
        $sql = "INSERT INTO feature_flags 
                (name, description, enabled, enabled_percentage, enabled_for_roles, enabled_for_users, metadata, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $result = $this->db->query($sql, [
            $data['name'],
            $data['description'],
            $data['enabled'] ? 1 : 0,
            $data['enabled_percentage'],
            $data['enabled_for_roles'],
            $data['enabled_for_users'],
            $data['metadata']
        ]);
        
        if ($result) {
            $this->clearCache();
        }
        
        return $result;
    }
    
    public function delete(string $name): bool
    {
        $sql = "DELETE FROM feature_flags WHERE name = ?";
        $result = $this->db->query($sql, [$name]);
        
        if ($result) {
            $this->clearCache();
        }
        
        return $result;
    }
    
    public function getStats(): array
    {
        $all = $this->getAll();
        
        $stats = [
            'total' => count($all),
            'enabled' => 0,
            'disabled' => 0,
            'role_restricted' => 0,
            'user_restricted' => 0,
            'percentage_based' => 0
        ];
        
        foreach ($all as $feature) {
            if ($feature->enabled) {
                $stats['enabled']++;
            } else {
                $stats['disabled']++;
            }
            
            if ($feature->enabled_for_roles) {
                $stats['role_restricted']++;
            }
            
            if ($feature->enabled_for_users) {
                $stats['user_restricted']++;
            }
            
            if ($feature->enabled_percentage < 100) {
                $stats['percentage_based']++;
            }
        }
        
        return $stats;
    }
}

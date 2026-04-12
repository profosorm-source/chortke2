<?php

namespace App\Services;

use App\Models\FeatureFlag;

class FeatureFlagService
{
    private \Core\Database $db;
    private FeatureFlag $featureModel;
    
    public function __construct(
        \App\Models\FeatureFlag $featureModel,
        \Core\Database $db)
    {
        $this->featureModel = $featureModel;
        $this->db = $db;
    }
    
    public function isEnabled(string $name, ?int $userId = null): bool
    {
        $role = null;
        
        if ($userId) {
            $userSql = "SELECT role FROM users WHERE id = ?";
            $user = $this->db->fetch($userSql, [$userId]);
            $role = $user ? $user->role : null;
        }
        
        return $this->featureModel->isEnabled($name, $userId, $role);
    }
    
    public function areEnabled(array $names, ?int $userId = null): bool
    {
        foreach ($names as $name) {
            if (!$this->isEnabled($name, $userId)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function getEnabled(?int $userId = null): array
    {
        $all = $this->featureModel->getAll();
        $enabled = [];
        
        foreach ($all as $feature) {
            if ($this->isEnabled($feature->name, $userId)) {
                $enabled[] = $feature->name;
            }
        }
        
        return $enabled;
    }
}

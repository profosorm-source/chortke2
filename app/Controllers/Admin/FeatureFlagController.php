<?php

namespace App\Controllers\Admin;

use App\Models\FeatureFlag;
use App\Controllers\Admin\BaseAdminController;

class FeatureFlagController extends BaseAdminController
{
    private FeatureFlag $featureModel;
    
    public function __construct(\App\Models\FeatureFlag $featureModel)
    {
        parent::__construct();
        $this->featureModel = $featureModel;
    }
    
    public function index()
    {
        try {
            $features = $this->featureModel->getAll();
            
            return view('admin/features/index', [
                'features' => $features
            ]);
        } catch (\Exception $e) {
            log_error('FeatureFlag index error', ['error' => $e->getMessage()]);
            
            return view('admin/features/index', [
                'features' => [],
                'error' => 'خطا در دریافت لیست فیچرها'
            ]);
        }
    }
    
    public function toggle()
    {
        try {
            $data = $this->request->json();
            $name = $data['name'] ?? '';
            
            if (!$name) {
                return $this->response->json([
                    'success' => false, 
                    'message' => 'نام فیچر الزامی است.'
                ], 400);
            }
            
            $feature = $this->featureModel->findByName($name);
            if (!$feature) {
                return $this->response->json([
                    'success' => false,
                    'message' => 'فیچر مورد نظر یافت نشد.'
                ], 404);
            }
            
            if ($this->featureModel->toggle($name)) {
                $newStatus = !$feature->enabled ? 'فعال' : 'غیرفعال';
                
                log_activity(
                    user_id(), 
                    'feature_toggled', 
                    "فیچر {$name} {$newStatus} شد"
                );
                
                return $this->response->json([
                    'success' => true,
                    'message' => "وضعیت فیچر به {$newStatus} تغییر کرد."
                ]);
            }
            
            return $this->response->json([
                'success' => false, 
                'message' => 'خطا در تغییر وضعیت.'
            ], 500);
            
        } catch (\Exception $e) {
            log_error('FeatureFlag toggle error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->response->json([
                'success' => false,
                'message' => 'خطای سرور: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function update()
    {
        try {
            $data = $this->request->json();
            $name = $data['name'] ?? '';
            
            if (!$name) {
                return $this->response->json([
                    'success' => false,
                    'message' => 'نام فیچر الزامی است.'
                ], 400);
            }
            
            $feature = $this->featureModel->findByName($name);
            if (!$feature) {
                return $this->response->json([
                    'success' => false,
                    'message' => 'فیچر مورد نظر یافت نشد.'
                ], 404);
            }
            
            $updateData = [];
            
            if (isset($data['description'])) {
                $updateData['description'] = trim($data['description']);
            }
            
            if (isset($data['enabled_percentage'])) {
                $percentage = (int) $data['enabled_percentage'];
                
                if ($percentage < 0 || $percentage > 100) {
                    return $this->response->json([
                        'success' => false,
                        'message' => 'درصد فعال‌سازی باید بین 0 تا 100 باشد.'
                    ], 400);
                }
                
                $updateData['enabled_percentage'] = $percentage;
            }
            
            if (isset($data['enabled_for_roles'])) {
                $roles = $data['enabled_for_roles'];
                
                if (!is_array($roles)) {
                    return $this->response->json([
                        'success' => false,
                        'message' => 'فرمت نقش‌ها نامعتبر است.'
                    ], 400);
                }
                
                $roles = array_filter(array_map('trim', $roles));
                $updateData['enabled_for_roles'] = $roles;
            }
            
            if (isset($data['enabled_for_users'])) {
                $users = $data['enabled_for_users'];
                
                if (!is_array($users)) {
                    return $this->response->json([
                        'success' => false,
                        'message' => 'فرمت کاربران نامعتبر است.'
                    ], 400);
                }
                
                $users = array_filter(array_map('intval', $users));
                $updateData['enabled_for_users'] = $users;
            }
            
            if (empty($updateData)) {
                return $this->response->json([
                    'success' => false,
                    'message' => 'هیچ تغییری برای ذخیره وجود ندارد.'
                ], 400);
            }
            
            if ($this->featureModel->update($name, $updateData)) {
                log_activity(
                    user_id(),
                    'feature_updated',
                    "تنظیمات پیشرفته فیچر {$name} به‌روزرسانی شد",
                    ['updated_fields' => array_keys($updateData)]
                );
                
                return $this->response->json([
                    'success' => true,
                    'message' => 'تنظیمات فیچر با موفقیت ذخیره شد.'
                ]);
            }
            
            return $this->response->json([
                'success' => false,
                'message' => 'خطا در ذخیره تنظیمات.'
            ], 500);
            
        } catch (\InvalidArgumentException $e) {
            return $this->response->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
            
        } catch (\Exception $e) {
            log_error('FeatureFlag update error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->response->json([
                'success' => false,
                'message' => 'خطای سرور: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function create()
    {
        try {
            $data = $this->request->json();
            
            $required = ['name', 'description'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return $this->response->json([
                        'success' => false,
                        'message' => "فیلد {$field} الزامی است."
                    ], 400);
                }
            }
            
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $data['name'])) {
                return $this->response->json([
                    'success' => false,
                    'message' => 'نام فیچر فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد.'
                ], 400);
            }
            
            if ($this->featureModel->create($data)) {
                log_activity(
                    user_id(),
                    'feature_created',
                    "فیچر جدید {$data['name']} ایجاد شد"
                );
                
                return $this->response->json([
                    'success' => true,
                    'message' => 'فیچر با موفقیت ایجاد شد.'
                ]);
            }
            
            return $this->response->json([
                'success' => false,
                'message' => 'خطا در ایجاد فیچر.'
            ], 500);
            
        } catch (\InvalidArgumentException $e) {
            return $this->response->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
            
        } catch (\Exception $e) {
            log_error('FeatureFlag create error', [
                'error' => $e->getMessage()
            ]);
            
            return $this->response->json([
                'success' => false,
                'message' => 'خطای سرور: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function delete()
    {
        try {
            $data = $this->request->json();
            $name = $data['name'] ?? '';
            
            if (!$name) {
                return $this->response->json([
                    'success' => false,
                    'message' => 'نام فیچر الزامی است.'
                ], 400);
            }
            
            if ($this->featureModel->delete($name)) {
                log_activity(
                    user_id(),
                    'feature_deleted',
                    "فیچر {$name} حذف شد"
                );
                
                return $this->response->json([
                    'success' => true,
                    'message' => 'فیچر با موفقیت حذف شد.'
                ]);
            }
            
            return $this->response->json([
                'success' => false,
                'message' => 'خطا در حذف فیچر.'
            ], 500);
            
        } catch (\Exception $e) {
            log_error('FeatureFlag delete error', [
                'error' => $e->getMessage()
            ]);
            
            return $this->response->json([
                'success' => false,
                'message' => 'خطای سرور: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function stats()
    {
        try {
            $stats = $this->featureModel->getStats();
            
            return $this->response->json([
                'success' => true,
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            log_error('FeatureFlag stats error', [
                'error' => $e->getMessage()
            ]);
            
            return $this->response->json([
                'success' => false,
                'message' => 'خطا در دریافت آمار.'
            ], 500);
        }
    }
}

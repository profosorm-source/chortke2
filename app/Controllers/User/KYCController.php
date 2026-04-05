<?php

namespace App\Controllers\User;

use App\Models\KYCVerification;
use App\Services\KYCService;
use Core\Validator;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class KYCController extends BaseUserController
{
    private KYCService $kycService;
    private KYCVerification $kycModel;

    public function __construct(
        KYCVerification $kycModel,
        KYCService $kycService)
    {
        parent::__construct();
        $this->kycModel   = $kycModel;
        $this->kycService = $kycService;
    }

    /**
     * نمایش صفحه احراز هویت
     */
    public function index()
{
    if (!auth() || !user_id()) {
        redirect(url('/login'));
        return;
    }

    $userId = (int) user_id();

    
	$kyc = $this->kycModel->findByUserId($userId);

    return view('user/kyc/index', [
        'title' => 'احراز هویت',
        'kyc' => $kyc,
    ]);
}

    /**
     * نمایش فرم آپلود مدارک
     */
    public function upload(): void
    {
        $userId    = user_id();
        $canSubmit = $this->kycService->canSubmitKYC($userId);

        if (!$canSubmit['can']) {
            view('user/kyc/upload', [
                'error'     => $canSubmit['reason'],
                'canSubmit' => false
            ]);
            return;
        }

        view('user/kyc/upload', [
            'canSubmit' => true
        ]);
    }

    /**
     * ثبت درخواست احراز هویت
     */
    public function submit(): void
{
        
    $userId = user_id();
        ApiRateLimiter::enforce('kyc_submit', (int)user_id(), is_ajax());
    $data   = $this->request->all();

    // 1. اعتبارسنجی داده‌ها
    $validator = new Validator($data, [
        'national_code' => 'required|digits:10',
        'birth_date'    => 'required'
    ]);

    if ($validator->fails()) {
        $this->session->setFlash('errors', $validator->errors());
        redirect('/kyc/upload');
        return;
    }

    // 2. بررسی فایل آپلود شده
    if (
        empty($_FILES['verification_image']) ||
        $_FILES['verification_image']['error'] !== UPLOAD_ERR_OK
    ) {
        $this->session->setFlash('errors', [
            'verification_image' => ['تصویر احراز هویت الزامی است']
        ]);
        redirect('/kyc/upload');
        return;
    }

    // 3. ثبت درخواست KYC
    $result = $this->kycService->submitKYC(
        $userId,
        [
            'national_code' => trim($data['national_code']),
            'birth_date'    => $data['birth_date']
        ],
        $_FILES['verification_image']
    );

    // 4. نوتیف ادمین (فقط با Session، بدون user() یا مدل)
    if ($result['success']) {
        $user = $this->session->get('user'); // آرایه
        $userName = $user['full_name'] ?? 'کاربر';

        notify_admins(
            'kyc_submitted',
            'درخواست احراز هویت جدید',
            'کاربر ' . $userName . ' درخواست احراز هویت ثبت کرده است',
            url('/admin/kyc/review/' . $result['kyc_id']),
           ['user_id' => $userId, 'kyc_id' => $result['kyc_id']]
  );

        $this->session->setFlash('success', $result['message']);
        redirect('/kyc');
        return;
    }

    // 5. خطای ثبت KYC
    $this->session->setFlash('error', $result['message']);
    redirect('/kyc/upload');
}

    /**
     * نمایش وضعیت KYC
     */
    public function status(): void
    {
        $userId = user_id();
        $kyc    = $this->kycModel->findByUserId($userId);

        if (!$kyc) {
            redirect(url('/kyc'));
            return;
        }

        $statusLabels = [
            'pending'      => 'در انتظار بررسی',
            'under_review' => 'در حال بررسی',
            'verified'     => 'تأیید شده',
            'rejected'     => 'رد شده',
            'expired'      => 'منقضی شده'
        ];

        // اگر درخواست AJAX بود → JSON برگردون
        if (is_ajax()) {
            $this->response->json([
                'success' => true,
                'kyc'     => [
                    'status'           => $kyc->status,
                    'status_label'     => $statusLabels[$kyc->status] ?? $kyc->status,
                    'submitted_at'     => $kyc->submitted_at,
                    'verified_at'      => $kyc->verified_at,
                    'rejection_reason' => $kyc->rejection_reason,
                    'image'            => $kyc->verification_image
                ]
            ]);
            return;
        }

        // درخواست معمولی → نمایش view
        view('user/kyc/status', [
            'title'        => 'وضعیت احراز هویت',
            'kyc'          => $kyc,
            'status_label' => $statusLabels[$kyc->status] ?? $kyc->status,
        ]);
    }
}
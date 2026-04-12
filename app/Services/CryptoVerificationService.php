<?php

namespace App\Services;

use App\Models\CryptoDeposit;
use App\Models\Setting;
use Core\Database;
use Core\Logger;

class CryptoVerificationService
{
    private \App\Models\Setting $settingModel;
    private Database $db;
    private CryptoDeposit $depositModel;
    private WalletService $walletService;
    
    // آدرس‌های ولت سایت (از تنظیمات)
    private Logger $logger;
    private array $siteWallets = [];
    
    public function __construct(
        Database $db,
        Logger   $logger,
        WalletService $walletService,
        \App\Models\CryptoDeposit $depositModel,
        \App\Models\Setting $settingModel)
    {
        $this->db = $db;
        $this->depositModel = $depositModel;
        $this->walletService = $walletService;
        $this->settingModel = $settingModel;
        
        // بارگذاری ولت‌ها از تنظیمات
        $this->logger = $logger;
        $this->loadSiteWallets();
    }
    
    /**
     * بارگذاری آدرس ولت‌های سایت
     */
    private function loadSiteWallets(): void
    {
        $settings = $this->settingModel;
        
        $this->siteWallets = [
            'BNB20' => $settings->get('site_wallet_bnb20', ''),
            'TRC20' => $settings->get('site_wallet_trc20', ''),
            'ERC20' => $settings->get('site_wallet_erc20', ''),
            'TON' => $settings->get('site_wallet_ton', ''),
            'SOL' => $settings->get('site_wallet_sol', '')
        ];
    }
    
    /**
     * دریافت آدرس ولت سایت
     */
    public function getSiteWallet(string $network): ?string
    {
        return $this->siteWallets[$network] ?? null;
    }
    
    /**
     * دریافت تمام ولت‌های سایت
     */
    public function getAllSiteWallets(): array
    {
        return array_filter($this->siteWallets);
    }
    
    /**
     * تأیید خودکار تراکنش
     */
    public function autoVerify(int $depositId): array
    {
        try {
            $deposit = $this->depositModel->find($depositId);
            
            if (!$deposit) {
                return ['success' => false, 'message' => 'واریز یافت نشد'];
            }
            
            // دریافت اطلاعات تراکنش از بلاکچین
            $verification = $this->verifyTransaction(
                $deposit->network,
                $deposit->tx_hash,
                $deposit->to_wallet,
                $deposit->amount
            );
            
            if ($verification['success']) {
                // تأیید خودکار
                if ($verification['exact_match']) {
                    return $this->approveDeposit($depositId, null, true);
                } else {
                    // نیاز به بررسی دستی
                    $this->depositModel->update($depositId, [
                        'verification_status' => 'manual_review',
                        'verification_data' => \json_encode($verification['data'])
                    ]);
                    
                    return [
                        'success' => false,
                        'manual_review' => true,
                        'message' => 'تراکنش نیاز به بررسی دستی دارد',
                        'reason' => $verification['mismatch_reason']
                    ];
                }
            } else {
                // رد خودکار
                $this->depositModel->update($depositId, [
                    'verification_status' => 'rejected',
                    'admin_note' => $verification['message'],
                    'verification_data' => \json_encode($verification)
                ]);
                
                return $verification;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('crypto.auto_verify.failed', [
                'deposit_id' => $depositId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'خطا در تأیید خودکار'
            ];
        }
    }
    
    /**
     * بررسی تراکنش در بلاکچین
     */
    private function verifyTransaction(string $network, string $txHash, string $toWallet, float $amount): array
    {
        try {
            switch ($network) {
                case 'TRC20':
                    return $this->verifyTronTransaction($txHash, $toWallet, $amount);
                
                case 'BNB20':
                    return $this->verifyBscTransaction($txHash, $toWallet, $amount);
                
                case 'ERC20':
                    return $this->verifyEthereumTransaction($txHash, $toWallet, $amount);
                
                case 'TON':
                    return $this->verifyTonTransaction($txHash, $toWallet, $amount);
                
                case 'SOL':
                    return $this->verifySolanaTransaction($txHash, $toWallet, $amount);
                
                default:
                    return [
                        'success' => false,
                        'message' => 'شبکه پشتیبانی نمی‌شود'
                    ];
            }
            
        } catch (\Exception $e) {
            $this->logger->error('crypto.tx_verify.failed', [
                'network' => $network,
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'خطا در اتصال به بلاکچین'
            ];
        }
    }
    
    /**
     * تأیید تراکنش Tron (TRC20)
     */
    private function verifyTronTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        try {
            // API TronScan
            $apiUrl = "https://apilist.tronscan.org/api/transaction-info?hash={$txHash}";
            
            $ch = \curl_init($apiUrl);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);
            
            if ($httpCode !== 200) {
                return [
                    'success' => false,
                    'message' => 'تراکنش در شبکه یافت نشد'
                ];
            }
            
            $data = \json_decode($response, true);
            
            if (!isset($data['contractRet']) || $data['contractRet'] !== 'SUCCESS') {
                return [
                    'success' => false,
                    'message' => 'تراکنش ناموفق است'
                ];
            }
            
            // استخراج اطلاعات
            $tokenTransfers = $data['tokenTransferInfo'] ?? [];
            
            if (empty($tokenTransfers)) {
                return [
                    'success' => false,
                    'message' => 'تراکنش USDT یافت نشد'
                ];
            }
            
            $transfer = $tokenTransfers[0];
            
            // تبدیل آدرس‌ها
            $actualTo = $transfer['to_address'] ?? '';
            $actualFrom = $transfer['from_address'] ?? '';
            $actualAmount = ($transfer['amount_str'] ?? 0) / 1000000; // USDT has 6 decimals
            $timestamp = ($data['timestamp'] ?? 0) / 1000;
            
            // بررسی مطابقت
            $matches = [
                'to_wallet' => strtolower($actualTo) === strtolower($toWallet),
                'amount' => abs($actualAmount - $expectedAmount) < 0.01, // تلرانس 0.01 USDT
                'token' => ($transfer['token_name'] ?? '') === 'Tether USD'
            ];
            
            $exactMatch = $matches['to_wallet'] && $matches['amount'] && $matches['token'];
            
            $verificationData = [
                'tx_hash' => $txHash,
                'network' => 'TRC20',
                'from_wallet' => $actualFrom,
                'to_wallet' => $actualTo,
                'amount' => $actualAmount,
                'expected_amount' => $expectedAmount,
                'timestamp' => $timestamp,
                'date' => date('Y-m-d H:i:s', $timestamp),
                'confirmations' => $data['confirmed'] ?? false,
                'matches' => $matches
            ];
            
            if ($exactMatch) {
                return [
                    'success' => true,
                    'exact_match' => true,
                    'data' => $verificationData
                ];
            } else {
                $mismatches = [];
                if (!$matches['to_wallet']) $mismatches[] = 'آدرس گیرنده';
                if (!$matches['amount']) $mismatches[] = 'مبلغ';
                if (!$matches['token']) $mismatches[] = 'نوع توکن';
                
                return [
                    'success' => true,
                    'exact_match' => false,
                    'mismatch_reason' => implode('، ', $mismatches) . ' مطابقت ندارد',
                    'data' => $verificationData
                ];
            }
            
        } catch (\Exception $e) {
            $this->logger->error('crypto.tronscan.error', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'خطا در اتصال به TronScan'
            ];
        }
    }
    
    /**
     * تأیید تراکنش BSC (BNB20)
     */
    private function verifyBscTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        try {
            // API BscScan
            $apiKey = env('BSCSCAN_API_KEY', '');
            $apiUrl = "https://api.bscscan.com/api?module=proxy&action=eth_getTransactionByHash&txhash={$txHash}&apikey={$apiKey}";
            
            $ch = \curl_init($apiUrl);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);
            
            if ($httpCode !== 200) {
                return [
                    'success' => false,
                    'message' => 'تراکنش در شبکه یافت نشد'
                ];
            }
            
            $data = \json_decode($response, true);
            
            if (!isset($data['result'])) {
                return [
                    'success' => false,
                    'message' => 'تراکنش یافت نشد'
                ];
            }
            
            $tx = $data['result'];
            
            // دریافت Receipt برای بررسی موفقیت
            $receiptUrl = "https://api.bscscan.com/api?module=proxy&action=eth_getTransactionReceipt&txhash={$txHash}&apikey={$apiKey}";
            
            $ch = \curl_init($receiptUrl);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $receiptResponse = \curl_exec($ch);
            \curl_close($ch);
            
            $receiptData = \json_decode($receiptResponse, true);
            
            if (!isset($receiptData['result']['status']) || $receiptData['result']['status'] !== '0x1') {
                return [
                    'success' => false,
                    'message' => 'تراکنش ناموفق است'
                ];
            }
            
            // پارس کردن input data برای استخراج مبلغ و آدرس
            // BEP20 Transfer: 0xa9059cbb + address(32 bytes) + amount(32 bytes)
            $input = $tx['input'] ?? '';
            
            if (strlen($input) < 138) {
                return [
                    'success' => false,
                    'message' => 'داده تراکنش نامعتبر است'
                ];
            }
            
            // استخراج آدرس گیرنده
            $toAddress = '0x' . substr($input, 34, 40);
            
            // استخراج مبلغ (18 decimals for USDT on BSC)
            $amountHex = substr($input, 74, 64);
            $actualAmount = hexdec($amountHex) / (10 ** 18);
            
            $matches = [
                'to_wallet' => strtolower($toAddress) === strtolower($toWallet),
                'amount' => abs($actualAmount - $expectedAmount) < 0.01
            ];
            
            $exactMatch = $matches['to_wallet'] && $matches['amount'];
            
            $verificationData = [
                'tx_hash' => $txHash,
                'network' => 'BNB20',
                'from_wallet' => $tx['from'] ?? '',
                'to_wallet' => $toAddress,
                'amount' => $actualAmount,
                'expected_amount' => $expectedAmount,
                'block_number' => hexdec($tx['blockNumber'] ?? '0'),
                'matches' => $matches
            ];
            
            if ($exactMatch) {
                return [
                    'success' => true,
                    'exact_match' => true,
                    'data' => $verificationData
                ];
            } else {
                $mismatches = [];
                if (!$matches['to_wallet']) $mismatches[] = 'آدرس گیرنده';
                if (!$matches['amount']) $mismatches[] = 'مبلغ';
                
                return [
                    'success' => true,
                    'exact_match' => false,
                    'mismatch_reason' => implode('، ', $mismatches) . ' مطابقت ندارد',
                    'data' => $verificationData
                ];
            }
            
        } catch (\Exception $e) {
            $this->logger->error('crypto.bscscan.error', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'خطا در اتصال به BscScan'
            ];
        }
    }
    
    /**
     * تأیید تراکنش Ethereum (ERC20)
     */
    private function verifyEthereumTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        // مشابه BscScan ولی با Etherscan API
        return [
            'success' => false,
            'message' => 'تأیید ERC20 در نسخه بعدی پیاده‌سازی می‌شود'
        ];
    }
    
    /**
     * تأیید تراکنش TON
     */
    private function verifyTonTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        return [
            'success' => false,
            'message' => 'تأیید TON در نسخه بعدی پیاده‌سازی می‌شود'
        ];
    }
    
    /**
     * تأیید تراکنش Solana
     */
    private function verifySolanaTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        return [
            'success' => false,
            'message' => 'تأیید Solana در نسخه بعدی پیاده‌سازی می‌شود'
        ];
    }
   /**
 * تأیید واریز (دستی یا خودکار)
 * 
 * فایل: app/Services/CryptoVerificationService.php
 */
public function approveDeposit(int $depositId, ?int $adminId = null, bool $auto = false): array
{
    try {
        $this->db->beginTransaction();

        $deposit = $this->depositModel->find($depositId);

        if (!$deposit) {
            return ['success' => false, 'message' => 'واریز یافت نشد'];
        }

        if (in_array($deposit->verification_status, ['auto_verified', 'approved'])) {
            return ['success' => false, 'message' => 'این واریز قبلاً تأیید شده است'];
        }

        // ✅ اصلاح شد: ترتیب صحیح آرگومان‌ها
        // deposit(userId, amount, currency, metadata)
        $walletDeposit = $this->walletService->deposit(
            (int) $deposit->user_id,     // 1. userId
            (float) $deposit->amount,    // 2. amount
            'usdt',                      // 3. currency (lowercase)
            [                            // 4. metadata
                'type'         => 'crypto_deposit',
                'deposit_id'   => $depositId,
                'network'      => $deposit->network,
                'tx_hash'      => $deposit->tx_hash,
                'auto_verified' => $auto,
                'approved_by'  => $adminId,
                'description'  => 'واریز کریپتو ' . $deposit->network . ' - ' . substr($deposit->tx_hash, 0, 10) . '...'
            ]
        );

        // ✅ اصلاح شد: چک صحیح return type
        if (!$walletDeposit['success']) {
            throw new \Exception($walletDeposit['message'] ?? 'خطا در واریز به کیف پول');
        }

        // بروزرسانی وضعیت
        $this->depositModel->update($depositId, [
            'verification_status' => $auto ? 'auto_verified' : 'approved',
            'reviewed_by'         => $adminId,
            'reviewed_at'         => date('Y-m-d H:i:s'),
            'transaction_id'      => $walletDeposit['transaction_id'] // ✅ اضافه شد
        ]);

        $this->db->commit();

        $this->logger->info('crypto.deposit.approved', [
            'deposit_id'     => $depositId,
            'user_id'        => $deposit->user_id,
            'amount'         => $deposit->amount,
            'network'        => $deposit->network,
            'auto'           => $auto,
            'admin_id'       => $adminId,
            'transaction_id' => $walletDeposit['transaction_id']
        ]);

        return [
            'success' => true,
            'message' => 'واریز تأیید شد و موجودی کاربر شارژ گردید'
        ];

    } catch (\Exception $e) {
        $this->db->rollBack();

        $this->logger->error('crypto.deposit.approve_failed', [
            'deposit_id' => $depositId,
            'error'      => $e->getMessage()
        ]);

        return [
            'success' => false,
            'message' => 'خطا در تأیید واریز: ' . $e->getMessage()
        ];
    }
}
    
    /**
     * رد واریز
     */
    public function rejectDeposit(int $depositId, int $adminId, string $reason): array
    {
        try {
            $deposit = $this->depositModel->find($depositId);
            
            if (!$deposit) {
                return ['success' => false, 'message' => 'واریز یافت نشد'];
            }
            
            $this->depositModel->update($depositId, [
                'verification_status' => 'rejected',
                'admin_note' => $reason,
                'reviewed_by' => $adminId,
                'reviewed_at' => date('Y-m-d H:i:s')
            ]);
            
            $this->logger->warning('crypto.deposit.rejected', [
                'deposit_id' => $depositId,
                'user_id' => $deposit->user_id,
                'admin_id' => $adminId,
                'reason' => $reason
            ]);
            
            return [
                'success' => true,
                'message' => 'واریز رد شد'
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('crypto.deposit.reject_failed', [
                'deposit_id' => $depositId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'خطا در رد واریز'
            ];
        }
    }
}
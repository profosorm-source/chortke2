<?php

namespace App\Services;

class CryptoExplorerBestEffortVerifier
{
    public function verify(string $network, string $txHash, string $from, string $to, float $expectedAmount): array
    {
        $url = $this->explorerUrl($network, $txHash);
        if ($url === '#') {
            return ['status' => 'unavailable', 'reason' => 'Explorer ناشناخته'];
        }

        $html = $this->fetch($url);
        if ($html === null) {
            return ['status' => 'unavailable', 'reason' => 'عدم دسترسی/تحریم/کلادفلر'];
        }

        if (stripos($html, 'Just a moment') !== false || stripos($html, 'cloudflare') !== false) {
            return ['status' => 'unavailable', 'reason' => 'محافظ ضدربات'];
        }

        // NOTE: در این نسخه چون استخراج دقیق از TronScan/BscScan بدون API تضمینی نیست،
        // اگر parser قابل اتکا نداشتیم => unavailable و مستقیم manual_review.
        // اگر در آینده امکان parse قابل اتکا فراهم شد، همینجا verified/mismatch می‌دهیم.
        return ['status' => 'unavailable', 'reason' => 'داده قابل استخراج نیست (SPA/JS)'];
    }

    private function explorerUrl(string $network, string $txHash): string
    {
        $map = [
            'TRC20' => 'https://tronscan.org/#/transaction/',
            'BNB20' => 'https://bscscan.com/tx/',
            'ERC20' => 'https://etherscan.io/tx/',
            'TON'   => 'https://tonscan.org/tx/',
            'SOL'   => 'https://explorer.solana.com/tx/',
        ];
        return ($map[$network] ?? '#') . $txHash;
    }

    private function fetch(string $url): ?string
    {
        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);

        $html = \curl_exec($ch);
        $http = (int)\curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($http !== 200 || !$html) return null;
        return $html;
    }
}
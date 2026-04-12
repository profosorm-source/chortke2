<?php

namespace App\Services\Sentry\Analytics;

use Core\Database;

/**
 * 📈 TrendAnalyzer - تحلیل روندها و پیش‌بینی
 * 
 * قابلیت‌ها:
 * - Trend Detection
 * - Anomaly Detection
 * - Forecasting
 * - Pattern Recognition
 */
class TrendAnalyzer
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * 📊 Analyze Trends
     */
    public function analyzeTrends(string $metric, int $days = 7): array
    {
        $data = $this->getHistoricalData($metric, $days);
        
        return [
            'trend' => $this->calculateTrend($data),
            'anomalies' => $this->detectAnomalies($data),
            'forecast' => $this->forecast($data, 3), // 3 روز آینده
            'patterns' => $this->detectPatterns($data),
        ];
    }

    /**
     * 📉 Calculate Trend
     */
    private function calculateTrend(array $data): array
    {
        if (count($data) < 2) {
            return ['direction' => 'stable', 'strength' => 0];
        }

        $n = count($data);
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;

        foreach ($data as $i => $point) {
            $x = $i;
            $y = $point['value'];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        // Linear regression: y = mx + b
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        
        return [
            'direction' => $slope > 0.1 ? 'increasing' : ($slope < -0.1 ? 'decreasing' : 'stable'),
            'strength' => abs($slope),
            'slope' => $slope,
        ];
    }

    /**
     * 🔍 Detect Anomalies
     */
    private function detectAnomalies(array $data): array
    {
        if (count($data) < 3) {
            return [];
        }

        $values = array_column($data, 'value');
        $mean = array_sum($values) / count($values);
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        $stdDev = sqrt($variance / count($values));
        $threshold = 2; // 2 standard deviations

        $anomalies = [];
        foreach ($data as $point) {
            $zScore = abs(($point['value'] - $mean) / $stdDev);
            if ($zScore > $threshold) {
                $anomalies[] = [
                    'timestamp' => $point['timestamp'],
                    'value' => $point['value'],
                    'z_score' => round($zScore, 2),
                    'severity' => $zScore > 3 ? 'high' : 'medium',
                ];
            }
        }

        return $anomalies;
    }

    /**
     * 🔮 Forecast
     */
    private function forecast(array $data, int $periods): array
    {
        if (count($data) < 3) {
            return [];
        }

        // Simple moving average forecast
        $windowSize = min(7, count($data));
        $recentValues = array_slice(array_column($data, 'value'), -$windowSize);
        $average = array_sum($recentValues) / count($recentValues);

        // Trend-adjusted forecast
        $trend = $this->calculateTrend($data);
        $forecasts = [];

        $lastTimestamp = strtotime($data[count($data) - 1]['timestamp']);

        for ($i = 1; $i <= $periods; $i++) {
            $forecastValue = $average + ($trend['slope'] * $i);
            $forecasts[] = [
                'timestamp' => date('Y-m-d H:i:s', strtotime("+{$i} day", $lastTimestamp)),
                'value' => max(0, round($forecastValue, 2)),
                'confidence' => $this->calculateConfidence($data),
            ];
        }

        return $forecasts;
    }

    /**
     * 🔄 Detect Patterns
     */
    private function detectPatterns(array $data): array
    {
        $patterns = [];

        // Spike Detection
        $spikes = $this->detectSpikes($data);
        if (!empty($spikes)) {
            $patterns[] = [
                'type' => 'spike',
                'description' => count($spikes) . ' spike(s) detected',
                'occurrences' => $spikes,
            ];
        }

        // Cyclical Pattern
        $cyclical = $this->detectCyclicalPattern($data);
        if ($cyclical) {
            $patterns[] = [
                'type' => 'cyclical',
                'description' => 'Cyclical pattern detected',
                'period' => $cyclical['period'],
            ];
        }

        // Gradual Increase/Decrease
        $trend = $this->calculateTrend($data);
        if ($trend['direction'] !== 'stable') {
            $patterns[] = [
                'type' => 'gradual_' . $trend['direction'],
                'description' => "Gradual {$trend['direction']}",
                'strength' => $trend['strength'],
            ];
        }

        return $patterns;
    }

    /**
     * ⚡ Detect Spikes
     */
    private function detectSpikes(array $data): array
    {
        if (count($data) < 3) {
            return [];
        }

        $spikes = [];
        for ($i = 1; $i < count($data) - 1; $i++) {
            $prev = $data[$i - 1]['value'];
            $current = $data[$i]['value'];
            $next = $data[$i + 1]['value'];

            // اگر مقدار فعلی 2 برابر قبل و بعد باشه
            if ($current > ($prev * 2) && $current > ($next * 2)) {
                $spikes[] = [
                    'timestamp' => $data[$i]['timestamp'],
                    'value' => $current,
                ];
            }
        }

        return $spikes;
    }

    /**
     * 🔁 Detect Cyclical Pattern
     */
    private function detectCyclicalPattern(array $data): ?array
    {
        if (count($data) < 14) { // حداقل 2 هفته
            return null;
        }

        // بررسی الگوی هفتگی
        $values = array_column($data, 'value');
        $period = 7;
        
        $correlation = 0;
        $count = 0;

        for ($i = 0; $i < count($values) - $period; $i++) {
            if (abs($values[$i] - $values[$i + $period]) < $values[$i] * 0.3) {
                $correlation++;
            }
            $count++;
        }

        $score = $count > 0 ? $correlation / $count : 0;

        return $score > 0.6 ? ['period' => $period, 'confidence' => $score] : null;
    }

    /**
     * 📊 Get Historical Data
     */
    private function getHistoricalData(string $metric, int $days): array
    {
        if ($metric === 'errors') {
            return $this->getErrorHistoricalData($days);
        } elseif ($metric === 'performance') {
            return $this->getPerformanceHistoricalData($days);
        }

        return [];
    }

    private function getErrorHistoricalData(int $days): array
    {
        $data = $this->db->query(
            "SELECT 
                DATE(created_at) as date,
                COUNT(*) as value
             FROM sentry_events
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$days]
        )->fetchAll(\PDO::FETCH_OBJ);

        return array_map(fn($item) => [
            'timestamp' => $item->date,
            'value' => (int)$item->value
        ], $data);
    }

    private function getPerformanceHistoricalData(int $days): array
    {
        $data = $this->db->query(
            "SELECT 
                DATE(created_at) as date,
                AVG(duration) as value
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$days]
        )->fetchAll(\PDO::FETCH_OBJ);

        return array_map(fn($item) => [
            'timestamp' => $item->date,
            'value' => round($item->value, 2)
        ], $data);
    }

    /**
     * 🎯 Calculate Confidence
     */
    private function calculateConfidence(array $data): float
    {
        // اعتماد بر اساس تعداد data pointها و variance
        $dataPoints = count($data);
        $values = array_column($data, 'value');
        
        if (count($values) < 2) {
            return 0.5;
        }

        $mean = array_sum($values) / count($values);
        $variance = 0;
        
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        $cv = $mean > 0 ? sqrt($variance / count($values)) / $mean : 1;
        
        // بیشتر data = اعتماد بیشتر
        // کمتر variance = اعتماد بیشتر
        $confidence = min(1, ($dataPoints / 30) * (1 - min(1, $cv)));
        
        return round($confidence, 2);
    }

    /**
     * 🔥 Get Hotspots (محل‌های داغ خطا)
     */
    public function getErrorHotspots(): array
    {
        return $this->db->query(
            "SELECT 
                JSON_EXTRACT(stack_trace, '$.frames[0].filename') as file,
                JSON_EXTRACT(stack_trace, '$.frames[0].line') as line,
                COUNT(*) as error_count,
                MAX(created_at) as last_occurrence
             FROM sentry_events
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             AND stack_trace IS NOT NULL
             GROUP BY file, line
             HAVING error_count > 5
             ORDER BY error_count DESC
             LIMIT 10"
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * 📉 Get Performance Degradation
     */
    public function getPerformanceDegradation(): array
    {
        // مقایسه این هفته با هفته قبل
        $thisWeek = $this->db->query(
            "SELECT AVG(duration) as avg FROM performance_transactions 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetch(\PDO::FETCH_OBJ);

        $lastWeek = $this->db->query(
            "SELECT AVG(duration) as avg FROM performance_transactions 
             WHERE created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) 
             AND DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetch(\PDO::FETCH_OBJ);

        $thisWeekAvg = $thisWeek->avg ?? 0;
        $lastWeekAvg = $lastWeek->avg ?? 0;

        $change = $lastWeekAvg > 0 
            ? (($thisWeekAvg - $lastWeekAvg) / $lastWeekAvg) * 100 
            : 0;

        return [
            'this_week_avg' => round($thisWeekAvg, 2),
            'last_week_avg' => round($lastWeekAvg, 2),
            'change_percent' => round($change, 2),
            'status' => $change > 10 ? 'degraded' : ($change < -10 ? 'improved' : 'stable'),
        ];
    }
}

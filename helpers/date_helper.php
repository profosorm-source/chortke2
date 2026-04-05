<?php

function today(): string
{
    return date('Y-m-d');
}

if (!function_exists('now')) {
    function now()
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('jdate')) {
    function jdate($datetime, $format = 'Y/m/d H:i')
    {
        if (
            is_string($datetime)
            && preg_match('/^[YmdHisAaDlNwWtzBuveOPTZ\/\-: ]+$/', $datetime)
            && (is_int($format) || (is_numeric($format) && (int)$format > 1000000000))
        ) {
            [$datetime, $format] = [$format, $datetime];
        }

        if (empty($datetime)) {
            return '-';
        }

        $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);

        return jalali_date($format, $timestamp);
    }
}

if (!function_exists('jalali_date')) {
    function jalali_date($format, $timestamp)
    {
        $g_y = date('Y', $timestamp);
        $g_m = date('n', $timestamp);
        $g_d = date('j', $timestamp);

        list($j_y, $j_m, $j_d) = gregorian_to_jalali($g_y, $g_m, $g_d);

        $replacements = [
            'Y' => $j_y,
            'm' => str_pad($j_m, 2, '0', STR_PAD_LEFT),
            'd' => str_pad($j_d, 2, '0', STR_PAD_LEFT),
            'H' => date('H', $timestamp),
            'i' => date('i', $timestamp),
            's' => date('s', $timestamp),
        ];

        return strtr($format, $replacements);
    }
}

if (!function_exists('gregorian_to_jalali')) {
    function gregorian_to_jalali($g_y, $g_m, $g_d)
    {
        $g_days_in_month = [31,28,31,30,31,30,31,31,30,31,30,31];
        $j_days_in_month = [31,31,31,31,31,31,30,30,30,30,30,29];

        $gy = $g_y - 1600;
        $gm = $g_m - 1;
        $gd = $g_d - 1;

        $g_day_no = 365*$gy + intdiv($gy+3,4) - intdiv($gy+99,100) + intdiv($gy+399,400);

        for ($i=0; $i < $gm; ++$i)
            $g_day_no += $g_days_in_month[$i];

        if ($gm > 1 && (($gy%4==0 && $gy%100!=0) || ($gy%400==0)))
            $g_day_no++;

        $g_day_no += $gd;

        $j_day_no = $g_day_no - 79;

        $j_np = intdiv($j_day_no, 12053);
        $j_day_no %= 12053;

        $jy = 979 + 33*$j_np + 4*intdiv($j_day_no,1461);

        $j_day_no %= 1461;

        if ($j_day_no >= 366) {
            $jy += intdiv($j_day_no-1,365);
            $j_day_no = ($j_day_no-1)%365;
        }

        for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i)
            $j_day_no -= $j_days_in_month[$i];

        $jm = $i + 1;
        $jd = $j_day_no + 1;

        return [$jy, $jm, $jd];
    }
}

function jtime(?string $mysqlDateTime, bool $seconds = false, bool $convertNumbers = true): string
{
    if (!$mysqlDateTime) return '-';

    $fmt = $seconds ? 'H:i:s' : 'H:i';
    $t = \date($fmt, \strtotime($mysqlDateTime));

    return $convertNumbers ? to_jalali($t, '', true) : $t;
}

if (!function_exists('to_jalali')) {
    function to_jalali($date, $format = 'Y/m/d', $persianNumbers = true)
    {
        if (empty($date)) return '';

        require_once __DIR__ . '/JalaliDate.php';

        $timestamp = is_numeric($date) ? $date : strtotime($date);
        $jalali = \Helpers\JalaliDate::format($format, $timestamp);

        if ($persianNumbers) {
            $englishNumbers = ['0','1','2','3','4','5','6','7','8','9'];
            $farsiNumbers   = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
            $jalali = str_replace($englishNumbers, $farsiNumbers, $jalali);
        }

        return $jalali;
    }
}

if (!function_exists('fa_number')) {
    function fa_number($value): string
    {
        $english = ['0','1','2','3','4','5','6','7','8','9'];
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];

        return str_replace($english, $persian, (string)$value);
    }
}

function fa_digits(string $value): string
{
    $map = ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'];
    return \strtr($value, $map);
}

if (!function_exists('to_gregorian')) {
    function to_gregorian($jalaliDate)
    {
        if (empty($jalaliDate)) return '';
        
        require_once __DIR__ . '/JalaliDate.php';
        
        return \Helpers\JalaliDate::toGregorian($jalaliDate);
    }
}

function time_ago(string $datetime): string
{
    $timestamp = \strtotime($datetime);
    $now = \time();
    $diff = $now - $timestamp;

    if ($diff < 60) {
        return 'همین الان';
    } elseif ($diff < 3600) {
        $minutes = \floor($diff / 60);
        return to_jalali((string)$minutes, '', true) . ' دقیقه پیش';
    } elseif ($diff < 86400) {
        $hours = \floor($diff / 3600);
        return to_jalali((string)$hours, '', true) . ' ساعت پیش';
    } elseif ($diff < 2592000) {
        $days = \floor($diff / 86400);
        return to_jalali((string)$days, '', true) . ' روز پیش';
    } elseif ($diff < 31536000) {
        $months = \floor($diff / 2592000);
        return to_jalali((string)$months, '', true) . ' ماه پیش';
    } else {
        $years = \floor($diff / 31536000);
        return to_jalali((string)$years, '', true) . ' سال پیش';
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Helpers;

use Carbon\Carbon;

class DateHelper
{
    private const string MP_TIMEZONE = '-04:00';
    private const string LOCAL_TIMEZONE = 'America/Buenos_Aires';

    public static function toLocal(string $mpDate): string
    {
        return Carbon::parse($mpDate, self::MP_TIMEZONE)
            ->setTimezone(self::LOCAL_TIMEZONE)
            ->format('Y-m-d H:i:s');
    }

    public static function formatForDb(string $date): string
    {
        return Carbon::parse($date)->format('Y-m-d H:i:s');
    }

    public static function calculateDelay(string $createdAt): string
    {
        $start = Carbon::parse($createdAt, self::MP_TIMEZONE);
        $now = Carbon::now(self::LOCAL_TIMEZONE);
        $seconds = $start->diffInSeconds($now);

        if ($seconds < 60) {
            return round($seconds, 1) . ' seg';
        }

        $minutes = $seconds / 60;
        if ($minutes < 60) {
            return round($minutes, 1) . ' min';
        }

        $hours = $minutes / 60;
        return round($hours, 1) . ' hs';
    }
}

<?php

namespace App\Support;

class MonitorFormat
{
    public static function number(mixed $value, string $unit = ''): string
    {
        return is_numeric($value) && $value >= 0 ? number_format((float) $value, 1).($unit === '' ? '' : ' '.$unit) : __('monitoring.common.missing_value');
    }

    public static function status(mixed $value): string
    {
        return match ($value) {
            'running' => __('monitoring.status.running'),
            'exited' => __('monitoring.status.exited'),
            'paused' => __('monitoring.status.paused'),
            'restarting' => __('monitoring.status.restarting'),
            'created' => __('monitoring.status.created'),
            'dead' => __('monitoring.status.dead'),
            default => is_string($value) ? $value : __('monitoring.common.unknown'),
        };
    }
}

<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use IntlCalendar;
use IntlException;

class JalaliDate
{
    public static function normalize(string $value): string
    {
        return strtr(trim($value), array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789'),
        ));
    }

    public static function toGregorian(string $value): ?string
    {
        if (! preg_match('~^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$~', self::normalize($value), $parts)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', array_slice($parts, 1));
        if ($year < 1000 || $year > 1600 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        $calendar = IntlCalendar::createInstance('UTC', 'en_US@calendar=persian');
        $calendar->clear();
        $calendar->setLenient(false);
        $calendar->set(IntlCalendar::FIELD_YEAR, $year);
        $calendar->set(IntlCalendar::FIELD_MONTH, $month - 1);
        $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, $day);
        try {
            $milliseconds = $calendar->getTime();
        } catch (IntlException) {
            return null;
        }

        return $milliseconds === false ? null : (new DateTimeImmutable('@'.(int) ($milliseconds / 1000)))->format('Y-m-d');
    }

    public static function fromGregorian(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        $calendar = IntlCalendar::createInstance('UTC', 'en_US@calendar=persian');
        $calendar->setTime($date->getTimestamp() * 1000);

        return sprintf('%04d/%02d/%02d', $calendar->get(IntlCalendar::FIELD_YEAR), $calendar->get(IntlCalendar::FIELD_MONTH) + 1, $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH));
    }

    // Preserve the API's wall-clock date/time: it does not document a timezone.
    public static function apiTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/', $value)) {
            return null;
        }

        $stamp = str_replace('T', ' ', substr($value, 0, 19));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stamp, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d H:i:s') === $stamp ? $stamp : null;
    }

    public static function timestampLabel(mixed $value): string
    {
        $stamp = self::apiTimestamp($value);

        return $stamp === null ? __('monitoring.common.missing_value') : self::fromGregorian(substr($stamp, 0, 10)).substr($stamp, 10);
    }
}

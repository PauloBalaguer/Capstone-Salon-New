<?php

/** Treatment minutes only. No appointment or travel buffer is included. */
class ServiceDuration
{
    public static function parse($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $text = trim((string) $value);
        if (!preg_match('/^[1-9][0-9]*$/D', $text)) return null;
        $minutes = filter_var($text, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        return $minutes === false ? null : $minutes;
    }

    public static function format(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;
        $parts = [];
        if ($hours) $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
        if ($remainder || !$hours) $parts[] = $remainder . ' minute' . ($remainder === 1 ? '' : 's');
        return implode(' ', $parts);
    }

    public static function end(string $startDateTime, int $minutes): DateTimeImmutable
    {
        return (new DateTimeImmutable($startDateTime, new DateTimeZone('Asia/Manila')))->modify("+{$minutes} minutes");
    }
}

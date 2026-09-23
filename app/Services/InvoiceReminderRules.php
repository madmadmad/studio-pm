<?php

namespace App\Services;

// The one place a day-offset-from-due-date turns into a reminder rule name,
// shared by the daily SendInvoiceReminders command (deciding what's due
// today) and the invoice page (showing the upcoming/sent schedule).
class InvoiceReminderRules
{
    /**
     * The full configured schedule of day-offsets, including the repeating
     * overdue tail, capped at 'max_overdue_reminders' total overdue entries.
     *
     * @return array<int, int>
     */
    public static function schedule(): array
    {
        $offsets = config('invoicing.reminder_offsets');
        $lastOffset = max($offsets);
        $interval = config('invoicing.reminder_repeat_interval_days');
        $maxOverdue = config('invoicing.max_overdue_reminders');
        $initialOverdueCount = count(array_filter($offsets, fn ($o) => $o > 0));

        $schedule = $offsets;

        for ($i = 1; $initialOverdueCount + $i <= $maxOverdue; $i++) {
            $schedule[] = $lastOffset + $interval * $i;
        }

        return $schedule;
    }

    // The rule name due today for the given offset (today - due date, in
    // days; positive means overdue), or null if no rule applies today.
    public static function ruleForOffset(int $offsetDays): ?string
    {
        return in_array($offsetDays, self::schedule(), true) ? self::ruleName($offsetDays) : null;
    }

    public static function ruleName(int $offsetDays): string
    {
        return match (true) {
            $offsetDays < 0 => 'due_minus_'.abs($offsetDays),
            $offsetDays === 0 => 'due_0',
            default => 'due_plus_'.$offsetDays,
        };
    }

    public static function offsetFromRule(string $rule): int
    {
        return match (true) {
            $rule === 'due_0' => 0,
            str_starts_with($rule, 'due_minus_') => -1 * (int) substr($rule, strlen('due_minus_')),
            str_starts_with($rule, 'due_plus_') => (int) substr($rule, strlen('due_plus_')),
            default => 0,
        };
    }

    public static function label(int $offsetDays): string
    {
        return match (true) {
            $offsetDays < 0 => abs($offsetDays).' days before due',
            $offsetDays === 0 => 'Due today',
            default => $offsetDays.' days overdue',
        };
    }
}

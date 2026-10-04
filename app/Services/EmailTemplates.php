<?php

namespace App\Services;

use App\Models\StudioProfile;
use Illuminate\Notifications\Messages\MailMessage;

// Builds a notification email from its template (config/email_templates.php,
// with any wording changed in Settings -- studio_profiles.email_templates):
// subject, heading, the message's paragraphs, the button, the note under
// it, with :placeholders filled in.
class EmailTemplates
{
    public const FIELDS = ['subject', 'heading', 'message', 'button', 'note'];

    // Fields an email can go without: cleared in Settings, they stay empty.
    // The rest fall back to their default when left blank.
    public const OPTIONAL = ['heading', 'note'];

    public static function definitions(): array
    {
        return config('email_templates');
    }

    // A template's wording: Settings' where it's been changed, else the default.
    public static function fields(string $key): array
    {
        $overrides = StudioProfile::current()->email_templates[$key] ?? [];

        return collect(static::definitions()[$key]['defaults'])
            ->map(fn (string $default, string $field) => match (true) {
                filled($overrides[$field] ?? null) => $overrides[$field],
                in_array($field, static::OPTIONAL, true) && array_key_exists($field, $overrides) => '',
                default => $default,
            })
            ->all();
    }

    /**
     * @param  array<string, string|null>  $vars  placeholder => value, without the colon
     */
    public static function mail(string $key, array $vars, string $url): MailMessage
    {
        $fill = fn (string $text) => strtr($text, collect(['studio' => StudioProfile::brandName(), ...$vars])
            ->mapWithKeys(fn ($value, $name) => [":{$name}" => (string) $value])->all());
        $fields = array_map($fill, static::fields($key));
        $paragraphs = fn (string $text) => array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text)), 'strlen'));

        $mail = (new MailMessage)->subject($fields['subject']);
        if (filled($fields['heading'])) {
            $mail->greeting($fields['heading']);
        }
        foreach ($paragraphs($fields['message']) as $line) {
            $mail->line($line);
        }
        $mail->action($fields['button'] ?: 'Open', $url);
        foreach ($paragraphs($fields['note']) as $line) {
            $mail->line($line);
        }

        return $mail;
    }

    public static function firstName(?string $name): string
    {
        return trim(explode(' ', trim((string) $name))[0]) ?: 'there';
    }

    // "20 minutes", "7 days" -- a link's lifetime, for :expiry.
    public static function duration(int $minutes): string
    {
        return match (true) {
            $minutes % 1440 === 0 => ($minutes / 1440).' '.str('day')->plural($minutes / 1440),
            $minutes % 60 === 0 => ($minutes / 60).' '.str('hour')->plural($minutes / 60),
            default => $minutes.' '.str('minute')->plural($minutes),
        };
    }
}

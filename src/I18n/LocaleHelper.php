<?php

declare(strict_types=1);

namespace HomeCare\I18n;

/**
 * Locale-aware date/number formatting via PHP intl extension (HC-141).
 */
final class LocaleHelper
{
    private const LANGUAGE_TO_LOCALE = [
        'English-US' => 'en_US',
        'Spanish' => 'es_ES',
        'Portuguese-BR' => 'pt_BR',
    ];

    private string $locale;

    public function __construct(string $language = 'English-US')
    {
        $this->locale = self::LANGUAGE_TO_LOCALE[$language] ?? 'en_US';
    }

    public function formatDate(\DateTimeInterface $date, int $style = \IntlDateFormatter::MEDIUM): string
    {
        $fmt = new \IntlDateFormatter(
            $this->locale,
            $style,
            \IntlDateFormatter::NONE,
            $date->getTimezone(),
        );

        $result = $fmt->format($date);

        return $result !== false ? $result : $date->format('Y-m-d');
    }

    public function formatDateTime(\DateTimeInterface $date, int $dateStyle = \IntlDateFormatter::MEDIUM, int $timeStyle = \IntlDateFormatter::SHORT): string
    {
        $fmt = new \IntlDateFormatter(
            $this->locale,
            $dateStyle,
            $timeStyle,
            $date->getTimezone(),
        );

        $result = $fmt->format($date);

        return $result !== false ? $result : $date->format('Y-m-d H:i');
    }

    public function formatNumber(float $value, int $decimals = 2): string
    {
        $fmt = new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL);
        $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        $result = $fmt->format($value);

        return $result !== false ? $result : number_format($value, $decimals);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}

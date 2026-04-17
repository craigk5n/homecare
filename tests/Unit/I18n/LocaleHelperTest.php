<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\I18n;

use HomeCare\I18n\LocaleHelper;
use PHPUnit\Framework\TestCase;

final class LocaleHelperTest extends TestCase
{
    public function testEnglishLocale(): void
    {
        $helper = new LocaleHelper('English-US');
        $this->assertSame('en_US', $helper->getLocale());
    }

    public function testSpanishLocale(): void
    {
        $helper = new LocaleHelper('Spanish');
        $this->assertSame('es_ES', $helper->getLocale());
    }

    public function testPortugueseBrLocale(): void
    {
        $helper = new LocaleHelper('Portuguese-BR');
        $this->assertSame('pt_BR', $helper->getLocale());
    }

    public function testUnknownLanguageFallsBackToEnUs(): void
    {
        $helper = new LocaleHelper('Unknown');
        $this->assertSame('en_US', $helper->getLocale());
    }

    public function testFormatDateProducesNonEmptyString(): void
    {
        $date = new \DateTimeImmutable('2026-04-17 10:00:00', new \DateTimeZone('UTC'));

        $en = new LocaleHelper('English-US');
        $this->assertNotEmpty($en->formatDate($date));

        $es = new LocaleHelper('Spanish');
        $this->assertNotEmpty($es->formatDate($date));

        $pt = new LocaleHelper('Portuguese-BR');
        $this->assertNotEmpty($pt->formatDate($date));
    }

    public function testFormatDateTimeProducesNonEmptyString(): void
    {
        $date = new \DateTimeImmutable('2026-04-17 10:30:00', new \DateTimeZone('UTC'));

        $en = new LocaleHelper('English-US');
        $result = $en->formatDateTime($date);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('10:30', $result);
    }

    public function testFormatNumberUsesLocaleDecimalSeparator(): void
    {
        $pt = new LocaleHelper('Portuguese-BR');
        $result = $pt->formatNumber(1234.56);
        // Portuguese-BR uses comma as decimal separator
        $this->assertStringContainsString(',', $result);
    }

    public function testFormatNumberEnglish(): void
    {
        $en = new LocaleHelper('English-US');
        $result = $en->formatNumber(1234.56);
        $this->assertStringContainsString('.', $result);
    }
}

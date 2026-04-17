<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Translation;

use PHPUnit\Framework\TestCase;

/**
 * HC-141: Verify every key in English-US.txt appears in each translation file.
 */
final class TranslationCoverageTest extends TestCase
{
    private static string $translationsDir;

    public static function setUpBeforeClass(): void
    {
        self::$translationsDir = dirname(__DIR__, 3) . '/translations';
    }

    public function testSpanishHasAllEnglishKeys(): void
    {
        $this->assertTranslationCovers('Spanish');
    }

    public function testPortugueseBrHasAllEnglishKeys(): void
    {
        $this->assertTranslationCovers('Portuguese-BR');
    }

    public function testEnglishFileExists(): void
    {
        $this->assertFileExists(self::$translationsDir . '/English-US.txt');
    }

    public function testSpanishFileExists(): void
    {
        $this->assertFileExists(self::$translationsDir . '/Spanish.txt');
    }

    public function testPortugueseBrFileExists(): void
    {
        $this->assertFileExists(self::$translationsDir . '/Portuguese-BR.txt');
    }

    private function assertTranslationCovers(string $language): void
    {
        $englishKeys = $this->parseKeys('English-US');
        $langKeys = $this->parseKeys($language);

        $missing = array_diff($englishKeys, $langKeys);

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "%s.txt is missing %d key(s) from English-US.txt:\n  %s",
                $language,
                count($missing),
                implode("\n  ", $missing)
            )
        );
    }

    /**
     * @return list<string>
     */
    private function parseKeys(string $language): array
    {
        $file = self::$translationsDir . '/' . $language . '.txt';
        $this->assertFileExists($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);

        $keys = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $keys[] = trim(substr($line, 0, $pos));
            }
        }

        return $keys;
    }
}

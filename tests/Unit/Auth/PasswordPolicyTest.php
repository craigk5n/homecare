<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\PasswordPolicy;
use HomeCare\Database\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    /**
     * Small throwaway list so tests don't pay the cost of loading the
     * 54k-entry bundled list on every case.
     */
    private string $listFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listFile = tempnam(sys_get_temp_dir(), 'pwlist_');
        file_put_contents(
            $this->listFile,
            "password\n12345678\nqwerty\nletmein\npassword12!\nsecret123!\n"
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_file($this->listFile)) {
            unlink($this->listFile);
        }
    }

    private function policy(
        int $minLength = PasswordPolicy::DEFAULT_MIN_LENGTH,
        bool $requireSymbol = PasswordPolicy::DEFAULT_REQUIRE_SYMBOL,
    ): PasswordPolicy {
        return PasswordPolicy::withRules(
            $minLength,
            $requireSymbol,
            $this->listFile
        );
    }

    public function testAcceptsStrongPassword(): void
    {
        $this->assertSame([], $this->policy()->validate('Zebras!Quietly99'));
    }

    public function testRejectsTooShort(): void
    {
        $errors = $this->policy()->validate('Aa1!aA');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least 10', $errors[0]);
    }

    public function testRejectsAllAlphanumericUnderLongThreshold(): void
    {
        // 13 chars, purely alphanumeric → below LONG_PASSWORD_BYPASS (14).
        $errors = $this->policy()->validate('Apples1234567');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('non-alphanumeric character', $errors[0]);
    }

    public function testAcceptsAllAlphanumericAtLongThreshold(): void
    {
        // 14 chars of letters — fine because length bypasses symbol rule.
        $this->assertSame([], $this->policy()->validate('ApplesPearsNow'));
    }

    public function testRejectsWhenContainsLogin(): void
    {
        $errors = $this->policy()
            ->validate('craigknudsenbigsafe!', ['login' => 'craigknudsen']);

        $this->assertContains('Password must not contain your login.', $errors);
    }

    public function testRejectsWhenContainsFirstName(): void
    {
        $errors = $this->policy()
            ->validate('Craig#1security', ['firstname' => 'Craig']);

        $this->assertContains('Password must not contain your firstname.', $errors);
    }

    public function testEmailComparedByLocalPartOnly(): void
    {
        // "alice" matches, but "example.org" should NOT cause
        // a false-positive if the password happens to contain it.
        $errors = $this->policy()
            ->validate('example.org-strong!pw', ['email' => 'alice@example.org']);

        $this->assertEmpty(array_filter(
            $errors,
            static fn (string $e): bool => str_contains($e, 'email')
        ));
    }

    public function testRejectsEmailLocalPart(): void
    {
        $errors = $this->policy()
            ->validate('alice1234567!ok', ['email' => 'alice@example.org']);

        $this->assertContains('Password must not contain your email.', $errors);
    }

    public function testIdentityRuleIgnoresShortFields(): void
    {
        // Two-character "Jo" shouldn't block any password that happens to
        // contain "jo"; otherwise nearly every password fails.
        $this->assertSame([], $this->policy()->validate('Johnson!Likes42', ['firstname' => 'Jo']));
    }

    public function testRejectsCommonPassword(): void
    {
        // "password12!" is in our fixture list verbatim. Long enough to
        // clear the length + symbol rules so rule 4 is the only reason
        // we expect a failure.
        $errors = $this->policy()->validate('password12!');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('common-password', $errors[0]);
    }

    public function testCommonPasswordCheckIsCaseInsensitive(): void
    {
        $errors = $this->policy(minLength: 8)->validate('Password');

        $this->assertNotEmpty(array_filter(
            $errors,
            static fn (string $e): bool => str_contains($e, 'common-password')
        ));
    }

    public function testMinLengthOverrideTakesEffect(): void
    {
        // 6-char pw passes if the minimum is 6.
        $this->assertSame(
            [],
            $this->policy(minLength: 6, requireSymbol: false)->validate('abc123')
        );
    }

    public function testRequireSymbolOverrideTakesEffect(): void
    {
        // When the symbol rule is off, a 10-char alphanumeric passes.
        $this->assertSame([], $this->policy(requireSymbol: false)->validate('abcdef1234'));
    }

    public function testMultipleViolationsAccumulate(): void
    {
        $errors = $this->policy()->validate('short'); // 5 chars, all alpha

        // Two violations: too short AND no symbol.
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function testConfigOverridesPullFromDatabase(): void
    {
        $db = new SqliteDatabase();
        $db->pdo()->exec('CREATE TABLE hc_config (setting VARCHAR(50) PRIMARY KEY, value VARCHAR(128))');
        $db->execute("INSERT INTO hc_config VALUES ('password_min_length', '6')");
        $db->execute("INSERT INTO hc_config VALUES ('password_require_symbol', 'N')");

        $policy = new PasswordPolicy($db, $this->listFile);

        // 6-char alphanumeric passes under the relaxed settings.
        $this->assertSame([], $policy->validate('abc123'));
    }

    public function testMissingCommonPasswordsFileDegradesGracefully(): void
    {
        $policy = PasswordPolicy::withRules(
            commonPasswordsFile: '/nonexistent/path.txt'
        );
        // Other rules still apply; fixture list is unreachable, so
        // a normally-rejected password now passes rule 4 (the file
        // is missing) but still trips other rules if any apply.
        $this->assertSame([], $policy->validate('Zebras!Quietly99'));
    }
}

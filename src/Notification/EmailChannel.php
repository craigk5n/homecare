<?php

declare(strict_types=1);

namespace HomeCare\Notification;

use HomeCare\Config\EmailConfig;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Email implementation of {@see NotificationChannel}.
 *
 * Transport is built lazily from {@see EmailConfig::getDsn()} on the
 * first `send()` call, so pages that never fire an email don't pay
 * the DSN-parse cost. Tests inject a pre-built `MailerInterface`
 * (typically Symfony's `NullTransport`) to capture messages without
 * hitting an SMTP server.
 *
 * Recipient resolution:
 *   - `NotificationMessage::$recipient` — if present, used verbatim
 *     (RFC 5322 address). Pages that know the user's address pass it.
 *   - Otherwise `send()` returns false — email needs an address and
 *     the channel does not guess.
 *
 * Body is rendered as `text/plain`. A richer HTML variant is a
 * future pass (STATUS.md flags this).
 */
final class EmailChannel implements NotificationChannel
{
    public const NAME = 'email';

    private ?MailerInterface $mailer;

    public function __construct(
        private readonly EmailConfig $config,
        ?MailerInterface $mailer = null,
    ) {
        $this->mailer = $mailer;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isReady(): bool
    {
        return $this->config->isReady();
    }

    public function send(NotificationMessage $message): bool
    {
        if (!$this->isReady()) {
            error_log('EmailChannel: skipped — SMTP not configured');
            return false;
        }
        if ($message->recipient === null || $message->recipient === '') {
            error_log('EmailChannel: skipped — message has no recipient');
            return false;
        }

        try {
            $email = (new Email())
                ->from(new Address(
                    $this->config->getFromAddress(),
                    $this->config->getFromName()
                ))
                ->to($message->recipient)
                ->subject($this->subject($message))
                ->text($message->body);

            $this->resolveMailer()->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            error_log('EmailChannel: transport error: ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            error_log('EmailChannel: unexpected error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Tags map to a bracketed subject prefix so mail clients can
     * filter on them; priority >= HIGH adds "[URGENT]" too.
     */
    private function subject(NotificationMessage $message): string
    {
        $prefix = '';
        if ($message->priority >= NotificationMessage::PRIORITY_HIGH) {
            $prefix .= '[URGENT] ';
        }
        if ($message->tags !== []) {
            $prefix .= '[' . implode(',', $message->tags) . '] ';
        }

        return $prefix . $message->title;
    }

    private function resolveMailer(): MailerInterface
    {
        if ($this->mailer === null) {
            $transport = Transport::fromDsn($this->config->getDsn());
            $this->mailer = new Mailer($transport);
        }

        return $this->mailer;
    }
}

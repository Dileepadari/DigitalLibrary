<?php

declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Three drivers:
 *
 *   log   write the message to storage/logs/mail-YYYY-MM-DD.log (the default, so
 *         a fresh install and CI need no mail server at all)
 *   mail  PHP's built-in mail()
 *   smtp  PHPMailer over SMTP
 *
 * Messages are plain text on purpose: every mail this app sends is a link plus a
 * sentence, and plain text cannot carry a tracking pixel or a broken template.
 */
final class Mailer
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly Logger $logger,
        private readonly string $logDirectory,
    ) {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $driver = (string) ($this->config['driver'] ?? 'log');

        if ($driver === 'smtp' && !class_exists(PHPMailer::class)) {
            $this->logger->warning('MAIL_DRIVER is smtp but PHPMailer is not installed; logging instead.', [
                'fix' => 'composer install',
            ]);
            $driver = 'log';
        }

        return match ($driver) {
            'smtp'  => $this->sendSmtp($to, $subject, $body),
            'mail'  => $this->sendNative($to, $subject, $body),
            default => $this->log($to, $subject, $body),
        };
    }

    private function from(): string
    {
        return sprintf(
            '%s <%s>',
            (string) ($this->config['from_name'] ?? 'Digital Library'),
            (string) ($this->config['from_address'] ?? 'library@localhost')
        );
    }

    private function log(string $to, string $subject, string $body): bool
    {
        $message = sprintf(
            "-----\nDate: %s\nFrom: %s\nTo: %s\nSubject: %s\n\n%s\n",
            date('c'),
            $this->from(),
            $to,
            $subject,
            $body
        );

        if (!is_dir($this->logDirectory)) {
            return false;
        }

        return file_put_contents(
            $this->logDirectory . '/mail-' . date('Y-m-d') . '.log',
            $message,
            FILE_APPEND | LOCK_EX
        ) !== false;
    }

    private function sendNative(string $to, string $subject, string $body): bool
    {
        return mail($to, $subject, $body, [
            'From'         => $this->from(),
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    private function sendSmtp(string $to, string $subject, string $body): bool
    {
        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = (string) ($this->config['host'] ?? 'localhost');
            $mailer->Port = (int) ($this->config['port'] ?? 587);
            $username = (string) ($this->config['username'] ?? '');

            if ($username !== '') {
                $mailer->SMTPAuth = true;
                $mailer->Username = $username;
                $mailer->Password = (string) ($this->config['password'] ?? '');
            }

            $encryption = (string) ($this->config['encryption'] ?? '');

            if ($encryption !== '') {
                $mailer->SMTPSecure = $encryption;
            }

            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom(
                (string) ($this->config['from_address'] ?? 'library@localhost'),
                (string) ($this->config['from_name'] ?? 'Digital Library')
            );
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->Body = $body;

            return $mailer->send();
        } catch (\Throwable $e) {
            $this->logger->error('SMTP send failed', ['to' => $to, 'error' => $e->getMessage()]);

            return false;
        }
    }
}

<?php
declare(strict_types=1);

/**
 * Sends email with no packages: plain text + HTML, inline images (the ticket QR
 * codes) and attachments (the .ics calendar file).
 *
 * Drivers, set in config ('mail' => ['driver' => ...]):
 *   smtp : log in to a real mailbox (e.g. tickets@ejikepius.dev) and send. Most reliable.
 *   mail : PHP's built-in mail(). Works on many hosts but often lands in spam.
 *   log  : don't send. Write the email to data/mail.log and the last HTML email to
 *          data/last-email.html so you can open it in a browser. Default for development.
 */
final class Mailer
{
    private const TIMEOUT = 10;

    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('mail'));
    }

    /**
     * @param array<string, array{data: string, type: string}> $inline      cid => image, shown in the HTML as <img src="cid:...">
     * @param array<string, array{data: string, type: string}> $attachments file name => file
     * Returns true if the email was sent (or logged). Never throws.
     */
    public function send(string $to, string $subject, string $text, string $html, array $inline = [], array $attachments = []): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        try {
            $message = $this->build($to, $subject, $text, $html, $inline, $attachments);
            match ($this->config['driver'] ?? 'log') {
                'smtp'  => $this->sendSmtp($to, $message),
                'mail'  => $this->sendMail($to, $subject, $message),
                default => $this->writeLog($to, $subject, $text, $html, $inline, $attachments),
            };
            return true;
        } catch (Throwable $e) {
            $this->log('FAILED to ' . $to . ': ' . $e->getMessage());
            return false;
        }
    }

    // ---- Building the message ---------------------------------------------

    /** @return array{headers: string, body: string} */
    private function build(string $to, string $subject, string $text, string $html, array $inline, array $attachments): array
    {
        $from     = $this->config['from_email'];
        $fromName = $this->config['from_name'] ?? 'Gatepass';
        $domain   = substr(strrchr($from, '@') ?: '@localhost', 1);

        // Plain text first, HTML second: email apps show the last version they understand.
        $alt = $this->boundary();
        $part = ['type' => 'multipart/alternative; boundary="' . $alt . '"', 'body' =>
            "--$alt\r\n" . $this->leaf('text/plain; charset=UTF-8', $text)
          . "--$alt\r\n" . $this->leaf('text/html; charset=UTF-8', $html)
          . "--$alt--\r\n"];

        // Inline images live next to the HTML that shows them.
        if ($inline) {
            $rel = $this->boundary();
            $body = "--$rel\r\nContent-Type: {$part['type']}\r\n\r\n{$part['body']}";
            foreach ($inline as $cid => $file) {
                $body .= "--$rel\r\n" . $this->leaf($file['type'], $file['data'], [
                    'Content-ID: <' . $cid . '>',
                    'Content-Disposition: inline; filename="' . $cid . '.png"',
                ]);
            }
            $part = ['type' => 'multipart/related; boundary="' . $rel . '"', 'body' => $body . "--$rel--\r\n"];
        }

        if ($attachments) {
            $mix = $this->boundary();
            $body = "--$mix\r\nContent-Type: {$part['type']}\r\n\r\n{$part['body']}";
            foreach ($attachments as $name => $file) {
                $body .= "--$mix\r\n" . $this->leaf($file['type'] . '; name="' . $name . '"', $file['data'], [
                    'Content-Disposition: attachment; filename="' . $name . '"',
                ]);
            }
            $part = ['type' => 'multipart/mixed; boundary="' . $mix . '"', 'body' => $body . "--$mix--\r\n"];
        }

        $headers = implode("\r\n", [
            'From: ' . mb_encode_mimeheader($fromName) . ' <' . $from . '>',
            'Reply-To: ' . ($this->config['reply_to'] ?? $from),
            'To: ' . $to,
            'Subject: ' . mb_encode_mimeheader($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: ' . $part['type'],
        ]);
        return ['headers' => $headers, 'body' => $part['body']];
    }

    private function leaf(string $type, string $data, array $extra = []): string
    {
        return implode("\r\n", array_merge(['Content-Type: ' . $type, 'Content-Transfer-Encoding: base64'], $extra))
            . "\r\n\r\n" . chunk_split(base64_encode($data));
    }

    private function boundary(): string
    {
        return 'gatepass_' . bin2hex(random_bytes(12));
    }

    // ---- Drivers ------------------------------------------------------------

    private function sendMail(string $to, string $subject, array $message): void
    {
        // mail() adds To and Subject itself, so remove them from our headers.
        $headers = preg_replace('/^(To|Subject): .*\r\n/m', '', $message['headers'] . "\r\n");
        $ok = mail($to, mb_encode_mimeheader($subject), $message['body'], rtrim($headers),
                   '-f' . $this->config['from_email']);
        if (!$ok) {
            throw new RuntimeException('mail() returned false');
        }
    }

    private function sendSmtp(string $to, array $message): void
    {
        $host = $this->config['host'];
        $port = (int) ($this->config['port'] ?? 465);
        // ssl = encrypted from the start (usually port 465).
        // tls = connect plain, then upgrade with STARTTLS (usually port 587).
        $implicit = ($this->config['encryption'] ?? ($port === 465 ? 'ssl' : 'tls')) === 'ssl';
        $remote   = ($implicit ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $socket = @stream_socket_client($remote, $errno, $errstr, self::TIMEOUT);
        if (!$socket) {
            throw new RuntimeException("Could not connect to $host:$port ($errstr)");
        }
        stream_set_timeout($socket, self::TIMEOUT);

        try {
            $this->expect($socket, 220);
            $this->command($socket, 'EHLO ' . $this->ehloName(), 250);

            if (!$implicit) {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS failed');
                }
                $this->command($socket, 'EHLO ' . $this->ehloName(), 250);
            }

            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode($this->config['username']), 334);
            $this->command($socket, base64_encode($this->config['password']), 235, hide: true);

            $this->command($socket, 'MAIL FROM:<' . $this->config['from_email'] . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', 354);

            // A line starting with "." would end the message early, so double it ("dot-stuffing").
            $data = preg_replace('/^\./m', '..', $message['headers'] . "\r\n\r\n" . $message['body']);
            $this->command($socket, $data . "\r\n.", 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function writeLog(string $to, string $subject, string $text, string $html, array $inline, array $attachments): void
    {
        $files = array_merge(array_map(fn ($c) => "inline $c", array_keys($inline)), array_keys($attachments));
        $this->log("To: $to\nSubject: $subject\n" . ($files ? 'Files: ' . implode(', ', $files) . "\n" : '')
            . "\n$text\n" . str_repeat('-', 60));

        // Preview: swap cid: images for data: URLs so the HTML opens in a browser.
        foreach ($inline as $cid => $file) {
            $html = str_replace('cid:' . $cid, 'data:' . $file['type'] . ';base64,' . base64_encode($file['data']), $html);
        }
        @file_put_contents(ROOT . '/data/last-email.html', $html);
    }

    // ---- SMTP helpers -------------------------------------------------------

    /** @param resource $socket */
    private function command($socket, string $line, int|array $expect, bool $hide = false): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expect, $hide ? '(password)' : strtok($line, "\r\n"));
    }

    /** Read the server's reply and check its 3-digit code. @param resource $socket */
    private function expect($socket, int|array $codes, string $after = 'connect'): void
    {
        $reply = '';
        while (($line = fgets($socket, 515)) !== false) {
            $reply .= $line;
            if (isset($line[3]) && $line[3] === ' ') {   // "250 OK" ends a reply, "250-..." continues it
                break;
            }
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, (array) $codes, true)) {
            throw new RuntimeException("SMTP said '" . trim($reply) . "' after $after");
        }
    }

    private function ehloName(): string
    {
        return parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
    }

    private function log(string $entry): void
    {
        @file_put_contents(ROOT . '/data/mail.log', '[' . date('c') . "] $entry\n", FILE_APPEND | LOCK_EX);
    }
}

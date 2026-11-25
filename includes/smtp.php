<?php
// Minimal SMTP client for sending the weekly report: STARTTLS (certificate verified), AUTH LOGIN,
// and a multipart/alternative (plain text + HTML) message. No external mail software needed.

final class SmtpMailer {
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private int $timeout = 30,
    ) {}

    public function send(string $from, string $fromName, array $to, string $subject, string $text, string $html): void {
        $this->connect();
        try {
            $this->command("MAIL FROM:<$from>", 250);
            foreach ($to as $addr) {
                $this->command("RCPT TO:<$addr>", [250, 251]);
            }
            $this->command('DATA', 354);
            $this->write($this->buildMessage($from, $fromName, $to, $subject, $text, $html) . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            if ($this->sock) {
                fclose($this->sock);
                $this->sock = null;
            }
        }
    }

    private function connect(): void {
        $this->sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout);
        if (!$this->sock) {
            throw new RuntimeException("Cannot connect to {$this->host}:{$this->port}: $errstr");
        }
        stream_set_timeout($this->sock, $this->timeout);
        $this->expect(220);
        $ehloHost = gethostname() ?: 'localhost';
        $this->command("EHLO $ehloHost", 250);
        $this->command('STARTTLS', 220);
        stream_context_set_option($this->sock, 'ssl', 'peer_name', $this->host);
        stream_context_set_option($this->sock, 'ssl', 'verify_peer', true);
        stream_context_set_option($this->sock, 'ssl', 'verify_peer_name', true);
        if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('TLS negotiation failed');
        }
        $this->command("EHLO $ehloHost", 250);
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->username), 334, 'AUTH username');
        $this->command(base64_encode($this->password), 235, 'AUTH password');
    }

    private function buildMessage(string $from, string $fromName, array $to, string $subject, string $text, string $html): string {
        $boundary = 'tt-' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $this->encodeHeader($fromName) . " <$from>",
            'To: ' . implode(', ', $to),
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@$domain>",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        ];
        $part = fn(string $type, string $body) => "--$boundary\r\n"
            . "Content-Type: $type; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n"
            . $part('text/plain', $text)
            . $part('text/html', $html)
            . "--$boundary--";
    }

    private function encodeHeader(string $value): string {
        return preg_match('/[^\x20-\x7e]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private function command(string $line, int|array $expect, ?string $label = null): string {
        $this->write($line);
        return $this->expect($expect, $label ?? strtok($line, ' '));
    }

    private function write(string $data): void {
        if (fwrite($this->sock, $data . "\r\n") === false) {
            throw new RuntimeException('Write to SMTP server failed');
        }
    }

    private function expect(int|array $codes, string $label = 'response'): string {
        $codes = (array)$codes;
        $response = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-" for all but the last line
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException("SMTP $label failed: " . trim($response ?: 'no response'));
        }
        return $response;
    }
}

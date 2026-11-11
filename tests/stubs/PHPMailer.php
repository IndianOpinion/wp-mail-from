<?php

declare(strict_types=1);

namespace PHPMailer\PHPMailer;

class Exception extends \Exception
{
}

/**
 * The parts of PHPMailer 6 that wp_mail() and this plugin touch, with the
 * same semantics: setFrom() only sets Sender when $auto is true, the
 * object is reused between messages, and invalid addresses throw.
 */
class PHPMailer
{
    public string $From = 'root@localhost';
    public string $FromName = 'Root User';
    public string $Sender = '';
    public string $Subject = '';
    public string $Body = '';
    /** @var array<string, array{0: string, 1: string}> */
    protected array $ReplyTo = [];
    /** @var list<string> */
    protected array $to = [];
    /** @var list<string> */
    protected array $customHeaders = [];

    public function __construct(public bool $exceptions = true)
    {
    }

    public function setFrom(string $address, string $name = '', bool $auto = true): bool
    {
        $address = trim($address);
        $name = trim(preg_replace('/[\r\n]+/', '', $name) ?? '');
        if (!is_email($address)) {
            throw new Exception('Invalid address:  (From): ' . $address);
        }
        $this->From = $address;
        $this->FromName = $name;
        if ($auto && $this->Sender === '') {
            $this->Sender = $address;
        }

        return true;
    }

    public function addAddress(string $address, string $name = ''): bool
    {
        if (!is_email($address)) {
            throw new Exception('Invalid address:  (to): ' . $address);
        }
        $this->to[] = $address;

        return true;
    }

    public function addReplyTo(string $address, string $name = ''): bool
    {
        $address = trim($address);
        if (!is_email($address)) {
            throw new Exception('Invalid address:  (Reply-To): ' . $address);
        }
        $key = strtolower($address);
        if (isset($this->ReplyTo[$key])) {
            return false;
        }
        $this->ReplyTo[$key] = [$address, $name];

        return true;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function getReplyToAddresses(): array
    {
        return $this->ReplyTo;
    }

    public function clearReplyTos(): void
    {
        $this->ReplyTo = [];
    }

    public function clearAllRecipients(): void
    {
        $this->to = [];
    }

    public function clearCustomHeaders(): void
    {
        $this->customHeaders = [];
    }

    public function addCustomHeader(string $header): void
    {
        $this->customHeaders[] = $header;
    }

    /**
     * @return list<string>
     */
    public function getCustomHeaders(): array
    {
        return $this->customHeaders;
    }

    public function send(): bool
    {
        $result = \WP::$sendResult;
        if ($result === 'throw') {
            throw new Exception('SMTP Error: The following recipients failed: ' . implode(', ', $this->to));
        }
        \WP::$sent[] = [
            'from' => $this->From,
            'name' => $this->FromName,
            'sender' => $this->Sender,
            'reply_to' => array_values(array_map(static fn (array $r): string => $r[0], $this->ReplyTo)),
            'headers' => $this->customHeaders,
        ];

        return (bool) $result;
    }
}

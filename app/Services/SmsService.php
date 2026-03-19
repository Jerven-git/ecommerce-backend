<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    protected string $provider;
    protected array $config;

    public function __construct()
    {
        $this->provider = config('sms.default', 'null');
        $this->config = config("sms.providers.{$this->provider}", []);
    }

    /**
     * Send an SMS message.
     */
    public function send(string $to, string $message): bool
    {
        return match ($this->provider) {
            'twilio' => $this->sendViaTwilio($to, $message),
            'vonage' => $this->sendViaVonage($to, $message),
            'log' => $this->sendViaLog($to, $message),
            'null' => true,
            default => throw new \RuntimeException("Unsupported SMS provider: {$this->provider}"),
        };
    }

    /**
     * Check if SMS is enabled (not using null driver).
     */
    public function isEnabled(): bool
    {
        return $this->provider !== 'null';
    }

    protected function sendViaTwilio(string $to, string $message): bool
    {
        // TODO: Install twilio/sdk and implement
        // $client = new \Twilio\Rest\Client($this->config['sid'], $this->config['token']);
        // $client->messages->create($to, [
        //     'from' => $this->config['from'],
        //     'body' => $message,
        // ]);

        throw new \RuntimeException('Twilio SMS provider is not yet implemented. Install twilio/sdk and uncomment the code in SmsService.');
    }

    protected function sendViaVonage(string $to, string $message): bool
    {
        // TODO: Install vonage/client and implement
        // $client = new \Vonage\Client(new \Vonage\Client\Credentials\Basic($this->config['key'], $this->config['secret']));
        // $client->sms()->send(new \Vonage\SMS\Message\SMS($to, $this->config['from'], $message));

        throw new \RuntimeException('Vonage SMS provider is not yet implemented. Install vonage/client and uncomment the code in SmsService.');
    }

    protected function sendViaLog(string $to, string $message): bool
    {
        Log::channel($this->config['channel'] ?? 'stack')
            ->info("SMS to {$to}: {$message}");

        return true;
    }
}

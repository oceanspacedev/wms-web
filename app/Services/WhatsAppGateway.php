<?php

namespace App\Services;

use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppGateway
{
    public const MESSAGES_PATH = '/api/v1/messages';

    public function send(string $phoneNumber, string $message): bool
    {
        $target = WhatsAppNumber::normalize($phoneNumber);

        if (! WhatsAppNumber::isValid($target)) {
            Log::error('Gagal mengirim pesan WhatsApp: nomor tujuan tidak valid.', [
                'whatsapp_last4' => substr($target, -4),
            ]);

            return false;
        }

        $url = config('services.whatsapp_gateway.url');
        $token = config('services.whatsapp_gateway.token');

        if (! is_string($url) || trim($url) === '') {
            Log::error('WAG_URL tidak diatur di file .env');

            return false;
        }

        if (! is_string($token) || trim($token) === '') {
            Log::error('WAG_TOKEN tidak diatur di file .env');

            return false;
        }

        $token = preg_replace('/^Bearer\s+/i', '', trim($token)) ?? trim($token);
        // PHP lokal (mis. Herd di Windows) kadang belum punya daftar sertifikat CA; isi WAG_CA_BUNDLE dengan file cacert.pem
        $caBundle = trim((string) config('services.whatsapp_gateway.ca_bundle', ''));

        try {
            $response = Http::acceptJson()
                ->when($caBundle !== '', fn ($request) => $request->withOptions(['verify' => $caBundle]))
                ->withToken($token)
                ->connectTimeout((float) config('services.whatsapp_gateway.connect_timeout', 5))
                ->timeout((float) config('services.whatsapp_gateway.timeout', 15))
                ->withHeaders([
                    'Idempotency-Key' => 'wms-'.(string) Str::uuid(),
                ])
                ->post($this->messagesEndpoint($url), [
                    'recipient' => [
                        'type' => 'phone',
                        'value' => $target,
                    ],
                    'message' => [
                        'type' => 'text',
                        'text' => $message,
                    ],
                    'purpose' => 'notification',
                    'mode' => 'sync',
                    'route_key' => 'default',
                    'client_reference' => 'wms',
                ]);
        } catch (\Throwable $exception) {
            Log::error('Gagal mengirim pesan WhatsApp via WAG.', [
                'whatsapp_last4' => substr($target, -4),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::error('Gagal mengirim pesan WhatsApp via WAG.', [
                'whatsapp_last4' => substr($target, -4),
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    private function messagesEndpoint(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        if (str_ends_with($baseUrl, self::MESSAGES_PATH)) {
            return $baseUrl;
        }

        return $baseUrl.self::MESSAGES_PATH;
    }
}

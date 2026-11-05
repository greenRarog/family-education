<?php

declare(strict_types=1);

namespace App\Services\Max;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BotApi
{
    public function call(string $method, string $path, array $parameters = []): array
    {
        if (! BotConfiguration::available()) {
            throw new RuntimeException('MAX is unavailable.');
        }
        try {
            $request = Http::connectTimeout(5)->timeout(5)->withHeaders(['Authorization' => config('services.max.token')])
                ->withOptions(['verify' => config('services.max.ca_bundle') ?: true]);
            $response = $request->send($method, 'https://platform-api2.max.ru/'.$path,
                [$method === 'GET' ? 'query' : 'json' => $parameters]);
            if ($response->successful() && is_array($response->json())) {
                return $response->json();
            }
        } catch (Throwable) {
            // Do not retain provider exception chains, request headers or content.
        }
        throw new RuntimeException('MAX request failed.');
    }

    public function send(string $userId, string $text): array
    {
        $identity = $this->call('GET', 'me');
        if ((string) ($identity['user_id'] ?? '') !== BotConfiguration::id()
            || ($identity['username'] ?? null) !== config('services.max.username') || ($identity['is_bot'] ?? false) !== true) {
            throw new RuntimeException('MAX bot identity does not match configuration.');
        }

        return $this->call('POST', 'messages?user_id='.rawurlencode($userId), ['text' => $text]);
    }

    public function instruction(string $userId, string $locale): void
    {
        $this->send($userId, match ($locale) {
            'en' => 'To connect MAX, open your Family Education profile and create a connection link.',
            'es' => 'Para conectar MAX, abre tu perfil de Family Education y crea un enlace de conexión.',
            default => 'Для подключения MAX откройте свой профиль Family Education и создайте ссылку подключения.',
        });
    }

    public function code(string $userId, string $code, string $locale): void
    {
        $this->send($userId, match ($locale) {
            'en' => "Family Education code: $code. Enter it only in your own profile in the same browser session. Do not share it. Enable notifications separately on the website.",
            'es' => "Código de Family Education: $code. Introdúcelo solo en tu propio perfil y en la misma sesión. No lo compartas. Activa las notificaciones por separado en la web.",
            default => "Код Family Education: $code. Введите его только в своём профиле в той же сессии браузера. Никому не передавайте код. Уведомления включаются отдельно на сайте.",
        });
    }
}

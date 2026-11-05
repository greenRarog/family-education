<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** No provider exception/URL or raw update is allowed into application logs. */
class BotApi
{
    public function call(string $method, array $parameters = []): mixed
    {
        if (! BotConfiguration::available()) {
            throw new RuntimeException('Telegram is unavailable.');
        }
        try {
            $response = Http::connectTimeout(5)->timeout($method === 'getUpdates' ? 35 : 5)
                ->withOptions(['proxy' => config('services.telegram.proxy') ?: null])
                ->post('https://api.telegram.org/bot'.config('services.telegram.token').'/'.$method, $parameters);
            if ($response->successful() && $response->json('ok') === true) {
                return $response->json('result');
            }
        } catch (Throwable) {
            // Deliberately discard the original exception, including its previous chain.
        }
        throw new RuntimeException('Telegram request failed.');
    }

    public function instruction(string $chat, string $locale): void
    {
        $text = match ($locale) {
            'en' => 'To connect Telegram, open your own Family Education profile and create a connection link there.',
            'es' => 'Para conectar Telegram, abre tu propio perfil de Family Education y crea allí un enlace de conexión.',
            default => 'Для подключения Telegram откройте свой профиль Family Education и создайте ссылку подключения на сайте.',
        };
        $this->call('sendMessage', ['chat_id' => $chat, 'text' => $text]);
    }

    public function code(string $chat, string $code, string $locale): void
    {
        $text = match ($locale) {
            'en' => "Family Education code: $code. Enter it only in your own profile in the same browser session. Do not share it. Notifications remain off until you enable them on the website.",
            'es' => "Código de Family Education: $code. Introdúcelo solo en tu propio perfil y en la misma sesión del navegador. No lo compartas. Las notificaciones seguirán desactivadas hasta que las actives en la web.",
            default => "Код Family Education: $code. Введите его только в своём профиле в той же сессии браузера. Никому не передавайте код. Уведомления останутся выключены до включения на сайте.",
        };
        $this->call('sendMessage', ['chat_id' => $chat, 'text' => $text, 'protect_content' => true]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\AdvertisementExpiredNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\NewResponseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationRenderingTest extends TestCase
{
    use RefreshDatabase;

    public static function localizedNotifications(): array
    {
        $subjects = [
            'ru' => [
                'new_response' => 'Новый отклик на ваше объявление',
                'new_message' => 'Новое сообщение',
                'advertisement_expired' => 'Срок действия объявления истёк',
            ],
            'en' => [
                'new_response' => 'New response to your advertisement',
                'new_message' => 'New message',
                'advertisement_expired' => 'Your advertisement has expired',
            ],
            'es' => [
                'new_response' => 'Nueva respuesta a tu anuncio',
                'new_message' => 'Nuevo mensaje',
                'advertisement_expired' => 'Tu anuncio ha caducado',
            ],
        ];
        $cases = [];
        foreach ($subjects as $locale => $notifications) {
            foreach ($notifications as $key => $subject) {
                $cases["{$locale}: {$key}"] = [$locale, $key, $subject];
            }
        }

        return $cases;
    }

    #[DataProvider('localizedNotifications')]
    public function test_notification_renders_in_the_recipient_language_with_escaped_content(string $locale, string $key, string $subject): void
    {
        config(['mail.default' => 'array', 'queue.default' => 'sync']);
        URL::forceRootUrl('https://family-education.test');
        URL::forceScheme('https');
        $originalLocale = $locale === 'en' ? 'ru' : 'en';
        app()->setLocale($originalLocale);
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $owner = $response->advertisement->user;
        $owner->update(['locale' => $locale, 'name' => '<b>Получатель & "José"</b>']);
        $senderName = '<img src=x onerror="alert(1)">Отправитель & Ana';
        $response->user->update(['name' => $senderName]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $response->user_id,
            'body' => "<script>alert(\"private message\")</script>\nТекст & español 'quoted'",
        ]);
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
            $sent[] = $event->message;
        });

        $notification = match ($key) {
            'new_response' => new NewResponseNotification($response),
            'new_message' => new NewMessageNotification($message),
            'advertisement_expired' => new AdvertisementExpiredNotification($response->advertisement),
        };
        $this->assertSame(['mail'], $notification->via($owner));

        // Exercise real sync queue, notification, Blade and array mail transport; no SMTP.
        $owner->notify($notification);
        $this->assertCount(1, $sent);
        $this->assertSame($originalLocale, app()->getLocale());
        $mail = $sent[0];
        $this->assertSame($subject, $mail->getSubject());
        $this->assertCount(1, $mail->getTo());
        $this->assertSame($owner->email, $mail->getTo()[0]->getAddress());
        $html = $mail->getHtmlBody();
        $this->assertIsString($html);
        $this->assertStringContainsString('<html lang="'.$locale.'">', $html);
        $this->assertStringContainsString(e($subject), $html);
        $label = $key === 'new_message' ? 'message' : 'advertisement';
        foreach (['subject', 'greeting', 'line', 'action', 'salutation', $label] as $field) {
            $translation = "notifications.{$key}.{$field}";
            $this->assertTrue(Lang::hasForLocale($translation, $locale), $translation);
            $this->assertStringContainsString(e(__($translation, [
                'name' => $owner->name,
                'user' => $senderName,
            ], $locale)), $html);
        }
        $this->assertStringNotContainsString('notifications.', $html);
        $this->assertStringNotContainsString($owner->name, $html);
        $this->assertStringNotContainsString($senderName, $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString(e($owner->name), $html);
        if ($key !== 'advertisement_expired') {
            $this->assertStringContainsString(e($senderName), $html);
        }
        if ($key === 'new_message') {
            $this->assertStringContainsString(e($message->body), $html);
        }
        $path = $key === 'advertisement_expired' ? '/my-advertisements' : "/conversations/{$conversation->id}";
        $this->assertStringContainsString('href="https://family-education.test'.$path.'"', $html);
    }
}

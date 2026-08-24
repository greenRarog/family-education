<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class NewMessageNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_mail_channel(): void
    {
        $recipient = User::factory()->create();
        $sender = User::factory()->create();
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
        ]);
        $notification = new NewMessageNotification($message);

        $channels = $notification->via($recipient);

        $this->assertSame(['mail'], $channels);
    }

    public function test_builds_email_for_new_message(): void
    {
        $recipient = User::factory()->create(['name' => 'Получатель']);
        $sender = User::factory()->create(['name' => 'Отправитель']);
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'body' => 'Текст нового сообщения',
        ]);
        $notification = new NewMessageNotification($message);

        $mail = $notification->toMail($recipient);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertSame(__('notifications.new_message.subject'), $mail->subject);
        $this->assertSame('emails.notifications.new-message', $mail->view);
    }
}

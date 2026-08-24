@extends('emails.layout')

@section('content')
    <p style="margin: 0 0 24px; color: #171717; font-size: 15px;">
        {{ __('notifications.new_message.greeting', ['name' => $notifiable->name]) }}
    </p>

    <h1 style="margin: 0 0 16px; color: #171717; font-size: 22px; font-weight: 600; line-height: 1.3;">
        {{ __('notifications.new_message.subject') }}
    </h1>

    <p style="margin: 0 0 24px; color: #6b7280; font-size: 15px;">
        {{ __('notifications.new_message.line', ['user' => $message->user->name]) }}
    </p>

    <p style="margin: 0 0 8px; color: #6b7280; font-size: 13px;">
        {{ __('notifications.new_message.message') }}
    </p>

    <div
        style="padding: 16px; background-color: #f7f7f8; border: 1px solid #e5e7eb; border-radius: 8px;"
    >
        <p style="margin: 0; color: #171717; font-size: 14px; white-space: pre-wrap; word-break: break-word;">
            {{ $message->body }}
        </p>
    </div>

    <div style="margin-top: 24px;">
        <a
            href="{{ url("/conversations/{$message->conversation_id}") }}"
            style="display: inline-block; padding: 10px 16px; background-color: #171717; color: #ffffff; border-radius: 8px; font-size: 14px; font-weight: 500; text-decoration: none;"
        >
            {{ __('notifications.new_message.action') }}
        </a>
    </div>

    <p style="margin: 32px 0 0; color: #6b7280; font-size: 14px;">
        {{ __('notifications.new_message.salutation') }}
    </p>
@endsection

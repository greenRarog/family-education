@extends('emails.layout')

@section('content')
    <p style="margin: 0 0 24px; color: #171717; font-size: 15px;">
        {{ __('notifications.advertisement_expired.greeting', ['name' => $notifiable->name]) }}
    </p>

    <h1 style="margin: 0 0 16px; color: #171717; font-size: 22px; font-weight: 600; line-height: 1.3;">
        {{ __('notifications.advertisement_expired.subject') }}
    </h1>

    <p style="margin: 0 0 24px; color: #6b7280; font-size: 15px;">
        {{ __('notifications.advertisement_expired.line') }}
    </p>

    <table
        role="presentation"
        width="100%"
        cellspacing="0"
        cellpadding="0"
        border="0"
        style="margin-bottom: 24px;"
    >
        <tr>
            <td
                style="padding: 16px; background-color: #f7f7f8; border: 1px solid #e5e7eb; border-radius: 8px;"
            >
                <p style="margin: 0 0 6px; color: #6b7280; font-size: 12px;">
                    {{ __('notifications.advertisement_expired.advertisement') }}
                </p>
            </td>
        </tr>
    </table>

    <a
        href="{{ url('/my-advertisements') }}"
        style="display: inline-block; padding: 10px 16px; background-color: #171717; color: #ffffff; border-radius: 8px; font-size: 14px; font-weight: 500; text-decoration: none;"
    >
        {{ __('notifications.advertisement_expired.action') }}
    </a>

    <p style="margin: 32px 0 0; color: #6b7280; font-size: 14px;">
        {{ __('notifications.advertisement_expired.salutation') }}
    </p>
@endsection

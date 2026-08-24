<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
</head>
<body
    style="margin: 0; padding: 0; background-color: #f7f7f8; color: #171717; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.5;">

<table
    role="presentation"
    width="100%"
    cellspacing="0"
    cellpadding="0"
    border="0"
    style="width: 100%; margin: 0; padding: 0; background-color: #f7f7f8;"
>
    <tr>
        <td style="padding: 40px 16px;">

            <table
                role="presentation"
                width="100%"
                cellspacing="0"
                cellpadding="0"
                border="0"
                style="width: 100%; max-width: 560px; margin: 0 auto;"
            >
                {{-- Logo / название --}}
                <tr>
                    <td style="padding-bottom: 24px; text-align: center;">
                        <a
                            href="{{ url('/') }}"
                            style="color: #171717; font-size: 18px; font-weight: 600; text-decoration: none;"
                        >
                            Family Education
                        </a>
                    </td>
                </tr>

                {{-- Основная карточка --}}
                <tr>
                    <td
                        style="padding: 32px; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px;"
                    >
                        @yield('content')
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding-top: 24px; text-align: center;">
                        <p style="margin: 0; color: #9ca3af; font-size: 12px;">
                            Family Education
                        </p>

                        <p style="margin: 6px 0 0; color: #9ca3af; font-size: 12px;">
                            {{ url('/') }}
                        </p>
                    </td>
                </tr>
            </table>

        </td>
    </tr>
</table>

</body>
</html>

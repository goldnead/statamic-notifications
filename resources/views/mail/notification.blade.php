{{-- Intentionally plain. The host publishes this view to brand it:
     php artisan vendor:publish --tag=notifications-views --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:32px 24px;background:#ffffff;">
        @if ($title)
            <h1 style="font-size:20px;margin:0 0 16px;color:#18181b;">{{ $title }}</h1>
        @endif

        @if ($message)
            <p style="color:#52525b;line-height:1.6;">{{ $message }}</p>
        @endif

        @if ($link)
            <p style="margin:32px 0;">
                <a href="{{ $link }}" style="display:inline-block;background:#18181b;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">
                    {{ __('notifications::mail.view') }}
                </a>
            </p>
        @endif
    </div>
</body>
</html>

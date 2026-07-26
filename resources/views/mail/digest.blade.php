{{-- Intentionally plain. The host publishes this view to brand it. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:32px 24px;background:#ffffff;">
        <p style="color:#52525b;line-height:1.6;">{{ __('notifications::mail.digest_intro') }}</p>

        @forelse ($items as $entry)
            <div style="padding:12px 0;border-bottom:1px solid #e4e4e7;">
                <p style="margin:0;color:#18181b;">{{ $entry['message'] ?? $entry['title'] }}</p>
                @if (! empty($entry['link']))
                    <a href="{{ $entry['link'] }}" style="font-size:13px;color:#3f3f46;">{{ __('notifications::mail.view') }}</a>
                @endif
            </div>
        @empty
            <p style="color:#a1a1aa;">{{ __('notifications::mail.digest_empty') }}</p>
        @endforelse

        @foreach ($extras as $source => $payload)
            <div style="padding:12px 0;border-bottom:1px solid #e4e4e7;">
                <p style="margin:0;font-size:13px;color:#71717a;">{{ $source }}</p>
                <pre style="margin:4px 0 0;font-size:12px;color:#3f3f46;white-space:pre-wrap;">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endforeach

        @if ($preferencesUrl)
            <p style="margin-top:32px;font-size:12px;color:#a1a1aa;">
                <a href="{{ $preferencesUrl }}" style="color:#a1a1aa;">{{ __('notifications::mail.preferences') }}</a>
            </p>
        @endif
    </div>
</body>
</html>

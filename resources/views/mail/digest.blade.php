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
            {{-- Only when the sources have nothing either. "Nothing new this
                 time" above a list of things is how this mail used to read. --}}
            @if (empty($extras))
                <p style="color:#a1a1aa;">{{ __('notifications::mail.digest_empty') }}</p>
            @endif
        @endforelse

        {{-- Each source hands over its own finished sentence under `line`, and
             that is all this view prints. Whatever else a contribution carries
             is still there for a published view to lay out richly; this one
             used to print all of it through json_encode() in a <pre> block. --}}
        @foreach ($extras as $extra)
            <div style="padding:12px 0;border-bottom:1px solid #e4e4e7;">
                <p style="margin:0;color:#18181b;">{{ $extra['line'] }}</p>
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

@php
    use App\Enums\MessageKind;
    use App\Support\MediaUrl;

    /** @var \App\Models\Enquiry $enquiry */
    $enquiry = $getRecord();
    $messages = $enquiry->messages()->with(['author', 'media'])->get();
@endphp

@if ($messages->isEmpty())
    <p style="opacity:.6;">No messages yet.</p>
@endif

<div style="display:flex;flex-direction:column;gap:12px;">
    @foreach ($messages as $message)
        @if ($message->kind === MessageKind::System)
            <div style="text-align:center;font-size:12px;opacity:.6;">
                {{ $message->body }} · {{ $message->created_at->format('d M Y, H:i') }}
            </div>
        @else
            @php
                $staff = $message->from_staff;
                $question = $message->kind === MessageKind::Question;
            @endphp
            <div style="display:flex;justify-content:{{ $staff ? 'flex-end' : 'flex-start' }};">
                <div style="max-width:80%;padding:10px 14px;border-radius:14px;{{ $staff ? 'background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.35);' : 'background:rgba(128,128,128,.12);border:1px solid rgba(128,128,128,.25);' }}">
                    <div style="font-size:12px;opacity:.7;margin-bottom:4px;">
                        <strong>{{ $message->author?->name ?? 'System' }}</strong>
                        {{ $staff ? '(staff)' : '(customer)' }}
                        · {{ $message->created_at->format('d M Y, H:i') }}
                        @if ($question)
                            · <span style="color:#d97706;font-weight:600;">Question</span>
                        @endif
                        @if (! $staff && ! $message->read_at)
                            · <span style="color:#dc2626;font-weight:600;">New</span>
                        @endif
                    </div>
                    <div style="white-space:pre-line;">{{ $message->body }}</div>
                    @if ($message->media->isNotEmpty())
                        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;">
                            @foreach ($message->media as $file)
                                <a href="{{ MediaUrl::for($file) }}" target="_blank" style="font-size:12px;padding:3px 8px;border-radius:6px;border:1px solid rgba(128,128,128,.35);text-decoration:none;color:inherit;">
                                    📎 {{ $file->file_name }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endforeach
</div>

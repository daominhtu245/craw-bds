<x-filament-panels::page>
    <form wire:submit="analyze" class="space-y-4">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-beaker">
            Kiểm tra
        </x-filament::button>
    </form>

    @if ($result)
        <x-filament::section>
            <x-slot name="heading">
                @if ($result['passed'])
                    ✅ Qua prefilter, sẽ được gửi cho AI
                @else
                    ⛔ Bị loại: {{ $result['reason'] }} ({{ $result['reason_code'] }})
                @endif
            </x-slot>

            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div><dt class="font-medium">Ngôn ngữ phát hiện</dt><dd>{{ $result['language'] ?? '—' }}</dd></div>
                <div><dt class="font-medium">Có từ về nhà ở</dt><dd>{{ $result['housing'] ? 'Có' : 'Không' }}</dd></div>
                <div><dt class="font-medium">Keyword dương</dt><dd>{{ implode(', ', $result['positive']) ?: '—' }} @if ($result['strong']) (có keyword mạnh) @endif</dd></div>
                <div><dt class="font-medium">Keyword âm (điểm {{ $result['negative_score'] }})</dt><dd>{{ implode(', ', $result['negative']) ?: '—' }}</dd></div>
                <div><dt class="font-medium">Khu vực nhận ra</dt><dd>{{ implode(', ', $result['areas']) ?: '—' }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-gray-500">Chỉ chạy các bước theo văn bản (quá ngắn, tiếng Việt, keyword). Các bước trùng lặp, môi giới đã biết và quá cũ cần dữ liệu thật.</p>
        </x-filament::section>

        @php($r = $result['rules'])
        <x-filament::section>
            <x-slot name="heading">
                Phân loại bằng keyword: {{ $r['intent'] }} · độ tin cậy {{ $r['confidence'] }}
                {{ $r['creates_lead'] ? '→ tạo lead' : '→ không tạo lead' }}
            </x-slot>

            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div><dt class="font-medium">Tóm tắt</dt><dd>{{ $r['summary'] }}</dd></div>
                <div><dt class="font-medium">Keyword khớp</dt><dd>{{ implode(', ', $r['matched']) ?: '—' }}</dd></div>
                <div><dt class="font-medium">Giai đoạn</dt><dd>{{ $r['stage'] }}</dd></div>
                <div><dt class="font-medium">Loại nhà</dt><dd>{{ $r['type'] }}</dd></div>
                <div><dt class="font-medium">Ngân sách</dt><dd>{{ $r['budget'] ?? '—' }}</dd></div>
                <div><dt class="font-medium">Thời gian thuê / ngày đến</dt><dd>{{ $r['duration'] ? $r['duration'].' tháng' : '—' }} / {{ $r['arrival'] ?? '—' }}</dd></div>
                <div><dt class="font-medium">Yêu cầu</dt><dd>{{ implode(', ', $r['requirements']) ?: '—' }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-gray-500">Chỉnh kết quả bằng các keyword nhóm "Phân loại: …" và "Giai đoạn: …" ở trang Từ khóa (áp dụng ngay khi lưu).</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>

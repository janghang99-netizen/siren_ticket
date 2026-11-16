@extends('layouts.backoffice')
@section('title', '1:1 문의')
@section('eyebrow', '고객센터')

@section('content')
    <nav class="mb-4 flex gap-2 text-sm" aria-label="상태">
        @foreach (['open' => '답변 대기 ('.$openCount.')', 'answered' => '답변 완료', 'all' => '전체'] as $k => $v)
            <a href="{{ route('admin.inquiries.index', ['status' => $k]) }}" @class(['rounded-full px-4 py-2 font-semibold', 'bg-ink text-paper' => $status === $k, 'bg-white ring-1 ring-ink/10' => $status !== $k])>{{ $v }}</a>
        @endforeach
    </nav>
    <div class="overflow-x-auto rounded-3xl bg-white ring-1 ring-ink/5">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="border-b border-ink/5 text-left text-xs text-mist"><tr><th class="px-5 py-3 font-semibold">받은 때</th><th class="px-3 py-3 font-semibold">종류</th><th class="px-3 py-3 font-semibold">제목</th><th class="px-3 py-3 font-semibold">고객</th><th class="px-5 py-3 font-semibold">상태</th></tr></thead>
            <tbody class="divide-y divide-ink/5">
                @forelse ($inquiries as $q)
                    <tr data-href="{{ route('admin.inquiries.show', $q) }}">
                        <td class="num px-5 py-3 text-xs">{{ $q->created_at->format('m.d H:i') }}</td>
                        <td class="px-3 py-3 text-xs">{{ $q->categoryLabel() }}{{ $q->store ? ' · '.$q->store->name : '' }}</td>
                        <td class="px-3 py-3"><a href="{{ route('admin.inquiries.show', $q) }}" class="row-link font-semibold">{{ $q->title }}</a></td>
                        <td class="num px-3 py-3 text-xs">{{ $q->customer?->maskedPhone() }}</td>
                        <td class="px-5 py-3"><span @class(['chip', 'bg-stick/10 text-stick' => $q->isAnswered(), 'bg-signal/10 text-signal' => ! $q->isAnswered()])>{{ $q->isAnswered() ? '답변 완료' : '답변 대기' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-mist">문의가 없어요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $inquiries->links() }}</div>
@endsection

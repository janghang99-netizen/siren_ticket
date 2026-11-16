@extends('layouts.fan')
@section('title', '1:1 문의')

@section('content')
    <p class="eyebrow mt-2">고객센터</p>
    <h1 class="mt-2 text-2xl font-black">1:1 문의</h1>
    <p class="mt-1 text-sm text-mist">주문·매장·계정에 대해 물어보세요. 답변이 달리면 문자로 알려 드려요.</p>

    @if (! $customer)
        <div class="mt-6 rounded-3xl bg-white p-6 text-center ring-1 ring-ink/5">
            <p class="font-bold">휴대폰 인증 후 문의할 수 있어요</p>
            <p class="mt-1 text-sm text-mist">답변 알림을 받을 번호가 필요해요.</p>
            <div class="mt-4 flex justify-center gap-2">
                <a href="{{ route('benefits', ['return' => route('inquiries.index', [], false)]) }}" class="btn-ink">휴대폰 인증</a>
                <a href="{{ route('member.login') }}" class="btn-ghost">회원 로그인</a>
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('inquiries.store') }}" class="mt-6 space-y-4 rounded-3xl bg-white p-5 ring-1 ring-ink/5">
            @csrf
            <div>
                <label for="category" class="label">문의 종류</label>
                <select id="category" name="category" class="field" required>
                    @foreach (\App\Models\Inquiry::CATEGORIES as $k => $v)
                        <option value="{{ $k }}" @selected(old('category') === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="store_id" class="label">매장 <span class="font-normal text-mist">(선택)</span></label>
                    <select id="store_id" name="store_id" class="field">
                        <option value="">고르지 않음</option>
                        @foreach ($stores as $s)
                            <option value="{{ $s->id }}" @selected((int) old('store_id') === $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="order_no" class="label">주문 <span class="font-normal text-mist">(선택)</span></label>
                    <select id="order_no" name="order_no" class="field">
                        <option value="">고르지 않음</option>
                        @foreach ($orders as $o)
                            <option value="{{ $o->order_no }}" @selected(old('order_no') === $o->order_no)>{{ $o->created_at->format('m.d') }} {{ $o->store?->name }} {{ number_format($o->total_amount) }}원</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label for="title" class="label">제목</label>
                <input id="title" name="title" class="field" maxlength="120" value="{{ old('title') }}" required>
                @error('title')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="body" class="label">내용</label>
                <textarea id="body" name="body" rows="5" class="field" maxlength="3000" required>{{ old('body') }}</textarea>
                @error('body')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
            </div>
            <button class="btn-ink w-full">문의 남기기</button>
        </form>

        <h2 class="mt-8 text-lg font-bold">내 문의</h2>
        <ul class="mt-3 space-y-2">
            @forelse ($inquiries as $q)
                <li>
                    <a href="{{ route('inquiries.show', $q) }}" class="flex items-center justify-between gap-3 rounded-2xl bg-white px-4 py-3 ring-1 ring-ink/5">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-bold">{{ $q->title }}</span>
                            <span class="block text-xs text-mist">{{ $q->categoryLabel() }} · {{ $q->created_at->format('Y.m.d') }}</span>
                        </span>
                        <span @class(['chip shrink-0', 'bg-stick/10 text-stick' => $q->isAnswered(), 'bg-paper text-mist' => ! $q->isAnswered()])>{{ $q->isAnswered() ? '답변 완료' : '답변 대기' }}</span>
                    </a>
                </li>
            @empty
                <li class="rounded-2xl bg-white px-4 py-6 text-center text-sm text-mist ring-1 ring-ink/5">남긴 문의가 없어요.</li>
            @endforelse
        </ul>
    @endif
@endsection

{{-- 쿠폰 입력 칸 (운영사·업체 공용). $platform: 운영사면 티켓 쿠폰·공연 선택 가능 --}}
@php($c = $coupon)
<div x-data="{ scope: @js(old('scope', $c->scope ?? 'order')), kind: @js(old('kind', $c->kind ?? 'fixed')) }" class="space-y-5">
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label for="name" class="label">쿠폰 이름</label>
            <input id="name" name="name" class="field" value="{{ old('name', $c->name) }}" placeholder="예: 인터미션 음료 1,000원 할인" required>
            @error('name')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
        @if ($platform)
            <fieldset>
                <legend class="label">쓰는 곳</legend>
                <div class="flex gap-2">
                    @foreach (\App\Models\Coupon::SCOPES as $v => $l)
                        <label class="chip cursor-pointer bg-paper !px-4 !py-2 text-sm"><input type="radio" name="scope" value="{{ $v }}" x-model="scope"> {{ $l }}</label>
                    @endforeach
                </div>
            </fieldset>
        @endif
        <fieldset>
            <legend class="label">할인 방식</legend>
            <div class="flex flex-wrap gap-2">
                @foreach (\App\Models\Coupon::KINDS as $v => $l)
                    <label class="chip cursor-pointer bg-paper !px-4 !py-2 text-sm" x-show="'{{ $v }}' !== 'free_item' || scope === 'order'">
                        <input type="radio" name="kind" value="{{ $v }}" x-model="kind"> {{ $l }}</label>
                @endforeach
            </div>
        </fieldset>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div x-show="kind !== 'free_item'">
            <label for="value" class="label" x-text="kind === 'percent' ? '할인율 (%)' : '할인 금액 (원)'">할인</label>
            <input id="value" name="value" type="number" min="0" class="field num" value="{{ old('value', $c->value) }}">
            @error('value')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
        <div x-show="kind === 'percent'">
            <label for="max_discount" class="label">최대 할인 (원)</label>
            <input id="max_discount" name="max_discount" type="number" min="0" class="field num" value="{{ old('max_discount', $c->max_discount) }}">
        </div>
        <div x-show="kind === 'free_item'" class="sm:col-span-2">
            <label for="product_id" class="label">무료로 줄 메뉴 (1개)</label>
            <select id="product_id" name="product_id" class="field">
                <option value="">고르기</option>
                @foreach ($products as $p)
                    <option value="{{ $p->id }}" @selected(old('product_id', $c->product_id) == $p->id)>{{ $p->store->name }} · {{ $p->name }} ({{ number_format($p->price) }}원)</option>
                @endforeach
            </select>
            @error('product_id')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="min_amount" class="label">최소 주문 금액 (원)</label>
            <input id="min_amount" name="min_amount" type="number" min="0" class="field num" value="{{ old('min_amount', $c->min_amount) }}">
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div x-show="scope === 'order'">
            <label for="store_id" class="label">매장</label>
            <select id="store_id" name="store_id" class="field">
                <option value="">{{ $platform ? '모든 매장' : '우리 모든 매장' }}</option>
                @foreach ($stores as $s)
                    <option value="{{ $s->id }}" @selected(old('store_id', $c->store_id) == $s->id)>{{ $s->name }}{{ $platform && $s->vendor ? ' · '.$s->vendor->name : '' }}</option>
                @endforeach
            </select>
            @error('store_id')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
        @if ($platform)
            <div x-show="scope === 'ticket'">
                <label for="event_id" class="label">공연</label>
                <select id="event_id" name="event_id" class="field">
                    <option value="">모든 공연</option>
                    @foreach ($events as $e)
                        <option value="{{ $e->id }}" @selected(old('event_id', $c->event_id) == $e->id)>{{ $e->starts_at->format('m.d') }} {{ $e->artist }} · {{ $e->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label for="code" class="label">쿠폰 코드 <span class="font-normal text-mist">(입력해서 받는 쿠폰이면)</span></label>
            <input id="code" name="code" class="field num uppercase" value="{{ old('code', $c->code) }}" placeholder="예: ASTERIA2026">
            @error('code')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-4">
        <div><label for="starts_at" class="label">받기 시작</label><input id="starts_at" name="starts_at" type="datetime-local" class="field num" value="{{ old('starts_at', $c->starts_at?->format('Y-m-d\TH:i')) }}"></div>
        <div><label for="ends_at" class="label">끝 (사용 기한)</label><input id="ends_at" name="ends_at" type="datetime-local" class="field num" value="{{ old('ends_at', $c->ends_at?->format('Y-m-d\TH:i')) }}">
            @error('ends_at')<p class="mt-1 text-sm text-signal">{{ $message }}</p>@enderror</div>
        <div><label for="valid_days" class="label">받은 뒤 사용 기간 (일)</label><input id="valid_days" name="valid_days" type="number" min="1" class="field num" value="{{ old('valid_days', $c->valid_days) }}"></div>
        <div><label for="total_limit" class="label">총 발급 수량</label><input id="total_limit" name="total_limit" type="number" min="1" class="field num" value="{{ old('total_limit', $c->total_limit) }}" placeholder="무제한"></div>
    </div>

    <div class="flex flex-wrap gap-5 text-sm">
        <label class="flex items-center gap-2"><input type="checkbox" name="is_public" value="1" @checked(old('is_public', $c->is_public))> 손님 화면에 "받기" 버튼으로 보여 주기</label>
        <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $c->is_active))> 사용 중</label>
    </div>
    <p class="rounded-2xl bg-paper px-4 py-3 text-xs text-mist">
        @if ($platform) 운영사 쿠폰의 할인액은 운영사가 부담해요. 업체 정산에는 할인 전 금액이 그대로 들어가요.
        @else 우리 쿠폰의 할인액은 업체 부담이라 정산에서 빠지고, 수수료는 할인 뒤 금액에 붙어요. 1인 1장만 받을 수 있어요. @endif
    </p>
</div>

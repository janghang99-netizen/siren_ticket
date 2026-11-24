{{-- 보고서 표 1개. $head = 머리 칸, $rows = 셀 배열 배열, $cls = 줄별 class(tot|grand|dtop|dsum), $tcls = 표 class
     셀 = 숫자(오른쪽 정렬·콤마) | 글자 | ['v' => 글자, 'c' => 칸 class(n|sr-ok|sr-mute|sr-bad|sr-no)] --}}
<div class="mb-2 overflow-x-auto">
    <table class="sr-t w-full text-sm {{ $tcls ?? '' }}">
        <tr>@foreach ($head as $h)@if (is_array($h))<th class="{{ $h['c'] }}">{{ $h['v'] }}</th>@else<th>{{ $h }}</th>@endif @endforeach</tr>
        @foreach ($rows as $i => $row)
            <tr class="{{ $cls[$i] ?? '' }}">
                @foreach ($row as $v)
                    @if (is_int($v) || is_float($v))
                        <td class="n">{{ number_format((int) round($v)) }}</td>
                    @elseif (is_array($v))
                        <td class="{{ $v['c'] ?? '' }}">{{ $v['v'] }}</td>
                    @else
                        <td>{{ $v }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
    </table>
</div>

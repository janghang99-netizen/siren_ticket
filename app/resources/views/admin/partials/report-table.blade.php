{{-- 보고서 표 1개. $head = 머리 칸, $rows = 셀 배열 배열 (숫자는 오른쪽 정렬·콤마), $cls = 줄별 tot|grand --}}
<div class="mb-2 overflow-x-auto">
    <table class="sr-t w-full text-sm">
        <tr>@foreach ($head as $h)<th>{{ $h }}</th>@endforeach</tr>
        @foreach ($rows as $i => $row)
            <tr @class(['tot' => ($cls[$i] ?? '') === 'tot', 'grand' => ($cls[$i] ?? '') === 'grand'])>
                @foreach ($row as $v)
                    @if (is_int($v) || is_float($v))
                        <td class="n">{{ number_format((int) round($v)) }}</td>
                    @else
                        <td>{{ $v }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
    </table>
</div>

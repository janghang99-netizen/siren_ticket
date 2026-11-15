<?php

namespace App\Services\Benefits;

use App\Models\BenefitLedger;
use App\Models\Customer;
use App\Models\LedgerAnchor;
use Illuminate\Support\Facades\DB;

/**
 * 혜택 원장 (해시 체인 + 머클 앵커).
 *  - append: 직전 행의 hash 를 prev_hash 로 넣고 이 행의 내용과 함께 sha256 → 한 행이라도 고치면 그 뒤 체인이 모두 깨진다.
 *  - anchor: 아직 묶이지 않은 구간의 머클 루트를 만들어 BlockchainAnchor 로 보낸다(기본은 로컬 기록만).
 *    체인에는 휴대폰 번호 대신 customer_key 만 들어간다.
 *  - idem_key: 같은 주문의 같은 적립·차감은 한 번만 (원본 다담다의 이중 적립·이중 환불 결함 방지).
 */
class BenefitLedgerService
{
    public function __construct(private BlockchainAnchor $chain) {}

    /** 이미 처리된 idem_key 인가 */
    public function done(string $idemKey): bool
    {
        return BenefitLedger::where('idem_key', $idemKey)->exists();
    }

    /** 반드시 호출자의 트랜잭션 안에서 부른다. */
    public function append(Customer $customer, string $kind, string $type, int $amount, ?int $balanceAfter = null,
        ?int $refId = null, ?string $subject = null, ?string $memo = null, ?int $userId = null, ?string $idemKey = null): BenefitLedger
    {
        // 마지막 행을 잠가 체인 연결을 직렬화한다 (동시에 두 행이 같은 prev_hash 를 갖지 않게)
        $prev = BenefitLedger::query()->orderByDesc('id')->lockForUpdate()->first(['id', 'hash']);
        $row = [
            'customer_id' => $customer->id, 'kind' => $kind, 'type' => $type, 'amount' => $amount,
            'balance_after' => $balanceAfter, 'ref_id' => $refId, 'subject' => $subject, 'memo' => $memo,
            'user_id' => $userId, 'idem_key' => $idemKey, 'prev_hash' => $prev?->hash,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];
        $row['hash'] = self::hashOf($row, $customer->customer_key);

        return BenefitLedger::create($row);
    }

    public static function hashOf(array $row, string $customerKey): string
    {
        return hash('sha256', implode('|', [
            $row['prev_hash'] ?? '', $customerKey, $row['kind'], $row['type'], $row['amount'], $row['balance_after'] ?? '',
            $row['ref_id'] ?? '', $row['subject'] ?? '', $row['idem_key'] ?? '', $row['created_at'],
        ]));
    }

    /** @return array{ok:bool, checked:int, broken_at:?int} 체인을 처음부터 다시 계산해 본다 */
    public function verify(): array
    {
        $prev = null;
        $checked = 0;
        foreach (BenefitLedger::with('customer:id,customer_key')->orderBy('id')->lazy() as $r) {
            $row = $r->getAttributes();
            $row['created_at'] = $r->created_at->format('Y-m-d H:i:s');
            if ($r->prev_hash !== $prev || self::hashOf($row, $r->customer->customer_key) !== $r->hash) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $r->id];
            }
            $prev = $r->hash;
            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }

    /** 아직 앵커되지 않은 행들의 머클 루트를 기록한다. 없으면 null. */
    public function anchor(): ?LedgerAnchor
    {
        return DB::transaction(function () {
            $rows = BenefitLedger::whereNull('anchor_id')->orderBy('id')->lockForUpdate()->get(['id', 'hash']);
            if ($rows->isEmpty()) {
                return null;
            }
            $root = self::merkleRoot($rows->pluck('hash')->all());
            [$network, $tx] = $this->chain->publish($root, $rows->first()->id, $rows->last()->id);

            $anchor = LedgerAnchor::create([
                'from_id' => $rows->first()->id, 'to_id' => $rows->last()->id, 'row_count' => $rows->count(),
                'merkle_root' => $root, 'head_hash' => $rows->last()->hash,
                'network' => $network, 'tx_hash' => $tx, 'anchored_at' => now(),
            ]);
            BenefitLedger::whereIn('id', $rows->pluck('id'))->update(['anchor_id' => $anchor->id]);

            return $anchor;
        });
    }

    /** @param  array<int, string>  $hashes */
    public static function merkleRoot(array $hashes): string
    {
        $level = array_values($hashes);
        while (count($level) > 1) {
            $next = [];
            for ($i = 0; $i < count($level); $i += 2) {
                $next[] = hash('sha256', $level[$i].($level[$i + 1] ?? $level[$i]));
            }
            $level = $next;
        }

        return $level[0];
    }
}

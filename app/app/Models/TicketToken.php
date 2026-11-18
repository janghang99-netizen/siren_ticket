<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 좌석 티켓 NFT (1티켓 = 1토큰) */
class TicketToken extends Model
{
    public const STATUSES = ['minted' => '발행됨', 'used' => '입장 완료', 'burned' => '소각(취소)'];

    protected $guarded = ['id'];

    protected $casts = ['transferable' => 'boolean', 'minted_at' => 'datetime', 'used_at' => 'datetime', 'burned_at' => 'datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** 체인 탐색기 링크 (카이아만, 로컬 기록은 null) */
    public function explorerUrl(?string $tx = null): ?string
    {
        if ($this->network === 'local') {
            return null;
        }
        $base = rtrim((string) config('siren.nft.kaia.explorer'), '/');

        return $tx ? "{$base}/tx/{$tx}" : "{$base}/nft/{$this->contract}/{$this->token_id}";
    }

    /** 퍼블릭 블록체인에 기록되는 토큰인가 (local = 다담다 서버 기록: 체인 꺼짐 또는 거주 국가 예외) */
    public function onChain(): bool
    {
        return $this->network !== 'local';
    }

    /** 지갑 주소의 탐색기 화면 — 이 지갑의 토큰·거래를 누구나 본다 */
    public function explorerAddressUrl(): ?string
    {
        return $this->onChain() ? rtrim((string) config('siren.nft.kaia.explorer'), '/').'/address/'.$this->owner_address : null;
    }

    /** 네트워크 이름 (화면용) */
    public function networkLabel(): string
    {
        return match (true) {
            ! $this->onChain() => '다담다 서버 기록',
            str_contains($this->network, 'kairos') => '카이아 테스트넷',
            default => '카이아 메인넷',
        };
    }

    public static function short(string $hex): string
    {
        return strlen($hex) > 12 ? substr($hex, 0, 6).'…'.substr($hex, -4) : $hex;
    }
}

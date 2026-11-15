<?php

namespace App\Services\Benefits;

/** 체인 미정 — DB(ledger_anchors)에만 남긴다. */
class LocalAnchor implements BlockchainAnchor
{
    public function publish(string $merkleRoot, int $fromId, int $toId): array
    {
        return ['local', null];
    }
}

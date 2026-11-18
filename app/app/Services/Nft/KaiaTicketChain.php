<?php

namespace App\Services\Nft;

use App\Models\Setting;
use App\Services\Nft\Evm\Abi;
use App\Services\Nft\Evm\EvmSigner;
use App\Services\Nft\Evm\Hex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 카이아(Kaia) — 기본은 테스트넷 Kairos(체인 ID 1001).
 *  - 운영사 키로 SirenTicket 컨트랙트의 mint/burn/markUsed 를 호출한다. 수수료는 운영사가 낸다(팬은 KAIA 불필요).
 *  - nonce 는 캐시 잠금으로 직렬화한다(동시 발행 시 nonce 충돌 방지). 운영 단계에서는 큐 작업으로 보내 한 줄로 처리한다.
 *  - 운영사 키는 storage/app/.kaia_operator_key (권한 600, 볼륨). 운영 단계에서는 KMS·HSM 서명으로 바꾼다.
 */
class KaiaTicketChain implements TicketChain
{
    public function __construct(private EvmSigner $signer) {}

    public function network(): string
    {
        return (string) config('siren.nft.kaia.network');
    }

    public function contract(): string
    {
        return (string) (config('siren.nft.kaia.contract') ?: Setting::query()->whereKey('kaia_contract_'.$this->network())->value('value'));
    }

    public function createWallet(int $customerId): array
    {
        $key = $this->signer->newKey();

        return ['address' => $key['address'], 'secret' => $key['private']];
    }

    public function mint(string $tokenId, string $to, string $metadataUri, string $metadataHash): string
    {
        return $this->callContract(Abi::call('mint(address,uint256,bytes32)', [['address', $to], ['uint256', (int) $tokenId], ['bytes32', $metadataHash]]));
    }

    public function burn(string $tokenId): string
    {
        return $this->callContract(Abi::call('burn(uint256)', [['uint256', (int) $tokenId]]));
    }

    public function markUsed(string $tokenId): string
    {
        return $this->callContract(Abi::call('markUsed(uint256)', [['uint256', (int) $tokenId]]));
    }

    public function ownerOf(string $tokenId): ?string
    {
        try {
            $res = $this->read(Abi::call('ownerOf(uint256)', [['uint256', (int) $tokenId]]));
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'revert')) {
                return null;   // NoSuchToken — 없는 번호
            }
            throw $e;
        }

        return '0x'.substr(Hex::strip($res), -40);
    }

    // ── 배포·운영 도구 ──

    /** 컨트랙트 배포 트랜잭션을 보내고 해시를 돌려준다 (주소는 영수증에서) */
    public function deploy(string $bytecode, string $baseUri): string
    {
        return $this->send(null, $bytecode.Abi::encode([['string', $baseUri]]), 3_000_000);
    }

    public function receipt(string $txHash): ?array
    {
        return $this->rpc('eth_getTransactionReceipt', [$txHash]);
    }

    public function operatorAddress(): string
    {
        return $this->signer->address($this->operatorKey());
    }

    public function balance(string $address): string
    {
        return (string) $this->rpc('eth_getBalance', [$address, 'latest']);
    }

    public function chainId(): int
    {
        return Hex::toInt($this->rpc('eth_chainId', []));
    }

    /** 읽기 전용 호출 (ownerOf 등) */
    public function read(string $data): string
    {
        return (string) $this->rpc('eth_call', [['to' => $this->contract(), 'data' => $data], 'latest']);
    }

    // ── 내부 ──

    private function callContract(string $data): string
    {
        $to = $this->contract();
        if (! $to) {
            throw new RuntimeException('카이아 컨트랙트 주소가 없어요. php artisan nft:deploy 를 먼저 실행하세요.');
        }

        return $this->send($to, $data);
    }

    private function send(?string $to, string $data, ?int $gas = null): string
    {
        $key = $this->operatorKey();
        $from = $this->signer->address($key);

        return Cache::lock('kaia-nonce:'.$from, 30)->block(20, function () use ($to, $data, $gas, $key, $from) {
            $nonce = Hex::toInt($this->rpc('eth_getTransactionCount', [$from, 'pending']));
            $gasPrice = Hex::toInt($this->rpc('eth_gasPrice', []));
            $gas ??= (int) ceil(Hex::toInt($this->rpc('eth_estimateGas', [array_filter(['from' => $from, 'to' => $to, 'data' => $data])])) * 1.25);
            $raw = $this->signer->signLegacy(['nonce' => $nonce, 'gasPrice' => $gasPrice, 'gas' => $gas, 'to' => $to, 'value' => 0, 'data' => $data],
                $key, (int) config('siren.nft.kaia.chain_id'));

            return (string) $this->rpc('eth_sendRawTransaction', [$raw]);
        });
    }

    private function rpc(string $method, array $params): mixed
    {
        $res = Http::timeout(15)->retry(2, 300, throw: false)->post((string) config('siren.nft.kaia.rpc'), [
            'jsonrpc' => '2.0', 'id' => random_int(1, PHP_INT_MAX), 'method' => $method, 'params' => $params,
        ]);
        if (! $res->ok()) {
            throw new RuntimeException("카이아 RPC 오류 ({$method}): HTTP {$res->status()}");
        }
        if ($res->json('error')) {
            throw new RuntimeException("카이아 RPC 오류 ({$method}): ".$res->json('error.message'));
        }

        return $res->json('result');
    }

    private function operatorKey(): string
    {
        $file = (string) config('siren.nft.kaia.operator_key_file');
        $key = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if (! preg_match('/^(0x)?[0-9a-fA-F]{64}$/', $key)) {
            throw new RuntimeException('카이아 운영사 키가 없어요. php artisan nft:operator 로 만드세요.');
        }

        return $key;
    }
}

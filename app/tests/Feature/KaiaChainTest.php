<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\SeatSection;
use App\Models\TicketToken;
use App\Services\Nft\Evm\Abi;
use App\Services\Nft\Evm\EvmSigner;
use App\Services\Nft\Evm\Rlp;
use App\Services\Nft\KaiaTicketChain;
use App\Services\Nft\NftTicketService;
use App\Services\Nft\TicketChain;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** 카이아 연동: 서명(표준 예제 값), ABI, RPC 흐름(가짜 응답), 거주 국가별 체인 선택, 실패 시 재시도 */
class KaiaChainTest extends TestCase
{
    use RefreshDatabase;

    private function needsGmp(): void
    {
        if (! extension_loaded('gmp')) {
            $this->markTestSkipped('gmp 확장이 없어 서명 테스트는 Docker 에서만 돌린다.');
        }
    }

    public function test_rlp_and_abi_encoding(): void
    {
        $this->assertSame('83646f67', bin2hex(Rlp::encode('dog')));
        $this->assertSame('c88363617483646f67', bin2hex(Rlp::encode(['cat', 'dog'])));
        $this->assertSame('80', bin2hex(Rlp::encode('')));
        $this->assertSame('0f', bin2hex(Rlp::encode(Rlp::int(15))));
        $this->assertSame('820400', bin2hex(Rlp::encode(Rlp::int(1024))));

        $this->assertSame('a9059cbb', Abi::selector('transfer(address,uint256)'));
        $this->assertSame(
            '0000000000000000000000000000000000000000000000000000000000000020'
            .'0000000000000000000000000000000000000000000000000000000000000003'
            .'6162630000000000000000000000000000000000000000000000000000000000',
            Abi::encode([['string', 'abc']]));
    }

    public function test_signer_matches_eip155_and_known_address(): void
    {
        $this->needsGmp();
        $signer = new EvmSigner;
        // 잘 알려진 예제 키 → 주소
        $this->assertSame('0x2c7536e3605d9c16a7a3d7b1898e529396a65c23', $signer->address('4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318'));
        // EIP-155 문서 예제 (chainId 1)
        $raw = $signer->signLegacy(['nonce' => 9, 'gasPrice' => 20000000000, 'gas' => 21000, 'to' => '0x3535353535353535353535353535353535353535',
            'value' => 1000000000000000000, 'data' => ''], str_repeat('46', 32), 1);
        $this->assertSame('0xf86c098504a817c800825208943535353535353535353535353535353535353535880de0b6b3a76400008025a028ef61340bd939bc2195fe537567866003e1a15d3c71ff63e1590620aa636276a067cbe9d8997f761aecb703304b3800ccf555c9f3dc64214b297fb1966a3b6d83', $raw);
    }

    public function test_kaia_mint_sends_signed_transaction_to_contract(): void
    {
        $this->needsGmp();
        $keyFile = storage_path('app/.kaia_test_key');
        file_put_contents($keyFile, str_repeat('46', 32));
        config(['siren.nft.kaia.operator_key_file' => $keyFile, 'siren.nft.kaia.contract' => '0x00000000000000000000000000000000000000aa']);
        $sent = null;
        Http::fake(function ($req) use (&$sent) {
            $m = $req['method'];
            if ($m === 'eth_sendRawTransaction') {
                $sent = $req['params'][0];
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => match ($m) {
                'eth_getTransactionCount' => '0x5', 'eth_gasPrice' => '0x66720b300', 'eth_estimateGas' => '0x186a0',
                'eth_sendRawTransaction' => '0xabc123', default => null,
            }]);
        });

        try {
            $tx = app(KaiaTicketChain::class)->mint('7', '0x2c7536e3605d9c16a7a3d7b1898e529396a65c23', 'https://x/7', str_repeat('ab', 32));
        } finally {
            @unlink($keyFile);
        }
        $this->assertSame('0xabc123', $tx);
        $this->assertNotNull($sent);
        // 받는 쪽이 컨트랙트이고 mint 셀렉터가 들어 있다
        $this->assertStringContainsString('00000000000000000000000000000000000000aa', $sent);
        $this->assertStringContainsString(Abi::selector('mint(address,uint256,bytes32)'), $sent);
    }

    public function test_residence_decides_chain_and_failures_wait_for_retry(): void
    {
        // 체인이 실패하는 가짜 구현 → 카이아 대신 바인딩
        $failing = new class implements TicketChain
        {
            public bool $down = true;

            public function network(): string
            {
                return 'kaia-kairos';
            }

            public function contract(): string
            {
                return '0x00000000000000000000000000000000000000aa';
            }

            public function createWallet(int $customerId): array
            {
                return ['address' => '0x'.str_pad(dechex($customerId), 40, '0', STR_PAD_LEFT), 'secret' => 'secret-'.$customerId];
            }

            public function mint(string $tokenId, string $to, string $metadataUri, string $metadataHash): string
            {
                return $this->tx();
            }

            public function burn(string $tokenId): string
            {
                return $this->tx();
            }

            public function markUsed(string $tokenId): string
            {
                return $this->tx();
            }

            private function tx(): string
            {
                if ($this->down) {
                    throw new RuntimeException('RPC down');
                }

                return '0x'.str_repeat('1', 64);
            }
        };
        $this->app->instance(TicketChain::class, $failing);

        $event = Event::create(['slug' => 'k', 'name' => '투어', 'artist' => '아스테리아', 'starts_at' => now()->addDays(20), 'sales_open_at' => now()->subDay(), 'max_per_buyer' => 4]);
        $section = SeatSection::create(['event_id' => $event->id, 'code' => 'A1', 'name' => 'A1', 'tier' => 'R', 'price' => 1000, 'capacity' => 10, 'ring' => 1, 'start_deg' => 0, 'end_deg' => 30]);

        $kr = $this->makeMember('01012340001');                                                                     // 한국 거주 → 카이아
        $cnInKorea = $this->makeMember('8613800138001', attrs: ['country' => 'CN', 'residence_country' => 'KR']);   // 한국 사는 중국인 → 카이아
        $cnResident = $this->makeMember('8613800138002', attrs: ['country' => 'CN', 'residence_country' => 'CN']); // 중국 거주 → 똑같이 카이아 (거주 국가로 막지 않는다)

        $svc = app(TicketService::class);
        $t1 = $svc->purchase($event, $section, 1, $kr->name, $kr->phone, now(), member: $kr)->first();
        $t2 = $svc->purchase($event, $section, 1, $cnInKorea->name, $cnInKorea->phone, now(), member: $cnInKorea)->first();
        $t3 = $svc->purchase($event, $section, 1, $cnResident->name, $cnResident->phone, now(), member: $cnResident)->first();

        $this->assertSame(['kaia-kairos', 'kaia-kairos', 'kaia-kairos'], [$t1->token->network, $t2->token->network, $t3->token->network]);
        // 체인이 죽어 있어도 예매는 됐고, 카이아 토큰은 대기 + 오류 기록
        $this->assertSame(['pending', 'pending', 'pending'], [$t1->token->status, $t2->token->status, $t3->token->status]);
        $this->assertSame('RPC down', $t1->token->last_error);
        // 지갑 개인키는 DB 에 평문으로 저장되지 않는다
        $this->assertNotSame('secret-'.$t1->customer_id, DB::table('customer_wallets')->where('customer_id', $t1->customer_id)->value('secret'));

        // 대기 중에도 입장은 된다 → 체인이 살아나면 재시도가 발행·입장 표시를 이어서 보낸다
        $nft = app(NftTicketService::class);
        $nft->checkIn($nft->entryCode($t1));
        $failing->down = false;
        $this->assertSame(['mint' => 3, 'burn' => 0, 'used' => 1], $nft->retryPending());
        $this->assertNotNull($t1->token->fresh()->used_tx);
        $this->assertSame(['minted', 'minted'], [$t2->token->fresh()->status, $t3->token->fresh()->status]);
        $this->assertNull(TicketToken::whereNotNull('last_error')->first());

        // 설정에 나라를 넣으면 그 거주 국가만 서버 기록으로 돌릴 수 있다 (기본은 비어 있음)
        config(['siren.nft.offchain_residence' => ['CN']]);
        $this->assertSame(['kaia-kairos', 'local'], [$nft->chainFor($t1)->network(), $nft->chainFor($t3)->network()]);
    }
}

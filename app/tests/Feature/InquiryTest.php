<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\User;
use App\Services\Benefits\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 1:1 문의 — 고객이 남기고, 운영사가 답하면 문자로 알린다 */
class InquiryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0:string,1:string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SmsSender::class, new class($this->sent) implements SmsSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $phone, string $message): void
            {
                $this->sent[] = [$phone, $message];
            }
        });
    }

    private function verifiedAs(string $phone): Customer
    {
        $c = Customer::forPhone($phone);
        $this->withSession(['verified_phone' => $c->phone, 'verified_until' => now()->addHour()->timestamp]);

        return $c;
    }

    public function test_needs_verified_phone_or_member(): void
    {
        $this->get(route('inquiries.index'))->assertOk()->assertSee('휴대폰 인증 후 문의할 수 있어요');
        $this->post(route('inquiries.store'), ['category' => 'etc', 'title' => '질문', 'body' => '내용'])->assertForbidden();
        $this->assertSame(0, Inquiry::count());
    }

    public function test_customer_asks_admin_answers_and_customer_is_notified_once(): void
    {
        $me = $this->verifiedAs('010-1111-2222');
        $this->post(route('inquiries.store'), ['category' => 'order', 'title' => '주문이 안 와요', 'body' => '30분째 기다려요'])
            ->assertSessionHasNoErrors();
        $q = Inquiry::firstOrFail();
        $this->assertSame($me->id, $q->customer_id);
        $this->get(route('inquiries.index'))->assertSee('주문이 안 와요')->assertSee('답변 대기');

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->get(route('admin.inquiries.index'))->assertOk()->assertSee('주문이 안 와요');
        $this->put(route('admin.inquiries.answer', $q), ['answer' => '매장에 확인했어요. 곧 나와요.'])->assertSessionHasNoErrors();
        $this->put(route('admin.inquiries.answer', $q), ['answer' => '매장에 확인했어요. 지금 나왔어요.']);   // 고쳐도 문자는 한 번

        $this->assertCount(1, $this->sent);
        $this->assertSame($me->phone, $this->sent[0][0]);
        $this->assertStringContainsString('[SIREN Ticket] 1:1 문의에 답변이 달렸어요', $this->sent[0][1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inquiry.answer']);

        $this->get(route('inquiries.show', $q))->assertOk()->assertSee('지금 나왔어요');
    }

    public function test_cannot_see_someone_elses_inquiry(): void
    {
        $other = Customer::forPhone('010-9999-0000');
        $q = Inquiry::create(['customer_id' => $other->id, 'category' => 'etc', 'title' => '남의 문의', 'body' => '비밀']);
        $this->verifiedAs('010-1111-2222');
        $this->get(route('inquiries.show', $q))->assertNotFound();
        $this->get(route('inquiries.index'))->assertDontSee('남의 문의');
    }
}

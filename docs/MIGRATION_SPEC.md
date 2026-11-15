# 사이렌오더 마이그레이션 스펙 (다담다 → Laravel 12)

> 작성 2026-11-15 · 원본: `D:\dadamda_arena` (PHP 5.2 + HappyCGI + MariaDB)
> 범위: **다담다에서 사이렌오더(선주문·픽업·대기열·알림)만 추출해 새로 구축.**
> 커뮤니티(캐시팡)·미니홈·유료게시판·마일리지 충전·다국어 미니홈은 범위 밖.

---

## 1. 원본에서 가져온 것 (기능 인벤토리)

| 기능 | 원본 파일 | 새 구현 |
|---|---|---|
| 고객 주문 생성 | `basket_order.php` → `my_pay_market.php` | `POST /api/stores/{store}/orders` → `OrderService::place()` |
| 결제 확정 (유일 진입점) | `inc/lib_pay.php` `pay_market_ok()` | `OrderService::confirmPayment()` |
| 매장 주문보드 | `seller_order_board.php` + `ajax_seller_order_board_v2.php` | `GET /api/store/board` |
| 상태 변경/취소/환불 | `ajax_seller_order_update.php` | `PATCH /api/store/orders/{order}/status` → `OrderService::transition()` |
| 현장판매 POS | `ajax_seller_pos_order.php` | `POST /api/store/pos-orders` (place + confirmPayment) |
| 새 주문 알림 | `ajax_seller_alarm.php` + `openmarket_seller_alarm` | `GET /api/store/notifications` (`store_notifications`) |
| 픽업 전광판 | `order_board_view.php` + `ajax_order_board.php` | `GET /api/stores/{store}/pickup-board` (공개) |
| 수령완료 → 비트EIS 웹훅 | `inc/webhook_order.php` `send_order_webhook()` | `OrderWebhookSender` (주문 수령완료 시 1회) |
| 캐파 게이트 (설계만 존재) | `docs/arena_intermission_capacity_design.md` | `capacity_slots` + 원자적 UPDATE |

## 2. 상태값 — 원본 코드 유지

`OrderStatus` enum의 값은 원본 `product_stats` 숫자를 그대로 쓴다. 비트EIS 연동과 데이터 대조가 쉬워진다.

| 값 | 원본 의미 | enum |
|---|---|---|
| 1 | 미결제 | `Pending` |
| 2 | 결제완료 | `Paid` |
| 3 | 준비중 | `Preparing` |
| 4 | 준비완료 | `Ready` |
| 5 | 수령완료 (=정산예정) | `PickedUp` |
| 6 | 정산완료 | `Settled` |
| 11 | 판매취소 | `Cancelled` |
| 14 | 환불완료 | `Refunded` |

매장 측 허용 전이: `2→3`, `3→4`, `2|3|4→5`, `2→11`(→ 환불 성공 시 즉시 14).
원본 규칙 유지: 준비중 이상으로 넘어간 주문은 판매취소 불가.

## 3. 데이터 모델 (새 스키마)

원본은 `auction_jangboo`(헤더) / `auction_jangproduct`(라인, 상태가 라인마다 있음) / `_ext`(옵션) 구조였다.
사이렌오더에서는 **주문 = 매장 1곳**으로 정한다(원본도 상태를 `gou_number` 단위로 일괄 변경했으므로 동작은 같다).

- `stores` — 매장(원본: 판매자 회원). `code`(=원본 seller_id), `commission_rate`, `is_open`
- `users.store_id` — 매장 로그인 계정 (Sanctum 토큰)
- `products` — `store_id`, `name`, `price`, `stock_deduct_at`(`order`|`payment`|null=재고관리 안 함), `hide_when_sold_out`
- `product_options` — 원본 `auction_product_cate2`. `extra_price`, `stock`(null=무제한)
- `events` — 공연. `starts_at`
- `capacity_slots` — `event_id × store_id × slot(pre|intermission|post)`, `capacity`, `used`, `closes_at`, `is_closed`
- `orders` — `order_no`, `pickup_code`(4자리, 전광판 표시), `store_id`, `capacity_slot_id`, `status`, `channel`(online|pos), `pay_method`, `total_amount`, `commission_rate`(스냅샷), 상태별 시각
- `order_items` — 스냅샷(`name`, `unit_price`, `qty`, `dine_option` 매장|포장, `line_total`)
- `store_notifications` — 원본 `openmarket_seller_alarm`
- `store_webhooks` / `webhook_deliveries` — 원본 `seller_webhook` + `data/webhook_order.log`

## 4. 원본 결함 — 새 구현에서 고친 것

| # | 원본 문제 | 새 구현 |
|---|---|---|
| 1 | 모든 쿼리가 문자열 연결 → SQL 인젝션. `ajax_seller_alarm.php`는 `$_GET[seller_id]`를 그대로 쓰고 **인증이 없음** | 바인딩 쿼리, 매장 API는 전부 Sanctum 인증 |
| 2 | 재고 "조회 후 차감"(check-then-act) → 동시 주문 시 초과판매 | `UPDATE … SET stock = stock - ? WHERE id = ? AND stock >= ?`, 영향 행 0이면 실패 |
| 3 | `pay_market_ok()` 루프 안에서 `$result`를 덮어씀 → **결제완료 시 재고차감(`jaego_change=1`)이 첫 상품에만 적용** | 라인별 루프, 트랜잭션 안에서 처리 |
| 4 | 주문 생성이 트랜잭션이 아님 → 중간 실패 시 고아 행 | `DB::transaction()` |
| 5 | 결제수단을 추가하면 취소 분기 누락 → stats=11에 갇힘 (현장판매 7건 실종 사고) | `PaymentGateway` 인터페이스. 결제수단마다 `refund()`가 있어야 등록됨 |
| 6 | 보드 쿼리 `LIMIT 100` → 새 주문 누락 (2026-06-27 사고) | 활성 상태만 조회 + 수령완료는 5분 이내만, 최신순 |
| 7 | 웹훅 금액 100배 버그 (DECIMAL 문자열 처리) | 금액은 정수(원) 컬럼 |
| 8 | 캐파 게이트 없음 (설계 문서만 존재) | `capacity_slots` 원자적 차감 + 시간 마감 + 수동 마감, 취소 시 반환 |

## 5. 캐파 게이트 (설계 문서 §3~§4, §9 반영)

- 주문 생성 시점에 확인한다(결제 확정 함수에는 넣지 않는다 — 원본 CLAUDE.md 10번 사고).
- 차감: `UPDATE capacity_slots SET used = used + 1 WHERE id = ? AND used < capacity AND is_closed = 0 AND (closes_at IS NULL OR closes_at > NOW())`
- 닫히는 조건: ① 카운트 소진 ② `closes_at` 도달 ③ 매장 수동 마감 — 먼저 오는 것.
- 취소/환불 시 `used - 1`.
- 초기값(매장당): pre 540 / intermission 135 / post 180 — 시더 기본값.

## 6. 결제

- `PaymentGateway` 인터페이스: `refund(Order): bool`.
- 구현: `MockCardGateway`(개발용), `PosGateway`(현장 현금/계좌/카드 — 환불 즉시 성공, 재고 원복).
- 페이레터 실연동은 **키가 필요해 이번 범위에서 제외**. 인터페이스를 구현하면 꽂힌다.
- 결제 확정 콜백 `POST /api/orders/{order_no}/confirm` — 지금은 개발용 mock. 실서비스에서는 PG 서명 검증을 붙인다.

## 7. 비트EIS 웹훅 (원본 payload 호환)

- 시점: 주문이 수령완료(5)가 된 순간 1회. 실패해도 주문 처리는 막지 않는다(원본과 동일).
- 서명: `X-Dadamda-Signature: sha256=<HMAC-SHA256(body, secret)>`
- body: 원본과 같은 평탄 형식 `{"orders":[{"event":"order.completed","order_no",…,"line_total","total_amount"}]}`
- 전송 결과는 `webhook_deliveries`에 저장(원본 로그 파일 대체).

## 8. 범위 밖 / 후속

- 페이레터 실결제 연동 (키 필요)
- 스탬프/쿠폰 (`inc/lib_stamp.php`)
- SMS 알림톡 (원본 `send_sms_socket`)
- 원본 DB 이력 이관 (`auction_jangproduct` → `orders`)
- 티켓 실결제·좌석 지정 예매(현재는 구역 선택 + 자동 좌석 배정), 입장 QR 검표

## 9. 2단계 추가 범위 (2026-11-15)

- **아이돌 공연 티켓 판매**: `events` 확장 + `seat_sections`(보울 좌석도의 호 하나 = 구역) + `tickets`.
  구역 잔여석은 원자적 UPDATE로 차감, 1인 매수 제한은 휴대폰 번호 기준.
- **티켓 = 매장 주문 패스**: `orders.ticket_id` — 매장 보드에 주문자 좌석이 보인다.
- **화면**: 팬 모바일(공연·좌석도·내 티켓·매장 메뉴·결제·픽업번호), 매장 칸반 보드, 픽업 전광판.
  Blade + Tailwind v4 + Alpine. 디자인 토큰은 `resources/css/app.css`.

# SIREN — 공연 티켓 + 공연장 매장 사이렌오더

아이돌 공연 티켓을 팔고, 공연장 안 입점 매장(카페·맥주·베이커리·MD 등)이 모바일 선주문·픽업으로 판매하는 서비스.
다담다(PHP 5.2) 사이렌오더를 Laravel 12로 옮기고 티켓 예매를 붙였다. 이전 내역은 [docs/MIGRATION_SPEC.md](docs/MIGRATION_SPEC.md).

## 화면

| 누가 | 주소 | 하는 일 |
|---|---|---|
| 팬 | `/` | 공연 목록, 좌석도 미리보기 |
| 팬 | `/concerts/{공연}` | 보울 좌석도에서 구역 골라 예매 |
| 팬 | `/tickets` | 내 티켓 (휴대폰 번호로 다시 찾기) → 이 좌석으로 매장 주문 |
| 팬 | `/concerts/{공연}/stores/{매장}` | 시간대(공연 전·인터미션·공연 후) 고르고 주문 → 픽업번호 |
| 매장 | `/staff` | 주문 칸반 (새 주문 → 만드는 중 → 픽업 대기), 시간대별 주문 마감 |
| 매장 앞 모니터 | `/display/{매장}` | 픽업 전광판 |

## 실행

```bash
cd app
php artisan migrate:fresh --seed
npm install && npm run build
php artisan serve
```

- 매장 테스트 계정: `arena1@siren.test` ~ `arena6@siren.test` / `password` (시더가 만드는 개발용 값)
- 미결제 주문 만료(캐파·재고 반환): `php artisan schedule:work`
- 테스트: `php artisan test`

## 아직 안 된 것

- 실결제(페이레터) 연동 — 지금은 개발용 mock 결제. `config/siren.php`의 게이트웨이만 바꾸면 된다.
- 입장 QR 검표, 좌석 직접 지정, SMS 알림톡, 원본 DB 이력 이관.

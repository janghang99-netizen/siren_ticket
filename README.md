# SIREN — 공연 티켓 + 공연장 입점매장 사이렌오더

- **좌석티켓**은 플랫폼 회사(슈퍼어드민)만 운영한다 — 공연 일정을 여러 개 등록하고, 구역별 가격·좌석 수를 정하고, 판매·취소를 관리한다.
- **사이렌오더**는 여러 입점업체가 운영한다 — 한 업체가 여러 매장을 갖고, 매장마다 상품·주문을 관리한다. 운영사는 업체별로 정산한다.

다담다(PHP 5.2) 사이렌오더를 Laravel 12로 옮기고 티켓 예매를 붙였다. 이전 내역은 [docs/MIGRATION_SPEC.md](docs/MIGRATION_SPEC.md).

## 실행 (Docker · MySQL 8.4)

```bash
docker compose up -d --build
```

- 사이트: http://localhost:8090 · 관리 로그인: http://localhost:8090/login
- 첫 기동 시 마이그레이션 + 데모 데이터가 자동으로 들어간다. 처음부터 다시: `docker compose down -v && docker compose up -d --build`
- 미결제 주문 만료(캐파·재고 반환)는 `scheduler` 컨테이너가 매분 돌린다.
- MySQL로 테스트:

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=siren_test -e SESSION_DRIVER=array -e CACHE_STORE=array app php artisan test
```

### 개발용 계정 (시더가 만드는 값, 비밀번호 모두 `password`)

| 역할 | 이메일 | 들어가면 |
|---|---|---|
| 슈퍼어드민 | `admin@siren.test` | 공연 일정 · 티켓 판매 · 입점업체 · 매장 · 전체 주문 · 정산 |
| 입점업체 (매장 3) | `arenafood@siren.test` | 아레나 커피 / 오븐 베이커리 / 드링크 스테이션 |
| 입점업체 (매장 2) | `beerhouse@siren.test` | 비어 스탠드 / 핫도그 키친 |
| 입점업체 (매장 1) | `md@siren.test` | 공식 MD 숍 |

## 화면

| 누가 | 주소 | 하는 일 |
|---|---|---|
| 팬 | `/` → `/concerts/{공연}` | 보울 좌석도에서 구역 골라 예매 |
| 팬 | `/tickets` | 내 티켓 → 이 좌석으로 매장 주문 |
| 팬 | `/concerts/{공연}/stores/{매장}` | 시간대(공연 전·인터미션·공연 후) 고르고 주문 → 픽업번호 |
| 팬 | `/orders` | 주문내역 — 진행 중 / 받은 주문 / 취소 |
| 입점업체 | `/partner` | 매장 전환, 주문보드, 상품 리스트·등록, 판매 내역, 취소 내역, 정산 내역 |
| 슈퍼어드민 | `/admin` | 공연 등록(구역·매장 시간대 자동 생성), 티켓 판매·취소, 업체·매장 등록, 정산서 생성·지급 |
| 매장 앞 모니터 | `/display/{매장코드}` | 픽업 전광판 |

## 정산 규칙

- 기간 안에 **수령완료**된 주문을 업체별로 묶어 정산서를 만든다. 지급액 = 매출 − 수수료(주문 시점 매장 수수료율, 주문별 반올림).
- 묶인 주문은 정산완료로 바뀌고 다시 묶이지 않는다. 환불된 주문은 매출에 들어가지 않는다(정산서에 건수만 참고 표시).
- 운영사가 실제로 송금한 뒤 "지급 완료로 표시"한다.

## 로컬 PHP로 실행 (Docker 없이, SQLite)

```bash
cd app && php artisan migrate:fresh --seed && npm install && npm run build && php artisan serve
```

## 아직 안 된 것

- 실결제(페이레터) 연동 — 지금은 개발용 mock 결제. `config/siren.php`의 게이트웨이만 바꾸면 된다.
- 입장 QR 검표, 좌석 직접 지정, SMS 알림톡, 원본 DB 이력 이관, 업체 계정 추가·비밀번호 변경 화면.

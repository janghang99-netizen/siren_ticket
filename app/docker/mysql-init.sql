-- 테스트 전용 DB (php artisan test 를 MySQL 로 돌릴 때 사용 — 운영 데이터와 분리)
CREATE DATABASE IF NOT EXISTS siren_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON siren_test.* TO 'siren'@'%';
FLUSH PRIVILEGES;

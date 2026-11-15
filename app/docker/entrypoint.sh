#!/bin/sh
# 컨테이너 시작 준비: .env/APP_KEY → DB 대기 → 마이그레이션 → (비어 있으면) 데모 시드
set -e
cd /var/www/html

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

echo "[siren] MySQL(${DB_HOST}:${DB_PORT}) 대기 중..."
until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
    sleep 2
done
echo "[siren] MySQL 연결됨"

# 스케줄러 컨테이너는 마이그레이션을 app 컨테이너에 맡긴다 (동시 실행 방지)
if [ "${SKIP_MIGRATE:-0}" != "1" ]; then
    php artisan migrate --force
    if [ "$(php artisan tinker --execute='echo App\Models\Event::count();' 2>/dev/null | tail -n1)" = "0" ]; then
        echo "[siren] 데모 데이터 시드"
        php artisan db:seed --force
    fi
fi

chown -R www-data:www-data storage bootstrap/cache
exec "$@"

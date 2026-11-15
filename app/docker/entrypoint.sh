#!/bin/sh
# 컨테이너 시작 준비: .env/APP_KEY → DB 대기 → 마이그레이션 → (비어 있으면) 데모 시드
set -e
cd /var/www/html

[ -f .env ] || cp .env.example .env
# 앱 키는 볼륨(storage/app)에 한 번만 만들고 계속 쓴다. 재빌드할 때마다 바뀌면
# 암호화된 2단계 인증 비밀키·세션·쿠키를 못 읽게 된다. app·scheduler 컨테이너가 같은 키를 쓴다.
KEY_FILE=storage/app/.app_key
if [ ! -s "$KEY_FILE" ]; then
    if [ "${SKIP_MIGRATE:-0}" = "1" ]; then
        # scheduler 는 app 이 키를 만들 때까지 기다린다
        until [ -s "$KEY_FILE" ]; do sleep 1; done
    else
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEY_FILE"
        chmod 600 "$KEY_FILE"
    fi
fi
sed -i "s|^APP_KEY=.*|APP_KEY=$(cat "$KEY_FILE")|" .env
grep -q '^APP_KEY=base64' .env || echo "APP_KEY=$(cat "$KEY_FILE")" >> .env

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

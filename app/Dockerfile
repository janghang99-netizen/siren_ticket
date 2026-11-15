# ── 1단계: 프론트 에셋 빌드 (Tailwind v4 + Alpine) ──
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# ── 2단계: PHP 8.3 + Apache ──
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libgmp-dev unzip git \
    && docker-php-ext-install pdo_mysql zip gmp \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# 문서 루트를 Laravel public/ 으로
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# 의존성 레이어 캐시 (검증용 이미지라 테스트 실행을 위해 dev 의존성 포함)
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/siren-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/siren-entrypoint && chmod +x /usr/local/bin/siren-entrypoint

ENTRYPOINT ["siren-entrypoint"]
CMD ["apache2-foreground"]

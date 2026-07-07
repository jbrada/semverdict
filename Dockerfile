# Build stage: resolve dependencies with the official composer image (ships git,
# needed because magento/magento-semver is installed from a VCS repository).
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

FROM php:8.5-cli-alpine
RUN apk add --no-cache libzip \
    && apk add --no-cache --virtual .build-deps libzip-dev \
    && docker-php-ext-install zip \
    && apk del .build-deps

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY bin ./bin
COPY src ./src
COPY resources ./resources

# Run audits from /audit so the default archive cache (./.semverdict-cache)
# lands in a directory the user can bind-mount to persist it between runs.
WORKDIR /audit

ENTRYPOINT ["php", "/app/bin/semverdict"]
CMD ["--help"]

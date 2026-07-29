# Build stage: resolve dependencies with the official composer image (ships git,
# needed because magento/magento-semver is installed from a VCS repository).
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

FROM php:8.5-cli-alpine
RUN apk add --no-cache libzip git \
    && apk add --no-cache --virtual .build-deps libzip-dev \
    && docker-php-ext-install zip \
    && apk del .build-deps
# The `next` command reads a bind-mounted working copy, which is owned by the
# host user rather than the container's root — git refuses that by default.
RUN git config --system --add safe.directory '*'

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY bin ./bin
COPY src ./src
COPY resources ./resources

# Auditing a large repository index or a big release pair needs more than the
# CLI default; override with `docker run -e PHP_MEMORY_LIMIT=…` if a package
# ever needs more still.
ENV PHP_MEMORY_LIMIT=1G
RUN printf 'memory_limit=${PHP_MEMORY_LIMIT}\n' > /usr/local/etc/php/conf.d/zz-memory-limit.ini

# Run audits from /audit so the default archive cache (./.semverdict-cache)
# lands in a directory the user can bind-mount to persist it between runs.
WORKDIR /audit

ENTRYPOINT ["php", "/app/bin/semverdict"]
CMD ["--help"]

FROM php:8.3-cli

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock /app/
COPY lib/ /app/lib/
COPY bin/ /app/bin/

RUN composer install --no-interaction --prefer-dist --no-dev --no-progress

COPY sdk_compliance_adapter/ /app/sdk_compliance_adapter/

EXPOSE 8080

CMD ["php", "/app/sdk_compliance_adapter/adapter.php"]

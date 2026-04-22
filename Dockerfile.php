FROM php:8.3-cli-bookworm

RUN printf 'Acquire::Retries "5";\nAcquire::ForceIPv4 "true";\n' > /etc/apt/apt.conf.d/99codex-network \
  && apt-get update \
  && apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev \
  && docker-php-ext-install pdo_sqlite \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /workspace/php

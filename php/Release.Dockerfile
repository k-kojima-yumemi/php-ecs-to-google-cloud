FROM public.ecr.aws/docker/library/php:8.4
COPY --from=public.ecr.aws/docker/library/composer:2 /usr/bin/composer /usr/bin/composer

RUN <<EOF
apt-get update
apt-get install -y zip unzip --no-install-recommends
apt-get clean
rm -rf /var/lib/apt/lists/*
EOF

WORKDIR /app
COPY composer.json composer.lock /app/
RUN <<EOF
composer install --no-dev
EOF

COPY app/ /app/app/
COPY main.php /app/

LABEL org.opencontainers.image.source=https://github.com/k-kojima-yumemi/php-ecs-to-google-cloud
CMD ["php", "main.php"]

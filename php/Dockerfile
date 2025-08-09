FROM public.ecr.aws/docker/library/php:8.4
COPY --from=public.ecr.aws/docker/library/composer:2 /usr/bin/composer /usr/bin/composer

RUN <<EOF
apt-get update
apt-get install -y zip unzip --no-install-recommends
apt-get clean
rm -rf /var/lib/apt/lists/*
EOF

RUN <<EOF
pecl install xdebug
docker-php-ext-enable xdebug
EOF

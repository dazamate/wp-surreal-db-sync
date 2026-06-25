# PHP 8.5 toolchain image for running the test suite and static analysis.
# The host PHP version is irrelevant — everything runs in here.
FROM php:8.5-cli

# git + unzip: composer needs git for the surrealdb dev-main source install and
# unzip for dist packages. zip is used by bin/build.sh to package the release,
# curl to fetch the php-scoper PHAR. libonig-dev backs the mbstring extension.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip curl libzip-dev libonig-dev \
    && docker-php-ext-install zip mbstring \
    && rm -rf /var/lib/apt/lists/*

# Pull the composer binary straight from the official composer image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# php-scoper is installed as a standalone PHAR (NOT a composer dependency) so its
# own dependency tree can never conflict with the plugin's. bin/build.sh uses it
# to namespace-prefix the bundled vendor libraries for a release. The "latest"
# release URL is a stable redirect; pin a specific tag here for reproducible
# tooling if needed.
RUN curl -fsSL -o /usr/local/bin/php-scoper \
        https://github.com/humbug/php-scoper/releases/latest/download/php-scoper.phar \
    && chmod +x /usr/local/bin/php-scoper

WORKDIR /app

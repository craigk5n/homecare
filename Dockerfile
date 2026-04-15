# HomeCare web image (HC-050)
#
# Apache + PHP 8.2 image with the extensions HomeCare needs (mysqli for
# the legacy dbi4php layer, pdo_mysql + pdo_sqlite for the new
# repository layer / tests). Composer is installed and `composer
# install --no-dev` runs at build time so the image is self-contained.
#
# settings.php is generated at container start from environment
# variables by /usr/local/bin/docker-entrypoint.sh — committing a
# settings.php would defeat the point.

FROM php:8.3-apache

# System packages: build tools for ext compile, mysql client for
# entrypoint healthcheck, libsqlite for tests, libzip for composer.
RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client \
        libzip-dev \
        libsqlite3-dev \
        unzip \
        git \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions: mysqli for production DB; pdo_mysql + pdo_sqlite for
# the abstraction layer; zip for Composer.
RUN docker-php-ext-install -j$(nproc) \
        mysqli \
        pdo \
        pdo_mysql \
        pdo_sqlite \
        zip

# Apache: rewrite (so the api/v1 .htaccess works), AllowOverride All on
# the docroot so .htaccess files take effect.
RUN a2enmod rewrite \
    && sed -ri -e 's!<Directory /var/www/>!<Directory /var/www/>\n    AllowOverride All!' /etc/apache2/apache2.conf

# Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy app sources (the .dockerignore keeps vendor/ and dumps out).
COPY . /var/www/html/

# Install runtime deps only (no PHPUnit/PHPStan in the image).
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Make the writable dirs writable by Apache.
RUN chown -R www-data:www-data /var/www/html

# Entrypoint: render settings.php from env, optionally seed the DB,
# then hand off to the upstream apache2-foreground.
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

EXPOSE 80

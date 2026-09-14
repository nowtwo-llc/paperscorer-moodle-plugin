# PHP image for the ps-moodle development instance.
#
# moodlehq/moodle-php-apache only publishes up to 8.4, so anything newer needs
# building here. PHP_VERSION is a build arg: drop it back to 8.4 (the highest
# version any released Moodle is tested against) if a newer PHP breaks Moodle
# core rather than the plugin.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip locales libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libicu-dev libzip-dev libxml2-dev libxslt1-dev libsodium-dev \
        libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Moodle's PHPUnit bootstrap refuses to run without en_AU.UTF-8 (it asserts a
# known locale so date/number formatting is deterministic across machines).
# The official moodlehq image ships these; a stock php image does not.
RUN sed -i -E 's/^# *(en_AU\.UTF-8|en_US\.UTF-8)/\1/' /etc/locale.gen \
    && locale-gen

# opcache is handled separately: on PHP 8.5 it is compiled statically into the
# binary, so docker-php-ext-install produces no shared module and fails; on 8.4
# and earlier it is a normal shared extension.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd intl zip mysqli exif soap xsl sodium \
    && (docker-php-ext-install opcache || docker-php-ext-enable opcache || true)

# Moodle requires max_input_vars >= 5000; the rest keeps CLI installs and
# PHPUnit from tripping over defaults.
RUN { \
      echo 'max_input_vars = 5000'; \
      echo 'memory_limit = 512M'; \
      echo 'post_max_size = 128M'; \
      echo 'upload_max_filesize = 128M'; \
    } > /usr/local/etc/php/conf.d/moodle.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Moodle 5.0+ moved the web root into public/; 4.x serves from the tree root.
# Make DocumentRoot configurable so one image serves both layouts.
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && a2enmod rewrite

WORKDIR /var/www/html

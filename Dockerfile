# ---------------------------------------------------------------------------
#  Application image: PHP 8.2 + Apache + pdo_mysql
#  The document root is public/ so that src/, db/ and config.php are not
#  reachable over HTTP.
# ---------------------------------------------------------------------------
FROM php:8.2-apache

# Install the MySQL PDO driver and enable Apache modules / rewrite.
RUN docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite \
    && apt-get update \
    # mysql client for the entrypoint; curl for the container healthcheck
    && apt-get install -y --no-install-recommends default-mysql-client curl \
    && rm -rf /var/lib/apt/lists/*

# Apache document root -> public/
# The two sed expressions are intentionally different (see the official
# php:apache image docs): sites-available/*.conf contain "/var/www/html",
# while apache2.conf and conf-available/* use the broader "/var/www/".
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

# Recommended PHP settings for the app.
RUN { \
        echo 'display_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'error_log=/dev/stderr'; \
        echo 'upload_max_filesize=8M'; \
        echo 'post_max_size=8M'; \
    } > /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

# Copy the whole project, then make src/ and db/ readable by the webserver.
COPY . /var/www/html

# Entry point waits for MySQL and (optionally) loads schema + demo data.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]

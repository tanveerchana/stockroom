FROM php:8.3.36-apache-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev libonig-dev \
    && docker-php-ext-install pdo_sqlite mbstring \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . .
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/stockroom.ini
RUN chmod +x bin/start.sh \
    && mkdir -p storage/sessions \
    && chown -R www-data:www-data storage
ENV APP_ENV=production STOCKROOM_EPHEMERAL_HOSTING=false PORT=10000 DB_PATH=/var/www/html/storage/stockroom.sqlite SESSION_PATH=/var/www/html/storage/sessions
EXPOSE 10000
CMD ["/var/www/html/bin/start.sh"]

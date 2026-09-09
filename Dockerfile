FROM php:8.3-apache-bookworm
RUN apt-get update \
 && apt-get install -y --no-install-recommends libcurl4-openssl-dev libsqlite3-dev \
 && docker-php-ext-install pdo_sqlite curl \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . /var/www/html
RUN mkdir -p data \
 && chown -R www-data:www-data data \
 && sed -ri 's#/var/www/html#/var/www/html/web#g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's#/var/www/html#/var/www/html/web#g' /etc/apache2/apache2.conf \
 && printf 'default_socket_timeout=15\n' > /usr/local/etc/php/conf.d/portal.ini
EXPOSE 80
CMD ["apache2-foreground"]

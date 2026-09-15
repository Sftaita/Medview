FROM dunglas/frankenphp:1-php8.3

RUN install-php-extensions \
    pdo_pgsql \
    intl \
    opcache \
    zip

WORKDIR /app

# vendor/ is baked into the image (composer install already ran on the host)
# so the container never needs network access to packagist during the build.
COPY . .

ENV SERVER_NAME=:80
EXPOSE 80

FROM dunglas/frankenphp:1-php8.3

RUN install-php-extensions \
    pdo_pgsql \
    intl \
    opcache \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# OR-Tools CP-SAT has no official PHP bindings (docs/decisions.md D031,
# docs/planning-solver.md) — the real solver runs as a Python subprocess
# invoked by OrToolsPlanningSolver, never linked into the PHP process. A
# dedicated venv avoids Debian's PEP 668 "externally managed environment"
# restriction on the system Python without --break-system-packages.
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 \
    python3-venv \
    && rm -rf /var/lib/apt/lists/* \
    && python3 -m venv /opt/ortools-venv \
    && /opt/ortools-venv/bin/pip install --no-cache-dir ortools==9.15.6755

WORKDIR /app

# vendor/ is baked into the image (composer install already ran on the host)
# so the container never needs network access to packagist during the build.
COPY . .

ENV SERVER_NAME=:80
EXPOSE 80

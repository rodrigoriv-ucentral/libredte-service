FROM php:8.5-cli

# pcntl: PHP_CLI_SERVER_WORKERS (variable de entorno del contenedor) necesita esta
# extensión para que `php -S` atienda varias peticiones a la vez; sin ella la
# variable se ignora en silencio y el servidor sigue siendo de un solo
# proceso (una sesión larga de /caf/solicitar bloquearía el resto).
RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libzip-dev \
        libxml2-dev \
        libssl-dev \
        libsqlite3-dev \
        libonig-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install soap pdo_sqlite mbstring gd bcmath intl pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize

# El config/services.yaml de libredte-lib-core importa sus dependencias con
# rutas "../vendor/...", escritas asumiendo que la librería es la raíz del
# proyecto. Al usarla como dependencia esas rutas no resuelven, así que se
# apunta su vendor/ al nuestro. Sin esto el contenedor DI queda vacío y no se
# puede emitir ni enviar ningún DTE. Ver config/services.yaml.
RUN ln -sfn /app/vendor /app/vendor/libredte/libredte-lib-core/vendor

# Mismo problema de raíz, otro síntoma: Derafu\Kernel\Environment::getProjectDir()
# calcula el "project_dir" subiendo 3 niveles desde symfony/dependency-injection
# en vendor/, así que siempre apunta a /app (nuestro proyecto), nunca a
# vendor/libredte/libredte-lib-core. Todo lo que libredte-lib-core busca bajo
# "{project_dir}/resources/..." (catálogos de tipos de documento, formas de
# pago, plantillas de PDF, esquemas XSD, WSDL) se rompe sin este symlink: la
# emisión falla con "Failed to open stream" apenas toca cualquiera de esos
# datos. Se descubrió al construir el Libro de Compra/Venta, pero afecta
# igual a la emisión normal de DTE (bill()/render()).
RUN ln -sfn /app/vendor/libredte/libredte-lib-core/resources /app/resources

RUN mkdir -p /data/certs /data/caf /data/db

# Muchos certificados .pfx emitidos por CAs chilenas (E-Sign, Acepta,
# Certinet, etc.) usan cifrado PKCS12 antiguo (RC2/3DES) que OpenSSL 3.x
# bloquea por defecto al no cargar el proveedor "legacy". Sin esto,
# openssl_pkcs12_read() falla con un error genérico que PHP no distingue
# de una clave incorrecta, aunque la clave sea correcta.
# Al listar un proveedor explícito, OpenSSL deja de activar "default" de
# forma implícita (ver comentario original en openssl.cnf) — hay que
# activarlo a mano o se rompe el resto de la criptografía (TLS incluido).
RUN sed -i \
    -e '/^\[provider_sect\]/a legacy = legacy_sect' \
    -e 's/^# activate = 1$/activate = 1/' \
    /etc/ssl/openssl.cnf \
    && printf '\n[legacy_sect]\nactivate = 1\n' >> /etc/ssl/openssl.cnf

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]

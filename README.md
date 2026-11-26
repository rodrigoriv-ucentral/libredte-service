# libredte-service

Microservicio HTTP (PHP) para emitir y gestionar Documentos Tributarios
Electrónicos (DTE) del SII de Chile: factura (33), boleta (39), nota de débito
(56) y nota de crédito (61). Timbra, firma y envía al SII, administra folios y
CAF, genera el PDF y la mensajería de intercambio, RVD y Libro de Compra/Venta.

Está construido sobre la biblioteca
[LibreDTE lib-core](https://github.com/LibreDTE/libredte-lib-core), que se
distribuye bajo la licencia AGPL-3.0. Este servicio se publica bajo la misma
licencia para cumplir con ella.

## Construir y ejecutar

Requisitos: Docker.

```bash
docker build -t libredte-service .
cp .env.example .env    # completa tus valores
docker run --rm -p 8080:8080 --env-file .env \
  -v libredte-data:/data libredte-service
```

- `GET /health` responde `{"ok":true}`.
- El resto de las rutas exige `Authorization: Bearer <LIBREDTE_API_TOKEN>`
  (ver `public/index.php`).
- El certificado digital (`/data/certs`), los CAF (`/data/caf`) y las bases
  SQLite (`/data/db`) viven en el volumen `/data`. Nunca los agregues al
  repositorio.

No expongas el puerto del servicio a internet: está pensado para ser llamado
solo por tu aplicación, dentro de una red privada.

## Licencia

Copyright (C) AtlasLabs.

Este programa es software libre: puedes redistribuirlo y/o modificarlo bajo
los términos de la GNU Affero General Public License publicada por la Free
Software Foundation, versión 3 o (a tu elección) cualquier versión posterior.
Se distribuye sin ninguna garantía. Ver el archivo [LICENSE](LICENSE).

Biblioteca original: https://github.com/LibreDTE/libredte-lib-core

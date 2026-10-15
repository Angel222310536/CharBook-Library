# CharBook Library — Comunidad Premium · Roadmap

Estado base: **Fase A construida** (2026-05-30). Documento de seguimiento para las fases B y C.

---

## Principio rector

No copiar Goodreads / Facebook / Reddit / Wattpad / StoryGraph. La comunidad de CharBook gira en torno a **un solo verbo: leer**. El feed no es un muro de texto: es una corriente de *lecturas* (empezó, terminó, reseñó, guardó) con el libro como objeto central y reacciones ligeras. Elegante, premium, mobile-first, tipo app nativa.

---

## ✅ FASE A — Motor de comunidad (COMPLETADA)

Objetivo: el bucle de retorno diario. Construido y verificado (typecheck limpio).

### Base de datos / migraciones
- `book_user` — estante de lectura por usuario: `status` (want_to_read · reading · read), `started_at`, `finished_at`. Único por (user, book).
- `activities` — eventos del feed: `type`, `book_id`, `meta` (json), contadores denormalizados `likes_count` / `comments_count` / `shares_count`. Índices por autor+fecha y fecha global.
- `activity_likes`, `activity_comments`, `activity_shares` — reacciones (con únicos por usuario donde aplica).

### Modelos Laravel
- `Activity` (+ `Activity::log()` que deduplica por usuario+tipo+libro y refresca recencia), `ActivityComment`.
- `User`: relaciones `shelf()`, `currentlyReading()`, `readBooks()`, `activities()`.
- Hooks: `FavoriteController` y `ReviewController` registran actividad automáticamente.

### Endpoints API
- Estado de lectura: `PUT/DELETE /books/{slug}/status`, `GET /me/shelf`.
- Feed: `GET /feed?scope=following|discover`.
- Reacciones: `POST /activities/{id}/like`, `/share`, `/comments`, `DELETE /comments/{id}`, `GET /activities/{id}` y `/comments`.
- Lectores: `GET /users/search?q=`.
- Perfil enriquecido: `currently_reading`, `read_books` y stats (leyendo/leídos) en `/users/{username}`.

### Frontend Next.js
- Página `/comunidad` (feed Siguiendo/Descubrir, paginación, skeletons, buscador de lectores en aside, responsive).
- `ActivityCard` premium: verbo + libro + tiempo relativo, like/comentar/compartir, comentarios con carga diferida.
- `ReadingStatusButton` en la ficha de libro (Quiero leer / Leyendo / Leído / Quitar).
- `ReaderSearch` con seguir en línea.
- Secciones "Leyendo ahora" y "Leídos" + stats en el perfil Atelier.
- Enlace **Comunidad** en el header.

> Pendiente operativo: correr `php artisan migrate` (5 migraciones nuevas).

---

## 🔜 FASE B — Internacionalización + perfil premium

Objetivo: alcance global y pulido visual de identidad.

1. **i18n con `next-intl`** (estándar para App Router de Next 16).
   - Selector de idioma en el header (esquina superior derecha): ES · EN · PT · FR · DE · IT.
   - Estructura `messages/{locale}.json`; envolver toda la UI con `useTranslations`.
   - Persistencia: cookie `NEXT_LOCALE` + campo `locale` en `users` (preferencia por usuario, sincronizada vía `PATCH /me/profile`).
   - Middleware de routing localizado (o estrategia sin prefijo con cookie).
2. **Perfil premium**: `banner_url` (subida a R2), `country`, `primary_language`, fecha de registro destacada, cabecera con banner + avatar superpuesto.
3. **Tema claro/oscuro** ya cableado (`next-themes`); añadir toggle visible y microanimaciones de transición.

Migraciones B: añadir `locale`, `country`, `primary_language`, `banner_url` a `users`.

> Avance 2026-06-10: los bloques `nav` y `header` (añadidos a `es.json`/`en.json` el 09-06) se replicaron traducidos a `de/fr/it/ja/pt`, así el header no cae al fallback al cambiar de idioma. **Pendiente**: el resto de claves de `de/fr/it/ja/pt` siguen siendo copias en español sin traducir (`common`, `language`, `creator`, `admin`, `report`).

---

## 🔜 FASE C — Profundidad social + notificaciones

Objetivo: densidad de red y retención.

1. **Notificaciones con sustancia**: tabla `notifications`, eventos (te siguió, comentó/likeó tu actividad, alguien que sigues empezó un libro), campana en header, marcado leído. Redis para fan-out/cola.
2. **Sugerencias de lectores** ("a quién seguir": por libros en común / leídos compartidos).
3. **Feed "Para ti"**: ranking ligero por afinidad (sin IA todavía — heurística por solapamiento de estantes).
4. **Realtime opcional**: contadores en vivo con polling o websockets.

---

## Notas técnicas

- **Guard en rutas públicas**: el guard por defecto es `web`; para detectar al espectador en rutas públicas (perfil, feed, actividad) se usa `$request->user('sanctum')` en resources/controllers.
- **Rendimiento del feed**: contadores denormalizados en `activities` + `withExists` para el estado del espectador; cuando crezca, mover el fan-out a Redis (ya disponible).
- **Búsqueda de lectores**: hoy con `ILIKE` sobre Postgres; si escala, indexar usuarios en Meilisearch como ya se hace con libros.
- **Deduplicación de actividad**: `Activity::log()` evita spamear el feed cuando alguien re-edita una review o re-marca estados.

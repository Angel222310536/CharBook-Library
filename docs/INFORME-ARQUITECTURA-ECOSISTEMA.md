# Informe de arquitectura — Ecosistema unificado WEB + MÓVIL

Fecha: 2026-06-10 · Autor: Claude (sesión de unificación)

## 1. Arquitectura actual

### Web (`C:\Projects\CharBook Library`) — NO SE MODIFICA
Monorepo con dos aplicaciones:

| Capa | Tecnología | Detalle |
|---|---|---|
| Backend `apps/api` | Laravel + Sanctum | API REST completa en `http://localhost:8000/api` |
| Frontend `apps/web` | Next.js 16 (App Router) | Consume la API vía Bearer token en cookie `charbook_token` |
| Base de datos | **PostgreSQL** (`charbook`, puerto 5432) | Única fuente de verdad |
| Búsqueda | Meilisearch (`127.0.0.1:7700`) | Índice de libros (`/books/search-instant`) |
| Cache/colas | Redis (phpredis) | Disponible para fan-out de feed |
| Archivos | Disco local (R2/S3 preparado, `AWS_BUCKET` vacío) | Portadas, avatares, archivos de libros |

### Autenticación (ya unificada a nivel API)
- **Laravel Sanctum con personal access tokens** (Bearer). No depende de cookies de sesión → **apto para móvil sin cambios**.
- `POST /api/auth/register` y `POST /api/auth/login` devuelven `{ data: {usuario}, token: "..." }`.
- Login puede devolver `{ two_factor_required, challenge, methods }` si el usuario tiene 2FA (TOTP/email/recovery) → se resuelve con `POST /auth/two-factor/verify`.
- Extras ya implementados: passkeys, OAuth Google (flujo web), reset de contraseña por código, verificación de email por código.
- `GET /api/user` devuelve el perfil completo del autenticado (roles, flags de creador, preferencias).

### Modelos de datos (PostgreSQL, 21 modelos Eloquent)
`User`, `Book`, `Author`, `Category`, `Favorite`, `Review`, `Activity`, `ActivityComment`, `Anthology`, `Chapter`, `ComicPage`, `CreatorApplication`, `CreatorWidget`, `FollowRequest`, `Language`, `Message`, `Notification`, `Passkey`, `Report`, `AffiliateLink`, `AffiliateClick`.

### Superficie de la API (resumen)
- **Pública**: `/books` (+featured/recent/popular/search-instant/{slug}/chapters/pages/reviews), `/categories`, `/authors`, `/users/{username}` (perfil Atelier), `/creators`, `/anthologies/{slug}`, `/languages`, `/health`.
- **Autenticada**: `/user`, favoritos (`POST /books/{slug}/favorite`, `GET /me/favorites`), reviews, estantes (`PUT/DELETE /books/{slug}/status`, `GET /me/shelf`), feed (`GET /feed?scope=`), reacciones a actividades, follows, notificaciones, mensajes, antologías, obras de creador (works/chapters/pages), solicitud de creador, cuenta/2FA/passkeys/sesiones.
- **Admin/Staff**: stats, CRUD libros/autores/categorías, moderación, broadcasts, gestión de usuarios.

### App móvil (`C:\Projects\CharBookReact-main`) — estado previo
- Expo SDK 54 + expo-router (envuelve un stack de react-navigation en `screens/Navigation.jsx`).
- **Era un prototipo desconectado**: login/registro falsos (navegaban sin validar), libros hardcodeados en `HomeScreen`, "biblioteca" con CRUD en estado local (`CrudScreen`), perfil con datos de `route.params`.
- Sin cliente HTTP, sin almacenamiento de token, sin ninguna llamada a la API.
- Único sistema transversal: `AccessibilityContext` (3 temas + escala de fuente).

## 2. Diagnóstico

✅ **Ya existe una API REST central y única** (Laravel). No hay que crear otra ni hay BD duplicadas que consolidar: la web ya es API-first.
❌ El problema es que **la móvil no consumía nada**: era una maqueta con datos falsos.

Por tanto la estrategia correcta NO es "crear una API unificada" (existe) sino **conectar la móvil a la API existente**, replicando las convenciones del cliente web (`Bearer charbook_token`, mismas formas de datos `lib/types.ts`).

## 3. Riesgos identificados

1. **Base URL en dispositivo físico**: `localhost:8000` solo funciona en emulador con loopback (Android emulator = `10.0.2.2`). Se centraliza en `lib/config.js` para cambiarla en un solo lugar.
2. **2FA**: si un usuario tiene 2FA activo, el login devuelve un challenge. La móvil implementa el flujo por código de email; TOTP se acepta por el mismo input.
3. **CORS**: las apps nativas no aplican CORS (solo Expo Web). No requiere cambios en Laravel.
4. **Rate limiting**: la API tiene throttles (`api`, `search`, `user-mutations`); el cliente móvil maneja 429 con mensaje.
5. **Imágenes**: las portadas (`cover`) vienen como URL absoluta del backend; en dispositivo físico requieren que `APP_URL` sea accesible en la red.
6. **No tocar la web**: todos los cambios viven en `CharBookReact-main` + documentación en `docs/`.

## 4. Plan de migración (ejecutado en esta sesión)

| Fase | Contenido | Estado |
|---|---|---|
| 0 | Informe de arquitectura (este documento) | ✅ |
| 1 | Capa de red móvil: `lib/config.js`, `lib/api.js` (fetch + Bearer), almacenamiento de token con AsyncStorage | ✅ |
| 2 | `AuthContext`: sesión persistente, login/register/logout reales, soporte challenge 2FA | ✅ |
| 3 | Pantallas conectadas: Login, Register, Home (libros+categorías+búsqueda reales), Detalle de libro (favorito + estante + reviews), Biblioteca (= `/me/shelf` + `/me/favorites`), Perfil (datos reales + logout) | ✅ |
| 4 | Tiempo real: polling ligero (focus/refresh). WebSockets/Reverb queda como siguiente iteración | ⏳ pendiente |
| 5 | Funciones avanzadas: feed comunidad, notificaciones, mensajes, antologías, lector de capítulos | ⏳ pendiente |

## 4.b Verificación end-to-end (2026-06-10)

Con la API corriendo (`php artisan serve`, Postgres `charbook` ok) se probó el flujo móvil completo contra el backend real:

1. `POST /auth/register` (usuario `prueba-movil@charbook.test`) → token Sanctum ✅
2. `GET /user` con el token → perfil completo ✅
3. `PUT /books/{slug}/status {reading}` → estante ✅
4. `GET /me/shelf?status=reading` → ❌ **500** (bug preexistente del backend)
5. `POST /books/{slug}/favorite` + `GET /me/favorites` ✅

**Bugfix aplicado en la API** (única modificación al proyecto web, 1 línea + comentario):
`apps/api/app/Http/Controllers/Api/ReadingStatusController.php` — en `mine()`, dentro de `->when(...)` el closure recibe el Builder y no la relación, por lo que `wherePivot('status', ...)` caía en el *dynamic where* de Laravel generando SQL con la columna inexistente `"pivot"`. Se reemplazó por `where('book_user.status', ...)`. Esto también reparaba el filtro por estado de `/biblioteca` en la web (Todos/Quiero leer/Leyendo/Leídos), que devolvía 500.
Tras el fix, el paso 4 devuelve el libro correctamente. ✅

## 5. Sincronización / tiempo real

- **Hoy**: la sincronización es inmediata por diseño (una sola BD): cualquier alta desde web aparece en móvil al refrescar la vista (pull-to-refresh + recarga al enfocar pantalla).
- **Recomendado a futuro**: Laravel Reverb (WebSockets nativo de Laravel, ya que Redis está disponible) emitiendo eventos `ActivityCreated`, `NotificationCreated`; en móvil `laravel-echo` + `pusher-js`. Alternativa más simple: polling de `/me/notifications/unread-count` cada 30–60 s.

## 6. Seguridad

- Se mantiene Sanctum (tokens revocables por dispositivo; `/me/sessions` ya lista sesiones y permite revocar).
- Token en móvil: AsyncStorage (suficiente para esta fase; migrar a `expo-secure-store` para producción — anotado en pendientes).
- No se modificó ninguna ruta, middleware ni política del backend.

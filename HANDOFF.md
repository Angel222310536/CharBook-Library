# CharBook Library — Handoff de trabajo reciente

> Documento para que otro agente (Codex) o desarrollador continúe sin contexto previo.
> Última actualización: **2026-06-18**. Monorepo: `apps/api` (Laravel 12 / PHP 8.2,
> Postgres, Sanctum) + `apps/web` (Next.js 16.2.6, App Router, **Turbopack**, React 19,
> react-query v5, Tailwind, axios). Idioma del producto: español.

---

## ⭐ Premium / Premium Plus — capa de entitlements (NUEVO, 2026-06-18)

Sistema de planes con permisos reales + UI + placeholders (sin IA externa todavía).
Planes y rango: **free(0) < premium(1) < premium_plus(2)**.

### Fuente de verdad
- **`apps/api/app/Services/Entitlements.php`** — mapa `FEATURES` (feature → tier
  mínimo) + `COMING_SOON` + `can()/state()/map()/isPremium()/isPremiumPlus()`.
  El plan sale de `UnlockService::activePlan()` (suscripción activa o `subscription_tier`).
- **`/api/user`** ahora devuelve `plan`, `is_premium`, `is_premium_plus`, `entitlements`
  (mapa por feature con `{can,state,requires}`).
- Front: **`apps/web/hooks/use-entitlements.ts`** (`can/state/requires/isPremium/isPremiumPlus`),
  **`components/premium/feature-gate.tsx`** (`<FeatureGate>`, `<FeatureLock>`, `<FeatureStateChip>`
  con estados Disponible / Requiere Premium / Requiere Premium Plus / Próximamente),
  **`components/premium/plus-badge.tsx`** (insignia).

### Seguridad (Tarea M) — server-side, no solo UI
- Middleware **`entitlement:<feature>`** (`app/Http/Middleware/EnsureEntitlement.php`,
  alias en `bootstrap/app.php`) → 403 si el plan no alcanza. Aplicado a los endpoints Plus.
- Tests: **`tests/Feature/EntitlementsTest.php`** (5) + early-access en
  `MonetizationBehaviorTest`. Suite completa: **102 passing**.

### Cómo quedó cada función
- **A) Temas premium / B) Tipografías premium** (Premium): en el lector
  `components/reader/premium-reader.tsx`. 3 básicos para todos + `PremiumPicker`
  (temas `noche/crema`, fuentes `garamond/literata/humanist`) bloqueado con candado
  para free; `effectiveTheme/effectiveFont` ignoran opciones premium si se pierde el plan.
- **G) Acceso anticipado** (SOLO Plus): `UnlockService::individualAccess` — `premium_plus`
  accede a todo; `premium` accede a todo lo de pago **menos** los capítulos en ventana
  early (reason `early_access`). Verificado con test.
- **D) Audiolibros IA** + **E) Voces IA** (Plus): botones SEPARADOS en el toolbar del
  lector (`components/reader/ai-audiobook-button.tsx`): `AiAudiobookButton` (voces, se
  guardan vía PATCH `/me/plus/voice`) y `AiSummaryButton` (resumen IA, aparte). El modal
  va en **portal a `document.body`** (`PlusModal`) — necesario porque el toolbar usa
  `transform` y rompía el `fixed` (se veía pegado arriba). No-Plus ve paywall Plus.
- **J) Resumen IA** (Plus): ahora es su PROPIO botón/modal (no dentro del de audiolibro),
  con "Resumen del libro" + "Resumen del capítulo" en **próximamente**.
- **F) Perfil avanzado** (Plus): **HECHO + rediseñado (2026-06-22)**. `lib/profile-banners.ts`
  tiene **10 fondos** premium (aurora, biblioteca, oceano, bosque, pergamino, noche,
  obsidiana, dorado, nebulosa, atelier) generados 100% con CSS (gradientes multicapa,
  brillos, estrellas, líneas) — sin imágenes. `PlusController::PROFILE_BANNERS` lista los
  mismos 10. En `app/ajustes/perfil`: categoría "Fondos Premium Plus", vista previa grande
  (swatches h-20 + portada live), altura reducida (~80px), hover, opción "Sin banner";
  no-Plus la ve bloqueada (visible). El perfil `app/u/[username]` se reestructuró en un
  **header integrado** (banner + foto superpuesta + nombre/insignias como parte del header,
  no un rectángulo aparte). Guardar: `/me/plus/profile`. `pergamino` es claro (`light:true`
  → texto oscuro). Para añadir un fondo: agrégalo en `profile-banners.ts` Y en
  `PlusController::PROFILE_BANNERS` (deben cuadrar).
- **H) Insignia Premium Plus**: **HECHA** en perfil público, **reseñas** (`reviews-section.tsx`)
  y **comunidad** (`activity-card.tsx`: autor del post + autor del comentario). Backend:
  `ReviewResource` / `ActivityResource` / `ActivityCommentResource` exponen
  `user.is_premium_plus` vía `Entitlements::isPremiumPlus()` (la verdad — NO `subscription_tier`,
  que puede estar desincronizado vs una Subscription activa). Nota: N+1 por autor en listas
  (aceptable a esta escala; optimizar con eager-load de subs si crece).
- **I) Apoyo a creadores / K) Acceso prioritario**: reflejados en `/premium` (tabla +
  tarjetas). Sin sección dedicada aparte todavía.
- **L) Página /premium**: tabla comparativa y tarjetas reescritas con las features reales.
  **Sin CharCoins** (siguen en `/monedas`).

### Placeholders (estructura lista, sin motor real)
- Audio IA y resumen IA: solo UI + "próximamente". La preferencia de voz ya persiste.
- Para conectar **voces IA reales** luego: crear un endpoint que tome `chapter_id` + la voz
  guardada (`preferences.reader_voice`), llame al proveedor TTS, cachee el audio (R2) y lo
  sirva; cambiar el botón "Reproducir · Próximamente" por un player. El gate `ai_audiobooks`
  y el selector de voz ya existen.

### Reader: tipografías (2026-06-22)
- Se quitó la fuente **"Mono"** de las opciones (básicas = Serif, Sans). `effectiveFont`
  cae a serif si había `dyslexic`/mono guardado. Fuentes premium siguen igual.

### Pendiente (siguiente fase, foundation lista)
- **C) Recomendaciones inteligentes** (Premium): endpoint con lógica interna (género/
  historial/favoritos/categorías) + sección. NO hecho aún.
- **J**: resumen de LIBRO en el detalle (los placeholders del lector ya existen).

### Probar
```bash
cd apps/api && php artisan test --filter="EntitlementsTest|MonetizationBehaviorTest"
cd apps/web && npx tsc --noEmit
# Token Plus para probar a mano:
cd apps/api && php artisan tinker --execute='echo \App\Models\User::find(1)->createToken("x")->plainTextToken;'
#   GET /api/user (incluye entitlements) · PATCH /api/me/plus/voice {voice} (403 si no Plus)
```

---

## 0) Cómo levantar y verificar

```bash
# API (Laravel) — puerto 8000  (monohilo: NO lo satures con requests en paralelo)
cd apps/api && php artisan serve

# Web (Next.js) — puerto 3000
cd apps/web && npm run dev

# Typecheck / tests
cd apps/web && npx tsc --noEmit
cd apps/api && php artisan test            # suite completa: 96 passing
```

Token de prueba para curl/preview autenticado:
```bash
cd apps/api && php artisan tinker --execute='echo \App\Models\User::find(1)->createToken("dev")->plainTextToken;'
# Cookie que leen SSR (cookies()) y cliente (document.cookie):  charbook_token=<token>
```

### ⚠️ Gotchas del entorno (importantes)
- **Turbopack se corrompe** tras muchas ediciones/HMR o un "approaching memory threshold,
  restarting": rutas que existen empiezan a dar **404 instantáneo** y el cliente no
  hidrata (perfil/avatar en blanco). **Recuperación:** parar el dev, `rm -rf apps/web/.next`,
  reiniciar. (Mitigación pendiente sugerida: subir heap con `NODE_OPTIONS=--max-old-space-size=4096`
  en el script `dev`.) Si navegas y da 404 pero `curl localhost:3000/<ruta>` da 200 → es esto.
- **`php artisan serve` es monohilo**: lanzar varias requests a la vez encola SSR y parece
  "colgado"; calienta una ruta a la vez con timeouts holgados.
- **Sin Imagick/GD/Ghostscript** → no se puede rasterizar PDF→imagen en el server.
- El navegador de preview (Claude_Preview) corre en pestaña oculta: rAF/IntersectionObserver
  no disparan → pdf.js/canvas y lazy-images de `next/image` no pintan ahí. Verifica render
  real con `new Image()`/curl o un navegador visible.
- `next.config.ts` necesita `images.dangerouslyAllowLocalIP: true` (Next 16 bloquea
  optimizar imágenes de IP privada como `localhost:8000` → portadas 400). Ya está puesto.

---

## 1) Lector unificado (texto / PDF / imágenes)
**Archivos:** `apps/web/components/reader/premium-reader.tsx` (envuelve TODOS los lectores),
`components/reader/pdf-reader.tsx`, `app/libro/[slug]/capitulo/[number]/page.tsx`.

- Controles que **aparecen al mover el mouse y se ocultan por inactividad (~2.6s)** en
  CUALQUIER lectura (antes solo en `mediaMode` = PDF/imágenes). Un único `useEffect`; el
  scroll solo revela en `mediaMode`.
- **`EdgeNav`**: zonas laterales anchas (64px, alto completo) para cambiar de capítulo;
  el chevron aparece al hover; se ocultan con el resto del chrome. Se omiten si
  `mediaMode && pageMode==='spread'` (ahí mandan los `NavZone` del PDF).
- Cambio de capítulo **rápido**: `router.push` + `router.prefetch(prev/next)` (antes
  `window.location.href`, recarga completa).

## 2) Fix de hidratación de auth
**Archivo:** `apps/web/hooks/use-auth.ts`. Gate `mounted` (useState+useEffect): en SSR y
en el primer render del cliente devuelve `user=null`/`isLoading=true`, evitando el
mismatch cuando react-query ya tiene el usuario en caché (rompía `/studio`, `/studio/obras`).

## 3) "Esperar para desbloquear" (auto-unlock gratis) + paywall rediseñado
**Backend:** `apps/api/app/Services/UnlockService.php`, `app/Models/ChapterWaitUnlock.php`,
migración `database/migrations/2026_06_17_120000_create_chapter_wait_unlocks_table.php`,
`PlatformSettings` (`unlocks.wait_enabled`=true, `unlocks.wait_hours`=48),
`MonetizationController@startWaitUnlock`, ruta `POST /api/chapters/{chapter}/wait-unlock`.
- Un lector **sin plan** reserva un capítulo de pago y a las **48h (configurable global o
  por obra: `book.monetization.wait_unlock_hours`)** se desbloquea **gratis** y permanente.
- `UnlockService::chapterAccess` concede acceso (`reason='wait'`) si la espera venció;
  en el paywall añade `wait_available/wait_hours/wait_until`. `startWait` es idempotente.

**Frontend:** `components/monetization/chapter-paywall.tsx` reescrito (más editorial),
con opción héroe "Espéralo y léelo gratis" + **cuenta atrás en vivo** (al llegar a 0 hace
`router.refresh()`). `monetizationApi.startWaitUnlock`. 

## 4) Paywall SECUENCIAL (puerta) + candados en la lista
**Backend:** `UnlockService::chapterAccess` = `individualAccess` + overlay de "puerta".
`firstLockedGate(user, book)` = primer capítulo de pago sin desbloquear (null para
suscriptores). Si `chapter.number > gate.number` → `reason='locked_sequential'` con
`gate_number/gate_chapter_id/gate_price`. **Memoizado por request** (`gateCache/
unlockedCache/waitCache` + `resetAccessCache()` en `unlock()` y `startWait()`) para no
disparar O(n²) queries.
- Regla: en cuanto hay un capítulo de pago sin desbloquear, TODO lo posterior queda
  bloqueado (aunque sea gratis); al pagar la puerta, los gratis siguientes se abren solos
  hasta la próxima de pago. Suscriptores se la saltan.

**Frontend:** `ChapterPaywall` cuando `reason==='locked_sequential'` opera sobre la PUERTA
(`gate_chapter_id`) y muestra "...desbloquea primero el capítulo {gate}". La lista de
capítulos en `app/libro/[slug]/page.tsx` usa `api.books.access(slug)` (nuevo en `lib/api.ts`)
para pintar candado/precio: la puerta muestra precio, los bloqueados en cadena solo candado;
suscriptores no ven candados.

## 5) Descarga controlada por el creador (EPUB de un solo archivo)
**Backend:** columna `books.allow_download` (migración
`2026_06_18_100000_add_allow_download_to_books.php`), `Book` fillable+cast,
`MyWorksController@store` (**required**|boolean) / `@update` (sometimes) / `serializeWork`,
`BookResource` (detalle público). Servicio `app/Services/Export/BookEpubExporter.php`
(EPUB3 válido **sin dependencias**, ZipArchive; mimetype Stored). Endpoint
`ReadingController@download` → `GET /api/books/{slug}/download`: 403 si `!allow_download`;
serie → EPUB SOLO con capítulos accesibles (respeta paywall + puerta); libro_completo
gratis con archivo → el original; si no hay nada → 404. Incrementa `downloads_count`.
**Decisión de formato: EPUB** (elección del usuario).

**Frontend:** toggle OBLIGATORIO "Descargas" (Permitir/No permitir) en el wizard
`app/studio/obras/nueva/page.tsx` (paso 0, gatea `step1Valid`); editable en
`app/studio/obras/[id]/editar/page.tsx` (pestaña Configuración).
`components/book/download-work-button.tsx` (client, `apiClient.get(blob)`) se muestra en
el detalle SOLO si `book.allow_download`. (Se quitó el viejo `<DownloadButton fileProxyUrl>`.)

## 6) Fix: leer un libro subido daba HTTP 500
**Archivo:** `apps/api/app/Http/Controllers/Api/BookController.php` (`fileProxy`).
`fileProxy` intentaba SIEMPRE el disco `r2_books` cuando había `file_path`, pero los
libros subidos desde el Studio viven en el disco **public** → R2 lanzaba excepción → 500
al **leer** (no es descarga; es el lector trayendo el archivo para mostrarlo). Ahora sirve
**primero desde public** si el archivo existe, y solo usa R2 si existe ahí (try/catch para
no romper en dev sin R2). La lectura inline funciona aunque `allow_download` sea false.

---

## Tests añadidos (todos en verde — 96 passing)
- `apps/api/tests/Feature/MonetizationBehaviorTest.php` — wait-unlock (3) + puerta secuencial (2).
- `apps/api/tests/Feature/WorkDownloadTest.php` — descarga (4): 403, EPUB válido, excluye
  capítulos de pago, store exige `allow_download`.
- `apps/api/tests/Feature/SecurityPermissionsTest.php` — `fileProxy` sirve libro local (1).

## Pendientes / ofrecidos (no hechos aún)
- Mitigación permanente de la corrupción de Turbopack (subir heap de Node en `dev`).
- `ReadingController@download` solo cubre disco local + EPUB de series; falta robustez
  para libros_completo en **R2** (como ya se hizo en `fileProxy`).
- Descarga de **cómics** (imágenes → EPUB) y un estado "Generando EPUB…" para obras largas.
- Mostrar "Generando…" en el botón de descarga.

## Notas de memoria (contexto del asistente)
Hay memorias detalladas en `C:/Users/al222/.claude/projects/C--Projects-CharBook-Library/memory/`
(`wait-to-unlock.md`, `work-download.md`, `stale-turbopack-404.md`, `chapter-pdf-reader.md`,
`nextjs16-local-image-ssrf.md`, `preview-hidden-tab-raf.md`, `chapter-importer.md`).

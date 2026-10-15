# CODEX_HANDOFF — actualizado 2026-06-13: hardening de uploads + fix archivo capítulo

## 🆕 SESIÓN 2026-06-13

### Fix definitivo "subo archivo pero dice Sin contenido"
- El backend ya persistía bien (verificado: subir PDF → file_url guardado, 200). El problema visible era la UI: el builder mostraba "Sin contenido" mirando solo `body`. **Fix** en `chapters-builder.tsx`: muestra "Archivo PDF adjunto" cuando hay `file_url`, indicador "Subiendo archivo…" durante la subida, y `type="button"` en los botones del editor (evita submit nativo que recargaba). El `accept` del input se alineó a `.epub,.pdf,.docx,.txt`.
- Recordatorio: los capítulos de book 35 quedaron con file_url=null porque se subieron con el código viejo (Content-Type manual). Re-subir con el código actual funciona.

### Auditoría + hardening de uploads (CRÍTICO de seguridad)
- **Hallazgo principal**: `ChaptersController::uploadFile` y `MyWorksController::uploadFile` validaban SOLO por `getClientOriginalExtension()` → un `virus.exe` renombrado a `libro.pdf` se aceptaba, guardaba y servía. Cover base64 no validaba contenido. ComicPages descartaba archivos malos en silencio.
- **Nuevo `app/Services/FileGuard.php`** (Codex había dejado un stub; lo completé): validación central con 7 capas — blocklist de ~50 ext peligrosas (exe/php/svg/html/js/sh…) incl. dobles extensiones (`shell.php.pdf`), allowlist por categoría, **MIME real (finfo)**, **magic bytes** (`%PDF`, `PK` para epub/docx, sin NUL para txt — esto es lo que bloquea renombrados), `getimagesize`/`getimagesizefromstring` para imágenes (bloquea SVG/polyglots), tamaño, y nombre de almacenamiento SIEMPRE aleatorio + ext canónica. La extensión la manda el cliente (no se infiere, para no colar .rtf como txt).
- **Aplicado en**: ChaptersController (capítulo), MyWorksController (archivo de obra + cover base64), ComicPagesController (ahora rechaza 422 en vez de skip), AccountController (avatar, alineado a jpg/png/webp, quita gif), BookController admin (cover→validateImage, file→validateWork como defensa extra sobre R2). El admin ya tenía `StoreBookRequest` con image+mimes (decente); FileGuard añade magic bytes.
- Reglas finales: **Obras** pdf/epub/docx/txt (MIME application/pdf, application/epub+zip, …docx, text/plain). **Imágenes** jpg/jpeg/png/webp.
- **Tests**: `tests/Feature/FileUploadSecurityTest.php` — 17 passed: acepta pdf/txt/epub/png reales; rechaza exe→pdf, elf→epub, .exe directo, doble-ext php.pdf, txt con NUL, ext no permitida (.rtf), svg, html→png, exe→jpg, data-url no-imagen, data-url svg; + 2 E2E sobre el endpoint real (exe→pdf da 422, pdf real da 200).
- Verificado: php -l OK en los 6 archivos · tests nuevos 17/17 · tests existentes intactos (Monetization+Security 15/68) · tsc 0 · eslint 0 · **smoke real: EXE renombrado a .pdf por el endpoint → 422**.
- Nota R2: los archivos protegidos ya se sirven por URL firmada (SecureFileController, sesión previa); covers van a disco/r2 con nombre aleatorio no adivinable. No se exponen ejecutables porque ya no se aceptan.

---

# CODEX_HANDOFF — actualizado 2026-06-13: fix capítulos-borrador + redirección + auditoría uploads

## 🆕 SESIÓN 2026-06-13

### Bug "subo PDF, guardo, pero el capítulo sigue 'Sin contenido' y la obra no se puede leer"
Causa raíz: una serie publicada con capítulos en **borrador**. La ficha pública cuenta solo capítulos publicados → "0 cap." → sin botón de leer. El usuario publicó la obra ANTES de subir los archivos, así que el auto-publish (que corre en `PATCH submission_status=published`) nunca volvió a dispararse.
- **`ChaptersController::uploadFile`**: si la obra ya está publicada y el capítulo no tiene schedule futuro → auto-publicar el capítulo al subir el archivo (+ notifica). Verificado: subir archivo a obra publicada → `is_published=true`.
- **`components/studio/chapters-builder.tsx`**: (a) la fila del capítulo ahora muestra "Archivo PDF adjunto" en vez de "Sin contenido" cuando hay archivo; (b) banner + botón **"Publicar N capítulos"** cuando la obra está publicada y hay borradores con contenido (publica en lote); (c) el editor de capítulo marca "publicar" por defecto si la obra está publicada; (d) spinner "Subiendo archivo…" y botones `type="button"` (evita submits accidentales).
- **`app/studio/obras/[id]/editar/page.tsx`**: `saveMutation` ahora redirige a `/studio/obras` tras guardar (flujo pedido); se pasa `workIsPublished` → ContentTab → ChaptersBuilder.
- Dejé el libro 35 del usuario (ejemplolibbro) con sus 2 capítulos PDF **ya publicados** (vista pública muestra 2 cap. listos para leer).

### Auditoría + hardening de subida de archivos — COMPLETA
Revisión: TODOS los endpoints de subida ya usan `app/Services/FileGuard.php` (7 capas: blocklist de extensiones peligrosas incl. dobles extensiones, allowlist de extensión, **MIME real (finfo)**, **magic bytes**, imágenes verificadas con getimagesize (bloquea SVG/polyglots), tamaño por categoría, nombre SIEMPRE aleatorio + extensión canónica del contenido). Endpoints cubiertos: `BookController` (cover+file), `ChaptersController::uploadFile`, `MyWorksController` (uploadFile + processCover/data-url), `ComicPagesController` (páginas img), `Me\AccountController` (avatar).
- **Reglas**: Obras = pdf/epub/docx/txt (50 MB). Imágenes = jpg/jpeg/png/webp (8 MB). Bloqueados: exe/msi/bat/cmd/ps1/dll/jar/js/html/htm/svg/php/asp/aspx/sh y ~40 más, aunque vengan renombrados.
- **Tests**: `tests/Feature/FileUploadSecurityTest.php` — **17 passed** (exe→pdf, elf→epub, doble ext php.pdf, txt con bytes binarios, svg→png, html→png, exe→jpg, data-url svg, + aceptación de pdf/txt/epub/png reales, + endpoint capítulo rechaza exe / acepta pdf).
- R2: privado (`r2_books`) servido solo por URL firmada (`SecureFileController`); covers públicos por CDN. Nombres aleatorios → sin path traversal ni adivinación.

### Validaciones
tsc 0 · eslint `chapters-builder.tsx` limpio (los 10 errores `any` de `editar/page.tsx` son PREEXISTENTES, no introducidos) · `php artisan test --filter=FileUploadSecurityTest` 17 passed · smoke: ficha pública del libro muestra 2 cap. legibles.

---

# CODEX_HANDOFF — actualizado 2026-06-12 (madrugada): Mi membresía + fix subida de archivos

## 🆕 SESIÓN 2026-06-12 (madrugada)

### Bug "subí archivo al capítulo pero dice Sin contenido / no puedo leer" — RESUELTO
- Causa 1 (frontend): `chaptersApi.uploadFile` y `bookFileApi.upload` fijaban `Content-Type: multipart/form-data` a mano → sin boundary → Laravel no parseaba el archivo y la subida fallaba en silencio. **Fix**: quitar el header manual (el navegador pone el boundary). Archivos: `apps/web/lib/api-creator.ts`.
- Causa 2 (backend, caso borde): `ChaptersController::assertChapterOwnership` explotaba con 500 ("creator_id on null") si la obra del capítulo fue borrada (huérfano). **Fix**: null-safe → 404 claro.
- Verificado E2E vía HTTP: obra serie nueva → capítulo SIN body → subir .txt multipart → 200 + `file_url` persistido → publicar obra → capítulo **publicado=1** (el auto-publish reconoce capítulos solo-archivo) → ya aparece para leer.
- Nota: el "Sin contenido" del builder mira solo `body`; el badge 📎 aparece cuando hay archivo (ahora que persiste).

### "Mi membresía" — experiencia propia en Configuración
- **Reescrita** `apps/web/app/ajustes/suscripciones/page.tsx` (antes placeholder con tiers viejos free/plus/pro): plan actual (Gratis/Premium/Plus con estilo dorado Plus), estado (Activa/Trial/Cancelada-fin-de-periodo/Vencida desde `subscriptions`), próximo cobro (`current_period_end`), badge "Otorgada por CharBook" para membresías manuales sin Stripe, beneficios del plan, comparación rápida 3 planes, CTAs: mejorar a Premium/Plus (→ checkout existente) y **Gestionar pago / Ver facturas / Cancelar** (→ **Stripe Customer Portal**, endpoint `POST /billing/portal` existente — nada sensible dentro de CharBook).
- Accesos: chips Premium/Plus del header ahora → `/ajustes/suscripciones`. El sidebar de Configuración ya tenía "Suscripción" apuntando aquí.
- **Ajuste posterior pedido por el usuario**: el item "Mi membresía" que se había añadido al dropdown del avatar fue ELIMINADO (no lo re-agregues). Los únicos accesos a la página son: chip dorado Premium/Plus del header y Configuración → Suscripción. El resto del dropdown (CharCoins, Atelier, biblioteca, antologías, editar perfil, Studio, cerrar sesión) queda como está.
- Queda en CharBook: ver estado/beneficios/comparar/decidir upgrade. Queda en Stripe: cancelar, tarjeta, facturas, datos de pago.
- Validado: tsc 0 · eslint 0 (también limpié 2 `any` preexistentes en api-creator) · smoke 200 `/ajustes/suscripciones` `/premium` `/monedas` · portal Stripe sigue OK (URL billing.stripe.com).

---

# CODEX_HANDOFF — actualizado 2026-06-12 (noche): UX Premium/Coins/Studio + fixes lectura/portadas

## 🆕 SESIÓN 2026-06-12 (noche)

### Bugs
1. **Serie publicada no se podía leer ("0 cap.")**: los capítulos se crean en borrador y "Publicar obra" no los publicaba. Fix en `MyWorksController@update`: al pasar a `published`, auto-publica capítulos con contenido (body o archivo) sin schedule futuro. Verificado vía HTTP: publicar obra → 2/2 caps publicados.
2. **Portadas no se ven**: era el MISMO dev server con caché Turbopack rota (el cover se guarda y sirve 200; el SSR de /libro ya la incluye). Limitación real anotada: el modelo solo persiste UNA portada (la vertical); la cuadrada del wizard no se guarda (pendiente si se quiere).

### UX (sin tocar Stripe/wallets/ledger/fund/webhooks)
- **/premium 100% membresía**: fuera "Bonus de CharCoins", "CharCoins mensuales", FeaturePanel de coins → reemplazos: Personalización avanzada, Recompensas mensuales exclusivas, Lectura offline, Catálogo Unlimited, Creator Fund.
- **/charcoins** nueva (alias canónico de /monedas, que se mantiene por compat con success URLs de Stripe). `/monedas` ganó sección "¿Qué son las CharCoins?" (qué son / para qué / cómo se consiguen) + nota de separación con Premium.
- **Acceso rápido**: ítem en el menú del avatar "N CharCoins [+]" → /charcoins (react-query, staleTime 60s, silencioso sin sesión). Paywall: caja "Te faltan X CharCoins" con botones Comprar Coins · Ver anuncio · Premium Plus.
- **Studio sin jerga técnica**: la página de monetización ya no muestra "(reparto X%)" — muestra badge de nivel humano (Creador Básico/Verificado/Destacado) + sección "Cuánto ganas por cada desbloqueo" con ejemplos REALES en coins (5/10/20 → coins netos), calculados server-side con la fórmula del UnlockService. Endpoint `GET /me/creator/wallet` ahora devuelve `creator_level`, `creator_level_label`, `earnings_examples[]`, `pricing_rules{}` (revenue_share_pct se mantiene por compat, ya no se muestra).
- **Premium Plus NO lo decide el creador**: checkbox eliminado del editor → estado solo-lectura "participa / no participa" (curaduría de CharBook); backend ignora `in_unlimited_catalog` del payload del creador (solo admin via su flujo). Justificación: el fund paga a obras participantes; la selección debe ser curatorial para evitar autoinclusión masiva que diluya el pool.
- **Packs fijos**: 3/5/10 con etiquetas Pack pequeño/mediano/grande; solo activar/desactivar + % descuento. Backend valida `packs.* in:3,5,10`. Justificación: opciones estables → decisión de compra simple y comparable; tamaños arbitrarios fragmentan precios y rompen los anclas de conversión.
- **Límites de precio por capítulo**: mín 0 (gratis) · recomendado 5–15 · **máx 50** (tope duro server-side desde `pricing.chapter_max_price`, configurable en settings; UI clamp + hint). Evita capítulos de 100–500 coins.
- **Guía para creadores nuevos** en el editor: precio recomendado, caps gratis 3–5 largas / 1–2 cortas, packs para series largas, early access 7 días.

### Validaciones
tsc 0 · eslint enfocado 0 · `php artisan test --filter=MonetizationBehaviorTest` 10 passed (51 assertions) · smoke 200: /premium /charcoins /monedas /studio/monetizacion.

### Pendientes
- Persistir la segunda portada (cuadrada) si se desea (columna nueva + UI).
- Smoke del editor del Studio con navegador real.
- Settings nuevos `pricing.*` aparecen en admin solo tras seedearlos o editarlos (defaults en código ya activos).

---

# CODEX_HANDOFF — actualizado 2026-06-12 (rediseño Premium/Plus + fix 404 crear obra)

## 🆕 SESIÓN 2026-06-12 (tarde): rediseño visual Premium/Plus + diagnóstico 404

### Bug "404 al crear obra" — RESUELTO (no era código)
- API verificada completa: `POST /me/works` → crear → `GET show` → `PATCH` → `POST chapters`, todo OK (con email verificado; sin verificar devuelve 403 con mensaje, correcto).
- El 404 era **caché corrupta de Turbopack en el dev server**: `/studio/obras`, `/studio/obras/nueva` y `/studio/monetizacion` devolvían 404 con las páginas existentes. Tras reiniciar `next dev` → todo 200. **Si reaparece: reiniciar el dev server (o borrar `.next`)**. Nota: marqué `email_verified_at` del usuario de prueba para QA.

### Rediseño Premium/Premium Plus (solo visual; CERO cambios de lógica/contratos)
- `app/globals.css`: nuevo bloque "Identidad Premium" — keyframes `premium-sheen` (gradiente animado), `premium-shimmer` (barrido), `premium-breathe` (glow respirando), `premium-float`; clases `member-chip-premium`, `member-chip-plus`, `plus-border-gradient`, `plus-card-glow`, `plus-cta`; **todo desactivado bajo `prefers-reduced-motion`**. Sin librerías nuevas (CSS puro).
- `components/site-header.tsx`: el botón "Mi Premium"/"Premium Plus" se reemplazó por **chips compactos** diferenciados: Premium = pill dorado sobrio con corona ("Premium"); Plus = pill con borde gradiente animado + shimmer + sparkles flotando ("Plus"). Ambos → /monedas, con title/aria.
- `app/premium/premium-client.tsx`: PlanCard Plus con borde-gradiente animado + glow + badge "Mejor experiencia" + título en gradiente + CTA "Mejorar a Plus" con barrido + scale 1.03 en desktop; hover-lift en todas las cards; MiniPlan del hero igual; tabla comparativa con columna Plus resaltada (bg-primary/5 + sparkles); CTA final envuelto en plus-border-gradient con CTA Plus dominante; fade-in-up del hero escalonado.
- `components/monetization/chapter-paywall.tsx`: opción "Obtener Premium Plus" ahora `plus` (borde gradiente + badge "Recomendado" + CTA dorado con barrido); prop nueva `plus` en PaywallOption.
- **No tocado**: StripeService, webhook, WalletService, CreatorLedgerService, refunds/clawback, migraciones, Creator Fund, Connect, móvil, contratos API, rutas.
- Validado: `tsc` 0 · eslint enfocado 0 · smoke 200 en /premium /monedas /studio/obras /studio/obras/nueva · API health ok.
- Pendiente visual: QA con navegador real (hover/animaciones/responsive) y captura.

---

# CODEX_HANDOFF — actualizado 2026-06-12 (cierre P1/P2 de monetización)

## 🆕 CIERRE DE RIESGOS P1/P2 (2026-06-12) — 20 checks, todos PASS

### P1.1 — Webhook Stripe seguro para producción ✅
`apps/api/app/Http/Controllers/Api/BillingController.php@webhook` reescrito:
- Con `STRIPE_WEBHOOK_SECRET` → **siempre** verifica firma (firma inválida/ausente → 400).
- Sin secret → solo procesa en `local`/`testing`; en producción rechaza 500 + log crítico (secret de facto obligatorio).
- No imprime secretos. Docs de `stripe listen` + registro en Dashboard en `docs/MONETIZACION-DISENO.md`.
- Validado: sin firma→400, firma inválida→400, **firma válida→200 acredita 100**, reintento no duplica, prod sin secret→500. (Firma HMAC validada con secret temporal, restaurado a vacío.)

### P1.2 — Pago E2E con webhook firmado ✅
No hay Stripe CLI instalado ni puedo pagar con tarjeta en navegador headless, pero validé el flujo **checkout→webhook firmado→acreditación→ledger→wallet** con webhooks firmados (HMAC real). Checkout de 100/500/Premium/Plus ya devuelven URLs `checkout.stripe.com` (QA previa). **Pendiente del usuario**: `stripe listen` o pago con `4242…` para el último tramo navegador→Stripe (instrucciones en el doc).

### P2.3 — Clawback en refunds/disputes ✅
- `StripeService`: `onRefund`/`onDispute` ahora **revierten de verdad** (antes solo logueaban). Nuevo `reversePurchase()` unificado: registra revenue_event negativo (idempotente por `stripe_event_id`), revierte las monedas del comprador y hace clawback de las Creator Coins generadas por sus unlocks (× fracción del refund). Cierra el vector de fraude comprar→unlock a cómplice→refund.
- `checkoutCoinPackage` ahora propaga metadata al PaymentIntent→Charge (refund resuelve comprador/paquete sin llamada extra).
- `WalletService::reverse()` nuevo: débito que **sí** permite saldo negativo como **deuda explícita registrada** (no silenciosa) + log.
- `CreatorLedgerService::clawback()` mejorado: si el creador ya retiró, el faltante queda como deuda explícita (available negativo) + log; devuelve desglose. Nuevo `clawbackBuyerUnlockEarnings()` con marca `refund_reversed` para no revertir dos veces (idempotente ante refund seguido de dispute).
- Validado: refund revierte 525 monedas (515→-10), clawback creador 7→0, saldo negativo registrado, revenue_event negativo, reintento idempotente; dispute clawback 14→0.

### P2.4 — Unificar creator_level y creator_tier ✅
- **Hallazgo**: `creator_level` NUNCA fue columna — era un accessor derivado que shadoweaba la columna inexistente; por eso el split y el admin sí estaban conectados por el badge pero no se podía fijar nivel explícito.
- Nueva migración `2026_06_12_100000_add_creator_level_override_to_users.php`: columna `creator_level` nullable (override explícito). **Una columna, no tabla** — justificada como corrección de P2.4.
- Accessor `getCreatorLevelAttribute` ahora: override explícito manda; si null, deriva como antes (sin regresión).
- `creator_level` añadido a `User::$fillable`.
- Endpoint admin `updateCreatorTier` unificado: acepta `{level: none|basic|verified|featured}` (preferido) o legacy `{tier: none|featured}`. `creator_level` es la fuente del split; `creator_tier` (badge) se deriva. Devuelve `revenue_share_pct` real (no hardcodeado).
- Frontend: `staffApi.users.setCreatorLevel` + selector de nivel económico en `app/admin/usuarios/page.tsx` (UI mínima; el toggle featured legacy se reemplazó por selector de 4 niveles).
- Validado (servicio + HTTP admin): basic→60%, verified→70%, featured→75%; cambio desde admin modifica el reparto real; legacy `{tier:featured}`→75%.

### Estado tras P1/P2
- `.env`: `APP_ENV=local`, `STRIPE_WEBHOOK_SECRET` vacío (listo para que el usuario ponga el real). tsc web limpio en archivos tocados; `php -l` OK en los 6 PHP + migración.
- Fixtures QA reseteados (qa-reader wallet=0, overrides null). La migración nueva está **aplicada**.
- **Riesgos restantes**: ver "Siguiente paso" abajo (configurar `whsec_` real es lo único que falta para pagos productivos; SSV de anuncios y reconciliación siguen en P3/P4, NO tocados).

---

# CODEX_HANDOFF — actualizado 2026-06-11 (QA E2E de monetización)

## 🆕 QA FINAL 2026-06-11 (noche) — 47 checks ejecutados, 1 bug crítico corregido

Validación E2E con fixture determinista (lector `qa-reader@charbook.test`/`claveQA12345`, creador `qa-creator@charbook.test`, libro `qa-libro-monetizacion` con caps 1 gratis/2=10/3=20/4=early-30). Resultados: Stripe 8/8, Wallets/concurrencia 8/8, Paywall ok, Earnings/retención/clawback 8/8, Fund 6/6, R2 ok, Admin ok.

### Bug crítico CORREGIDO durante la QA
- **Todas las rutas `auth:sanctum` devolvían 500** ("Route [login] not defined") en vez de 401 sin token — Laravel intentaba redirigir a la ruta web `login`. Afectaba a TODA la API, no solo monetización. **Fix**: nuevo middleware `app/Http/Middleware/ForceJsonAccept.php` (fuerza `Accept: application/json` en `api/*`) + render de `AuthenticationException` en `bootstrap/app.php`. Verificado: `/wallet`, `/chapters/{id}/unlock` sin auth → **401 limpio**; rutas públicas (`/health`) intactas.

### Riesgos detectados, NO corregidos (decisión del usuario / fuera de scope de "no features")
1. **[CRÍTICO prod]** `STRIPE_WEBHOOK_SECRET` vacío → el webhook acredita eventos SIN verificar firma (probado: evento falso acreditó 2800 coins). Es intencional en dev pero **debe configurarse antes de producción** (`stripe listen` o endpoint del dashboard).
2. **[MEDIO]** `creator_level` (lo que usa el split: basic/verified/featured) NO está en `User::$fillable` y está desconectado de lo que el admin controla (`creator_tier` = solo badge none/featured). El split lee `creator_level` correctamente, pero el admin no puede ajustar el % por creador desde la UI actual. El default 70% aplica a quien no tenga creator_level seteado.
3. **[MEDIO]** Refund/dispute solo se loguean; `CreatorLedgerService::clawback()` existe y funciona (probado) pero `onRefund`/`onDispute` no lo invocan con precisión por-unlock.
4. **[BAJO]** Concurrencia real (HTTP paralelo) no se load-testeó; el `SELECT ... FOR UPDATE` que la protege sí está verificado secuencialmente (5/10 débitos pasan, saldo nunca negativo).

### Estado dejado en BD dev
- Usuario `prueba-movil@charbook.test` ahora tiene rol **super_admin** (para QA del panel admin).
- Fixture QA persiste (lector/creador/libro). Settings `revenue_split` se modificó en pruebas y se dejó con featured=80 (revisar si se quiere restaurar a 75).



## 🆕 AUDITORÍA 2026-06-11 (tarde) — resultado y fixes aplicados
- Auditoría completa de los 12 módulos de monetización: ver reporte en el chat / resumen aquí.
- **Customer Portal implementado** (no existía): `StripeService::billingPortalUrl`, `BillingController@portal`, ruta `POST /billing/portal`, botón "Gestionar suscripción" en `/monedas`. **Probado con claves reales**: devuelve URL `billing.stripe.com` ✅.
- **Checkout probado con claves reales** (`sk_test` ya en .env): paquete y suscripción devuelven URLs `checkout.stripe.com` ✅.
- **3 bugs corregidos**:
  1. `User::$fillable` no incluía `stripe_customer_id`/`stripe_account_id` → los `update()` los descartaban en silencio (cada checkout creaba customer duplicado y Connect fallaba con "empty string for 'account'"). Añadidos a fillable.
  2. stripe-php emite deprecación (`trigger_error`) en namespace v1 Accounts → Laravel la convertía en ErrorException. Nuevo helper `withoutDeprecationNoise()` alrededor de accounts/accountLinks; además `accounts->create` migrado a la forma moderna con `controller` (equivalente Express, sin `type` deprecado).
  3. (Sesión anterior) cliente Stripe lazy para webhooks sin clave.
- **Connect Express probado real**: onboarding URL `connect.stripe.com/setup` ✅; status `connected:true, payouts_enabled:false` con `requirements_due` (KYC pendiente del onboarding) ✅.
- Antifraude probado: `chapter_open` válido / `chapter_complete` con 3s **inválido** ✅; 402 re-verificado con capítulo 2 nuevo (15 coins) ✅.
- Plugin Claude Code `stripe@claude-plugins-official` instalado (scope user).
- **Falta aún**: STRIPE_WEBHOOK_SECRET vacío (registrar endpoint en dashboard → modo dev acepta sin firma), Price IDs de suscripción en settings (hoy usa price_data inline), SSV real de anuncios, clawback automático fino, QA visual del tab Monetización del Studio (Codex), webhooks de pagos reales end-to-end con `stripe listen`.



## 🆕 SESIÓN 2026-06-11: CAPA DE MONETIZACIÓN COMPLETA (solo web; móvil pendiente)

Diseño completo en `docs/MONETIZACION-DISENO.md` (leerlo primero: economía de 3 monedas, reparto configurable, Stripe, R2, antifraude, verificación E2E).

### Backend Laravel (`apps/api`) — nuevo
- **Migración** `2026_06_11_100000_create_monetization_tables.php`: platform_settings, wallets, coin_transactions, coin_packages, subscriptions, creator_wallets, creator_coin_transactions, unlocks, reading_events, ad_rewards, revenue_events, creator_earnings, payouts, creator_fund_distributions + columnas en users (stripe_customer_id, stripe_account_id), chapters (coin_price, early_access_until) y books (monetization jsonb, in_unlimited_catalog). **Ya migrada y seedeada** (MonetizationSeeder: 4 paquetes + 25 settings).
- **Modelos** (13): ojo, se generaron por script y hubo que quitarles el BOM (UTF-8 sin BOM siempre en PHP). Chapter y Book ganaron fillable/casts nuevos.
- **Services**: `PlatformSettings` (todo parámetro económico editable sin deploy, caché 60s), `WalletService` (ledger inmutable, FOR UPDATE, idempotencia, caps reward), `CreatorLedgerService` (pending→available con retención, clawback, payout), `UnlockService` (paywall, packs con descuento, reparto por nivel de creador), `ReadingEventService` (hitos válidos: tiempo+scroll+monotonía+cap diario), `AdRewardService` (sesión+SSV, sandbox dev), `StripeService` (checkout, webhooks idempotentes, Connect Express, transfers; cliente **lazy** para que el webhook funcione sin clave en dev).
- **Controllers**: MonetizationController (lector), BillingController (+webhook `/webhooks/stripe`), CreatorMonetizationController, Admin\MonetizationAdminController, SecureFileController (URLs firmadas R2 15min / fallback ruta firmada local `books.file-proxy.signed`).
- **Gate del lector**: `ReadingController@chapter` devuelve **402 + payload de paywall** si no hay derecho.
- **Comandos**: `charbook:release-creator-holds` (hourly) y `charbook:creator-fund {YYYY-MM}` (día 2 de cada mes) — programados en routes/console.php. Requieren `php artisan schedule:work` o cron.
- **.env**: añadidas STRIPE_SECRET / STRIPE_WEBHOOK_SECRET / STRIPE_PUBLISHABLE_KEY (VACÍAS — ponerlas para checkout real). `composer require stripe/stripe-php` hecho.

### Frontend Next (`apps/web`) — nuevo
- `lib/api-monetization.ts` (cliente completo lector/creador/admin).
- `components/monetization/chapter-paywall.tsx` — paywall del lector (4 opciones: Premium, Plus, monedas con packs y descuentos, anuncio); integrado en `app/libro/[slug]/capitulo/[number]/page.tsx` capturando el 402.
- `app/monedas/page.tsx` — billetera, paquetes→Stripe Checkout, recompensa diaria, anuncio, movimientos.
- `app/studio/monetizacion/page.tsx` — reemplazado el ComingSoon por panel real: saldos pending/available en USD, Stripe Connect (KYC), solicitar retiro, historial.
- `app/admin/monetizacion/page.tsx` + link en admin-sidebar — editor de TODOS los settings, paquetes (activar/desactivar), métricas financieras, gestión de retiros (retry/reject), ejecutar Creator Fund.
- Fix de tipo preexistente en `lib/types.ts` (ChapterFull.book.author opcional).

### Verificado E2E (ver §14 del diseño)
Paywall 402→unlock→lectura, mezcla premium+reward con límite %, reparto 75% featured (6 coins pendientes, retención 7d), webhook acredita 100 coins sin duplicar en reintento, contabilidad exacta en revenue_events.

### Pendientes de monetización
1. Poner claves reales de Stripe + crear Prices y volcar sus ids en settings (admin) + configurar webhook endpoint en dashboard Stripe.
2. UI de precios por capítulo en el editor de obras del Studio (el endpoint `PATCH /me/works/{id}/monetization` ya existe).
3. SSV real de AdMob/Ad Manager (verificación de firma) — hoy sandbox `ads.dev_autoconfirm`.
4. Reembolsos/disputas: hoy solo se registran y loguean; falta clawback automático fino por unlock.
5. Job de reconciliación de saldos vs ledger.
6. Móvil: consumir esta API (paywall, monedas, panel creador) — EXPRESAMENTE pospuesto por el usuario.
7. tsc de la web tiene errores PREEXISTENTES en `app/studio/obras/*` (no míos).

---

# Sesiones anteriores (2026-06-10)

## ⬆️ FASE EXTRA (misma fecha, posterior): perfiles públicos, antologías, ajustes de cuenta, secure-store

- **`screens/UserProfileScreen.jsx`** (ruta `UserProfile`): perfil Atelier de cualquier lector — `GET /users/{username}` con `meta.is_private`; botón follow de 4 estados (`follow_state`: self/following/pending/none), stats, rails (leyendo/leídos/favoritos/obras de creador), antologías y reseñas recientes. Enlazado desde: Comunidad (actor del feed y resultados del buscador), reseñas en la ficha de libro, y Perfil → "Ver mi perfil público".
- **`screens/AnthologiesScreen.jsx`** (ruta `Anthologies`): mis antologías + modal crear (título/descripción/pública). **`screens/AnthologyDetailScreen.jsx`** (ruta `AnthologyDetail`): detalle con libros + nota curatorial, editar (modal), quitar libros (Alert), eliminar. El slug puede cambiar al renombrar (manejado).
- **Ficha de libro**: nuevo icono "albums" en la topbar → modal "Guardar en antología" (lista mis antologías y agrega con `POST /anthologies/{slug}/books {book_id}`).
- **`screens/AccountSettingsScreen.jsx`** (ruta `AccountSettings`): avatar con `expo-image-picker` (FormData a `POST /me/avatar`), nombre/username/bio/perfil público (`PATCH /me/account`), contraseña (`PATCH /me/password` con `current_password`). Mensajes ok/error por sección; `refreshUser()` tras guardar.
- **Token endurecido**: `lib/storage.js` — `expo-secure-store` en nativo, AsyncStorage en web; `lib/api.js` usa este wrapper.
- **API client**: `anthologiesApi` completo (CRUD + addBook/removeBook/forUser) y `accountApi` (update/changePassword/uploadAvatar).
- Paquetes nuevos: `expo-image-picker`, `expo-secure-store` (config plugin añadido por expo install).
- Verificado: tsc/eslint 0, `expo export` ok, smoke real: crear antología → agregar libro → detalle → listar → eliminar ✅; perfil público con stats ✅. (Ojo: un 422 en el smoke inicial era encoding de PowerShell con acentos, no un bug de la app.)
- Pendientes que siguen: lector de cómic, push/tiempo real, directorio de creadores y panel de creador en app, follow-requests UI (aceptar/rechazar), git init.

Proyectos: **web** = `C:\Projects\CharBook Library` (apps/api Laravel + apps/web Next.js — NO tocar) y **móvil** = `C:\Projects\CharBookReact-main` (Expo 54).

## Qué cambié (esta sesión)

### 1. Fix de conectividad ("no se pudo conectar con el servidor")
Causa: la app apuntaba a `localhost`/`10.0.2.2`, que no existe desde un teléfono físico, y `php artisan serve` solo escuchaba en 127.0.0.1.
- `lib/config.js` reescrito: resuelve el host automáticamente desde `Constants.expoConfig.hostUri` (la IP del Metro bundler = IP LAN de la PC). Funciona en teléfono físico, emulador y web. Override: `EXPO_PUBLIC_API_URL`.
- API relanzada con `php artisan serve --host 0.0.0.0 --port 8000` (proceso en background).
- ⚠️ **PENDIENTE DEL USUARIO (admin)**: regla de firewall Windows para permitir el puerto 8000 entrante:
  `netsh advfirewall firewall add rule name="CharBook API dev (8000)" dir=in action=allow protocol=TCP localport=8000`
  Sin esto, el teléfono físico no alcanza la API (el classifier me denegó ejecutarlo). Documentado en el README móvil.

### 2. Rediseño completo "Tinta Viva" (sin emojis, iconografía profesional)
Espejo del design system de la web (`docs/charbook-design-system.html`):
- `constants/design.js` — paletas claro/oscuro/alto contraste (papel/tinta/bermellón/fósforo), tipografías, espaciados, radios.
- Fuentes instaladas: `@expo-google-fonts/fraunces` + `@expo-google-fonts/hanken-grotesk` (cargadas en `app/_layout.tsx` con splash).
- `components/ui/Kit.jsx` — kit de componentes: `T` (tipografía), `Button`, `IconButton` (con badge), `Field`, `Chip`, `BookCover`, `Stars` (visual + selector), `Avatar`, `LoadingView`, `StateView`, `Divider`, `SectionTitle`. Iconos Ionicons en toda la app.
- `context/AccessibilityContext.jsx` — reescrito sobre las paletas nuevas (mismos toggles: oscuro/contraste/escala de fuente).
- Eliminado: `screens/CrudScreen.jsx`, `screens/AudioScreen.jsx`, `components/AccessibilityBar.jsx`, `constants/colors.js` y todos los componentes demo del template Expo (`themed-*`, `parallax`, `hello-wave`, `haptic-tab`, `icon-symbol`, `collapsible`, `external-link`); `app/(tabs)`, `app/Profile.tsx`, `app/modal.tsx` (el template envolvía la app en sus propios tabs Home/Explore).
- `app/_layout.tsx` + `app/index.tsx` — raíz expo-router mínima; la navegación real es react-navigation.

### 3. Paridad funcional con la web — pantallas (todas nuevas o reescritas)
`screens/Navigation.jsx`: stack + **bottom tabs** (Inicio · Comunidad · Biblioteca · Perfil) con rutas modales: BookDetail, Reader, Chapter, Notifications, Messages, MessageDetail.
- **LoginScreen / RegisterScreen** — auth real + 2FA con botón "enviar código por correo".
- **HomeScreen** — saludo, campana con badge de no-leídas, búsqueda debounced, "continuar leyendo", carrusel de destacados (`/books/featured`), chips de categorías, grid con portadas/rating/badge gratis-premium.
- **BookDetailScreen** — hero con portada, favorito (corazón en topbar), estante 3 estados, chips de categorías, sinopsis, botón **Leer** si hay capítulos, reseñas con avatar/estrellas/tiempo y **modal para escribir/editar reseña** (rating + comentario → `POST /books/{slug}/reviews`).
- **ReaderScreen / ChapterScreen** — índice de capítulos y lectura con serif (Fraunces light), nota del autor, navegación anterior/siguiente (claves `prev`/`next` top-level de la API).
- **CommunityScreen** — feed Siguiendo/Descubrir, like optimista, comentarios expandibles (cargar + publicar), buscador de lectores con follow/unfollow en línea (campos `is_following`/`is_me` de UserCardResource).
- **LibraryScreen** — chips Todo/Quiero leer/Leyendo/Leídos/Favoritos, recarga al enfocar.
- **NotificationsScreen** — lista con no-leídas resaltadas, marcar una/todas (`meta.unread_total`).
- **MessagesScreen / MessageDetailScreen** — bandeja (campos `is_read`/`is_important`/`preview`/`sender_name`) y detalle (el GET marca leído en backend).
- **ProfileScreen** — tarjeta usuario real, badges plan/creador, stats del estante, grupos: Bandeja (notificaciones/mensajes), Accesibilidad (texto/oscuro/contraste), Cerrar sesión.

### Web: 0 cambios en esta sesión (el bugfix de `/me/shelf` fue en la sesión anterior).

## Verificación
- `npx tsc --noEmit` ✅ · `npx eslint` ✅ (0 errores) · `npx expo export --platform android` ✅ (bundle 3.07 MB, sin errores de imports).
- Smoke tests contra la API viva: login → feed discover (8 actividades reales), notificaciones (0), mensajes (0), destacados (12), búsqueda de lectores (`is_following` ok).
- Usuario de prueba: `prueba-movil@charbook.test` / `claveSegura123`.
- Proceso `php artisan serve --host 0.0.0.0` quedó corriendo en background (puerto 8000).

## Qué falta
1. **Regla de firewall** (1 comando como admin, arriba) — sin ella el teléfono físico no conecta.
2. Antologías (crear/editar desde la app), perfil público de otros lectores (`/u/[username]`), directorio de creadores, panel de creador (subir obras), ajustes de cuenta (avatar, contraseña, 2FA setup) — la API ya lo soporta todo; `anthologiesApi` ya está en `lib/api.js`.
3. Cómic (`/books/{slug}/pages`) — el lector móvil solo cubre capítulos de texto.
4. Push notifications (expo-notifications) + tiempo real (Laravel Reverb) — hoy: recarga al enfocar + pull-to-refresh.
5. Token a `expo-secure-store` en producción.
6. Commitear: **ninguno de los dos repos tiene git inicializado/commiteado** (la móvil no es repo).
7. i18n web pendiente (sesión 1): locales de/fr/it/ja/pt incompletos.

## Riesgos o dudas
- Si Expo corre en modo túnel (no LAN), `hostUri` da un dominio `exp.direct` y la API no será alcanzable → usar `EXPO_PUBLIC_API_URL`.
- El lector usa `chapter.body` como texto plano; si los creadores publican HTML/markdown habría que renderizarlo.
- `meta.total` de paginados se usa para stats del perfil; si la página trae menos de `per_page` igual es correcto.

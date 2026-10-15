# CharBook Library — Diseño del sistema de monetización

Fecha: 2026-06-11 · Estado: diseño aprobado para implementación (solo plataforma web; móvil pendiente)

---

## ⚙️ Configuración del webhook de Stripe (obligatoria para producción)

El webhook (`POST /api/webhooks/stripe`) es la **única fuente de verdad** de los pagos.
Comportamiento de seguridad (en `BillingController@webhook`):

| Entorno | `STRIPE_WEBHOOK_SECRET` | Resultado |
|---|---|---|
| cualquiera | presente | **Siempre verifica la firma**; firma inválida/ausente → 400 |
| `local` / `testing` | vacío | Procesa sin firma (solo dev) con warning en log |
| `production` / otro | vacío | **Rechaza con 500 + log crítico** (el secret es de facto obligatorio) |

Las claves se leen de `.env` (`config/services.php`); **nunca se hardcodean ni se imprimen en logs**.

### Desarrollo local con Stripe CLI (`stripe listen`)
```bash
# 1. Instalar y autenticar el Stripe CLI (una vez)
stripe login

# 2. Reenviar los eventos de Stripe (test mode) a la API local
stripe listen --forward-to http://localhost:8000/api/webhooks/stripe

# 3. El comando imprime un signing secret efímero: whsec_xxxxxxxx
#    Cópialo a apps/api/.env (NO lo subas a git):
#    STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxx
#    Luego: php artisan config:clear

# 4. Disparar eventos de prueba o pagar con tarjeta 4242 4242 4242 4242
stripe trigger checkout.session.completed
```

### Registro del webhook real (Stripe Dashboard, producción)
1. Dashboard → **Developers → Webhooks → Add endpoint**.
2. Endpoint URL: `https://TU_DOMINIO/api/webhooks/stripe`.
3. Eventos a escuchar: `checkout.session.completed`, `invoice.paid`,
   `customer.subscription.updated`, `customer.subscription.deleted`,
   `charge.refunded`, `charge.dispute.created`, `account.updated`.
4. Copiar el **Signing secret** (`whsec_...`) del endpoint y ponerlo en
   `STRIPE_WEBHOOK_SECRET` del entorno de producción (variable de entorno / secret manager).
5. La verificación de firma queda activa automáticamente.

---

## 1. Análisis de la arquitectura existente

| Pieza | Estado real | Impacto |
|---|---|---|
| Backend | **Laravel 12** (`apps/api`) — no Next API routes; Next.js 16 es solo frontend | La capa económica vive en Laravel |
| BD | PostgreSQL `charbook` | Transacciones SQL + `FOR UPDATE` disponibles |
| Storage | Cloudflare R2 ya configurado: disco `r2_books` (privado) y `r2_covers` (público CDN) vía driver S3 | `Storage::temporaryUrl()` funciona para URLs firmadas |
| Cache/colas | Redis (phpredis) + tabla `jobs` | Webhooks y Creator Fund pueden ir a cola |
| Buscador | Meilisearch | Sin impacto |
| Stripe | **NO integrado** (sin SDK, sin claves en .env) | Se añade `stripe/stripe-php` + claves |
| Usuarios | `users.subscription_tier` (string, default `free`), roles spatie, `creator_status/creator_tier/creator_level` | Tiers de creador ya existen → mapear a reparto |
| Contenido | `books` (access_type free/premium, `creator_id`), `chapters` (sin precio), `comic_pages` | Añadir campos de paywall a `chapters` y config a `books` |
| Lectura | `GET /books/{slug}/chapters/{n}` público | Aquí va el gate del paywall |

### Incompatibilidades detectadas y resolución
1. **`books.access_type` (free/premium) es binario y a nivel libro** — el paywall real es por capítulo. Se mantiene `access_type` como flag de catálogo y se añade monetización por capítulo (`chapters.coin_price`). No rompe nada existente.
2. **`/books/{slug}/file-proxy` es público** — sirve el archivo completo. Para libros con paywall debe exigir unlock/premium; se mantiene público solo para `access_type=free`.
3. **`users.subscription_tier`** hoy es informativo. Pasa a derivarse de la tabla `subscriptions` (fuente de verdad) y se sincroniza al cambiar para no romper el frontend existente.
4. **No hay tabla de settings** — se crea `platform_settings` (clave→json) con caché Redis. Nada de porcentajes hardcodeados.
5. Next.js consume la API con Bearer — Stripe Checkout se integra por **redirección** (no Elements), mínima superficie PCI.

---

## 2. Economía de tres monedas

| Moneda | Quién la tiene | Cómo se obtiene | Para qué sirve | Clave interna |
|---|---|---|---|---|
| **CharCoins Premium** | Lectores | Stripe Checkout (paquetes `coin_packages`) | Desbloquear capítulos/packs, contenido premium, acceso anticipado, eventos | `premium` |
| **CharCoins Reward** | Lectores | Anuncios recompensados, login diario, rachas, completar libros, logros, eventos | Desbloqueos normales con límites; **nunca** acceso anticipado ni contenido exclusivo | `reward` |
| **Creator Coins** | Solo creadores | Reparto automático cuando un lector gasta CharCoins Premium en su contenido | Convertibles a USD vía Stripe Connect | `creator` |

Reglas anti-inflación (todas en `platform_settings`):
- `rewards.daily_cap` = 10 (monedas reward máx/día, todas las fuentes)
- `rewards.ad_daily_cap` = 5 · `rewards.weekly_cap` = 50
- `rewards.max_pct_per_unlock` = 50 (% máx de un desbloqueo pagable con reward)
- `rewards.login_daily` = 1 · `rewards.book_complete` = 2 · `rewards.streak_7` = 3

Valor de referencia: 100 CharCoins Premium = 0.99 USD (solo informativo; el precio real lo fija cada `coin_package`). Las reward **no** generan Creator Coins: cuando un unlock mezcla premium+reward, solo la porción premium genera reparto al creador (la porción reward la absorbe la plataforma como costo de adquisición).

## 3. Reparto de ingresos (revenue split)

Configurable en `platform_settings.revenue_split`:
```json
{ "default": 70, "by_creator_level": { "basic": 60, "verified": 70, "featured": 75 } }
```
- Se aplica sobre el **valor en USD de las CharCoins Premium gastadas** (coin_value_usd = precio pagado/monedas del paquete; se usa tasa media configurable `coins.premium_usd_per_coin`, inicial 0.0099).
- El resto es ingreso de plataforma (`revenue_events.platform_amount`).
- `users.creator_level` ya existe (none/basic/verified/featured) → mapeo directo.
- Tasa de conversión Creator Coin → USD: `creator.coin_to_usd` (inicial 0.01). Nunca hardcodeada.

## 4. Suscripciones

| Plan | Precio inicial (configurable) | Incluye |
|---|---|---|
| **Premium** | 4.99 USD/mes (`subs.premium_price_id` en Stripe) | Sin anuncios, sync, marcadores/notas ilimitados, temas y tipografías premium, estadísticas, **descuento configurable en desbloqueos** (`subs.premium_unlock_discount_pct` = 10) |
| **Premium Plus** | 9.99 USD/mes | Todo Premium + audiolibro IA, offline, **acceso anticipado**, personalización avanzada, badges, recomendaciones, soporte prioritario, catálogo participante de lectura ilimitada (`books.in_unlimited_catalog`) |

Premium **no** desbloquea el catálogo completo. Los precios viven en Stripe Prices; la app solo guarda los `price_id` en settings para poder cambiarlos sin deploy.

## 5. Creator Fund

- `fund.premium_plus_pct` (inicial 50): % del ingreso neto mensual de Premium Plus que va al fondo.
- Reparto mensual (`creator_fund_distributions`) **por lectura efectiva**, no por visitas: puntos = Σ por usuario·capítulo de `reading_events` válidos (ver §8) sobre libros del catálogo ilimitado/participante, ponderados: `chapter_complete`=3, `chapter_75`=2, `chapter_50`=1.
- Comando `php artisan charbook:creator-fund {YYYY-MM}` (programable mensual): calcula pool, prorratea por puntos, acredita Creator Coins con `revenue_events` tipo `creator_fund`.

## 6. Paywall y desbloqueos

- Por capítulo: `chapters.coin_price` (0 = gratis), `chapters.early_access_until` (solo Premium Plus o desbloqueo con premium coins antes de esa fecha).
- Por libro: `books.monetization` json → `{ "free_chapters": 3, "packs": [3,5,10], "pack_discount_pct": {"3":5, "5":10, "10":15} }` — configurable por creador.
- **Packs**: "próximos N capítulos bloqueados" con descuento; un unlock por capítulo se persiste en `unlocks` (permanente, nunca se vuelve a pagar; `unique(user_id, chapter_id)`).
- Flujo del modal (frontend): al llegar a capítulo bloqueado → modal no intrusivo con 4 opciones (Premium, Premium Plus, desbloquear con monedas/pack, ver anuncio). El lector nunca muestra anuncios dentro del contenido.

## 7. Flujo Stripe (marketplace automatizado)

```
Lector ──Checkout Session──▶ Stripe (cuenta plataforma)
   modes: subscription (premium/plus) · payment (coin packages)
                  │ webhook firmado (STRIPE_WEBHOOK_SECRET)
                  ▼
   checkout.session.completed / invoice.paid / customer.subscription.* / charge.refunded / charge.dispute.created
                  ▼
   revenue_events (ingreso bruto por categoría) + wallets (acreditar coins) + subscriptions (estado)
                  ▼ (al gastar coins premium en contenido)
   split configurable → creator_coin_transactions estado=pending (retención 7d configurable)
                  ▼ job/cron diario libera → estado=available
                  ▼
   Creador (KYC ok, Stripe Connect Express) solicita retiro ≥ 50 USD, máx 1/semana
                  ▼
   payouts: Stripe Transfer → cuenta Connect → banco del creador (automático, sin pagos manuales)
```
- Connect **Express**: `POST /me/creator/connect` crea cuenta + Account Link de onboarding (KYC lo gestiona Stripe; CharBook no almacena datos bancarios). Webhook `account.updated` sincroniza `payouts_enabled`.
- Reembolso/disputa: webhook ajusta — revierte coins no gastadas y/o crea `creator_coin_transactions` negativos (clawback) sobre el saldo pendiente.
- Idempotencia: todos los webhooks registran `stripe_event_id` único en `revenue_events`/ledger; reintentos de Stripe no duplican.

## 8. Detección de lectura real (antifraude de fondo)

`reading_events`: `chapter_open`, `chapter_25`, `chapter_50`, `chapter_75`, `chapter_complete` con `seconds_accumulated`, `max_scroll_pct`.
Un evento cuenta como **válido** solo si (configurable):
- `reading.min_seconds_per_chapter` = 45 (proporcional al hito)
- `reading.min_scroll_pct` correspondiente al hito (25/50/75/95)
- rate-limit: máx 1 progresión por capítulo·usuario·minuto, hitos monotónicos (no se puede saltar de open a complete en <min_seconds)
- cap diario de capítulos válidos por usuario (`reading.daily_valid_chapters` = 60)
El Creator Fund y las recompensas "completar libro" solo usan eventos válidos.

## 9. Antifraude y seguridad (server-side siempre)

1. **Nada del cliente es confiable**: saldo, unlocks y rewards se calculan server-side; el cliente solo envía intenciones.
2. **Transacciones SQL** con `SELECT ... FOR UPDATE` sobre wallets en todo débito/crédito.
3. **Idempotencia**: `coin_transactions.idempotency_key` único (cliente manda UUID en unlocks/claims; webhooks usan `stripe_event_id`).
4. **Ledger inmutable**: nunca se actualizan saldos sin fila de transacción; el saldo es derivable (`sum(amount)`) y se verifica con job de reconciliación.
5. **Ad rewards**: el cliente solicita una "ad session" (`ad_rewards` estado=pending, nonce firmado) → el **callback servidor-a-servidor (SSV)** del proveedor (AdMob/Ad Manager) confirma con firma → solo entonces se acreditan reward coins, bajo caps diarios/semanales. En dev existe modo sandbox (`ads.dev_autoconfirm`).
6. **Retención de seguridad**: ganancias de creador entran `pending` (`payouts.hold_days` = 7) y solo después pasan a `available`; los retiros validan saldo disponible, mínimo (`payouts.min_usd` = 50), frecuencia (`payouts.max_per_week` = 1) y comisión (`payouts.fee_pct` = 0).
7. **Auditoría**: `revenue_events` + 2 ledgers + `payouts` permiten trazar ingreso→reparto→conversión→retiro→pago.

## 10. Cloudflare R2

- `r2_books` (privado): EPUB/PDF/audio/imágenes premium **solo** vía `Storage::disk('r2_books')->temporaryUrl($path, now()->addMinutes(15))` tras validar derecho (unlock/suscripción/compra). Endpoint `GET /books/{slug}/secure-file` (auth + gate) devuelve la URL firmada; el archivo nunca se expone público.
- `r2_covers` (público CDN): sin cambios.
- Fallback dev (disco local): `URL::temporarySignedRoute` hacia el file-proxy con gate equivalente.

## 11. Esquema de base de datos (nuevas tablas)

- `platform_settings` (key pk, value jsonb, updated_by) — todos los parámetros económicos editables sin deploy.
- `wallets` (user_id, premium_balance, reward_balance, version) — saldos de lector.
- `coin_transactions` (wallet ledger: user_id, currency premium|reward, amount ±, balance_after, type purchase|unlock|ad_reward|daily_login|streak|book_complete|achievement|event|refund|admin_adjust, reference morphs, idempotency_key unique, meta).
- `coin_packages` (name, coins, bonus_coins, price_usd, stripe_price_id, is_active, sort).
- `subscriptions` (user_id, plan premium|premium_plus, stripe_subscription_id, stripe_customer_id→users, status, current_period_end, cancel_at_period_end).
- `creator_wallets` (user_id, pending_coins, available_coins, lifetime_coins, payouts_enabled, stripe_account_id).
- `creator_coin_transactions` (ledger: creator_id, amount ±, status pending|available|reversed|paid_out, source unlock|creator_fund|adjust|clawback|payout, available_at, revenue_event_id, meta).
- `unlocks` (user_id, book_id, chapter_id unique con user, coins_premium_spent, coins_reward_spent, source single|pack|early_access, pack_size, unlocked_at) — historial permanente.
- `reading_events` (user_id, book_id, chapter_id, milestone, seconds_accumulated, scroll_pct, is_valid, day único por milestone) — alimenta fund y logros.
- `ad_rewards` (user_id, provider, placement, nonce unique, status pending|confirmed|rejected, coins, confirmed_at, ssv_payload).
- `revenue_events` (categoría subscription_premium|subscription_plus|coin_purchase|unlock_commission|advertising|other, gross/net/platform/creator amounts USD, stripe ids únicos, user, creator, meta) — contabilidad de plataforma.
- `creator_earnings` (agregado diario por creador: día, coins_earned, usd_value, source) — para gráficas rápidas.
- `payouts` (creator_id, amount_usd, fee_usd, coins_converted, status requested|processing|paid|failed|rejected, stripe_transfer_id, processed_at).
- `creator_fund_distributions` (month, pool_usd, total_points, status, breakdown jsonb por creador).
- Alteraciones: `users` +stripe_customer_id +stripe_account_id; `chapters` +coin_price +early_access_until; `books` +monetization jsonb +in_unlimited_catalog.

## 12. Superficie API nueva (resumen)

**Lector**: `GET /wallet` · `GET /coin-packages` · `POST /billing/checkout` (package_id | plan) · `GET /me/subscription` (se amplía) · `POST /billing/portal` · `GET /books/{slug}/access` (estado de paywall por capítulo) · `POST /chapters/{id}/unlock` (single) · `POST /books/{slug}/unlock-pack {size, from_chapter}` · `GET /me/unlocks` · `POST /reading-events` · `POST /rewards/daily-login` · `POST /ads/session` + `GET /ads/ssv-callback` (proveedor) · `GET /books/{slug}/secure-file`.
**Creador**: `GET /me/creator/wallet` · `GET /me/creator/earnings` · `GET /me/creator/transactions` · `POST /me/creator/connect` · `GET /me/creator/connect/status` · `POST /me/creator/payouts` · `GET /me/creator/payouts` · `PATCH /me/works/{id}/monetization` (precios, gratis, packs, early access).
**Admin**: `GET/PUT /admin/monetization/settings` · CRUD `/admin/coin-packages` · `GET /admin/finance/overview` · `GET/PATCH /admin/payouts` · `POST /admin/creator-fund/run` · `PATCH /admin/users/{id}/creator-level` (ya existía tier).
**Webhook**: `POST /webhooks/stripe` (sin auth, firma verificada).

## 13. Plan de implementación por fases

1. **F1 (esta entrega)**: settings + migraciones + modelos + ledger/wallet service + unlocks con packs y splits + retención + reading events válidos + ad rewards (SSV-ready, sandbox dev) + Stripe Checkout/webhooks/Connect/payouts + gating del lector + R2 firmado + seeders + endpoints lector/creador/admin + frontend web: modal paywall, página de monedas, panel de ingresos del creador, panel admin de monetización.
2. **F2**: portal de facturación Stripe, AdSense/AdMob real (SSV verificación de firma del proveedor), reconciliación automática nocturna, exportes contables, programa opcional de reparto publicitario.
3. **Móvil**: pendiente a petición del usuario (la API queda lista para consumirse desde la app).

## 14. Verificación de la entrega (2026-06-11, contra BD real)

- `php artisan migrate` ✅ (14 tablas + 3 alteraciones) · seeder: 4 paquetes + 25 settings ✅ · `php -l` 16/16 ✅ · tsc: archivos nuevos sin errores (los errores restantes en `studio/obras/*` son preexistentes).
- **Flujo paywall E2E**: mapa `/books/{slug}/access` → capítulo de pago devuelve **402** con datos del paywall → `POST /chapters/{id}/unlock` gasta 9 premium + 1 reward (respetando `max_pct_per_unlock`) → capítulo legible → saldos exactos (100→91 premium, 1→0 reward).
- **Reparto verificado**: creador nivel `featured` recibió 75% → 6 Creator Coins **pendientes** con `available_at = +7 días`; `revenue_events`: gross $0.09 / platform $0.02 / creator $0.07.
- **Webhook Stripe**: `checkout.session.completed` (paquete 100 coins) acredita 91→191; **reintento del mismo evento no duplica** (idempotencia por `stripe_event_id` + `idempotency_key`).
- **Rewards**: login diario +1 con caps; anuncio (sandbox dev) acredita bajo `ad_daily_cap`.
- Datos de prueba que quedaron en la BD dev: capítulo 1 de pago (10 coins) en el libro 32 con creador user 1; usuario `prueba-movil@charbook.test` con 191 premium coins y 1 unlock.

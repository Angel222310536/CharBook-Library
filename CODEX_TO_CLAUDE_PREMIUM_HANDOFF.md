# CODEX_TO_CLAUDE_PREMIUM_HANDOFF - Premium/Monetizacion Web

Fecha: 2026-06-12
Workspace: `C:\Projects\CharBook Library`

## Resumen

Codex avanzo el pulido visual web de Premium, monedas y paywall sin tocar Stripe, webhooks, wallets, refunds/disputes, Creator Fund, Stripe Connect, migraciones financieras ni app movil.

Tambien agrego soporte para que un super admin otorgue membresia Premium/Premium Plus manualmente desde Admin > Usuarios. Esto se implemento como `users.subscription_tier`, no como rol Spatie, para no mezclar beneficios de pago con permisos administrativos.

## Archivos modificados

Frontend:

- `apps/web/app/premium/page.tsx`
- `apps/web/app/premium/premium-client.tsx`
- `apps/web/app/monedas/page.tsx`
- `apps/web/components/monetization/chapter-paywall.tsx`
- `apps/web/components/site-header.tsx`
- `apps/web/app/admin/usuarios/page.tsx`
- `apps/web/lib/api-creator.ts`

Backend:

- `apps/api/routes/api.php`
- `apps/api/app/Http/Controllers/Api/Admin/UserAdminController.php`
- `apps/api/app/Services/UnlockService.php`

Tests:

- `apps/api/tests/Feature/SecurityPermissionsTest.php`
- `apps/api/tests/Feature/MonetizationBehaviorTest.php`

## Cambios principales

### 1. Pagina `/premium`

Se reemplazo la pagina simple por una landing completa:

- Hero CharBook Premium con pieza visual propia de biblioteca premium.
- Tres planes visibles: Gratis, Premium, Premium Plus.
- Cards de planes con estado actual.
- Beneficios principales.
- Tabla comparativa.
- Secciones CharCoins bonus, Catalogo Unlimited y Apoyo a creadores.
- Premium Picks.
- FAQ.
- CTA final.

Detalles:

- Usa `monetizationApi.checkout({ plan })` existente.
- Usa `monetizationApi.subscription()` para detectar plan y precios configurados.
- Despues de volver de Stripe con `?status=success`, refetch de usuario y suscripcion a 1.5s y 4.5s para que la pagina/header se actualicen solos.
- Si el usuario ya es Premium/Plus y tiene suscripcion activa real, muestra gestion via Customer Portal.
- Si el usuario es Premium/Plus por concesion manual de super admin, no intenta abrir Stripe Portal; muestra estado Premium.

### 2. Pagina `/monedas`

Se pulio la experiencia de billetera:

- Balance de CharCoins Premium y Reward.
- Estado de membresia Premium/Premium Plus.
- Separacion explicita entre monedas y membresia.
- Paquetes de CharCoins con bonus.
- Recompensa diaria y anuncio.
- Movimientos recientes.
- Estados vacios, login requerido y carga.

Detalles:

- `activePlan` ahora combina `wallet.plan` con `user.subscription_tier`.
- Si la membresia viene de Stripe, permite "Gestionar membresia".
- Si la membresia fue otorgada manualmente, muestra "Beneficios activos" y no abre Portal.

### 3. Paywall de capitulos

`components/monetization/chapter-paywall.tsx` fue redisenado como una pausa editorial:

- Opcion principal contextual:
  - Early access: Obtener Premium Plus.
  - Capitulo normal: Desbloquear con monedas.
- Opciones secundarias:
  - Obtener Premium.
  - Obtener Premium Plus.
  - Comprar pack de capitulos.
  - Comprar CharCoins.
  - Ganar monedas con anuncio.
- Muestra costo, saldo Premium y saldo Reward.
- Mantiene los endpoints existentes:
  - `bookAccess`
  - `unlockChapter`
  - `unlockPack`
  - `checkout`
  - `adSession`
  - `wallet`

No se cambio la logica financiera.

### 4. Header

`components/site-header.tsx` ahora:

- Oculta el link promocional "Premium" si `user.subscription_tier` es `premium` o `premium_plus`.
- Muestra boton de miembro:
  - `Mi Premium`
  - `Premium Plus`
- El boton dirige a `/monedas`.

### 5. Super admin puede otorgar Premium/Plus

Backend:

- Nueva ruta:
  - `PATCH /api/admin/users/{user}/membership`
- Metodo:
  - `UserAdminController::updateMembership`
- Solo `super_admin` puede usarlo.
- Body:
  - `{ "tier": "free" | "premium" | "premium_plus" }`
- Actualiza:
  - `users.subscription_tier`
  - `users.subscription_ends_at = null`

Frontend:

- En `apps/web/app/admin/usuarios/page.tsx`, super admin ve dropdown "Membresia".
- Puede otorgar Gratis, Premium o Premium Plus.
- La lista de usuarios muestra badge de membresia.

Importante:

- No se agrego rol Spatie `premium`.
- No se tocaron permisos administrativos.
- Es deliberadamente membresia/beneficio, no autorizacion.

### 6. Efecto real de membresia otorgada

`UnlockService::activePlan()` ahora:

1. Prioriza suscripciones activas reales en tabla `subscriptions`.
2. Si no hay suscripcion activa, usa fallback de `users.subscription_tier` cuando sea `premium` o `premium_plus`.

Esto permite que un Premium Plus otorgado manualmente tenga acceso a early access/unlimited catalog segun las reglas existentes.

No se tocaron StripeService, BillingController webhook, WalletService ni CreatorLedgerService.

## Tests agregados

### `SecurityPermissionsTest`

Nuevo test:

- `test_only_super_admin_can_grant_manual_premium_membership`

Valida:

- Admin normal recibe 403 al intentar otorgar Premium Plus.
- Super admin puede otorgar Premium Plus.
- `subscription_tier` queda actualizado.

### `MonetizationBehaviorTest`

Nuevo test:

- `test_manual_premium_plus_tier_grants_subscription_access`

Valida:

- Usuario con `subscription_tier = premium_plus` obtiene acceso por suscripcion a early access usando `UnlockService::chapterAccess`.

## Validaciones ejecutadas

Frontend:

```powershell
cd "C:\Projects\CharBook Library\apps\web"
npx tsc --noEmit
```

Resultado: PASS.

```powershell
cd "C:\Projects\CharBook Library\apps\web"
npx eslint app\premium\page.tsx app\premium\premium-client.tsx app\monedas\page.tsx app\admin\usuarios\page.tsx components\site-header.tsx components\monetization\chapter-paywall.tsx app\studio\monetizacion\page.tsx app\admin\monetizacion\page.tsx
```

Resultado: PASS.

Backend:

```powershell
cd "C:\Projects\CharBook Library\apps\api"
php artisan test --filter=SecurityPermissionsTest
```

Resultado: 5 passed, 17 assertions.

```powershell
cd "C:\Projects\CharBook Library\apps\api"
php artisan test --filter=MonetizationBehaviorTest
```

Resultado: 10 passed, 51 assertions.

Smoke HTTP:

- `/premium` => 200
- `/monedas` => 200
- `/studio/monetizacion` => 200
- `/admin/monetizacion` => protegido. Sin sesion redirige a login y termina en 200, comportamiento esperado.

## Limitaciones

- No se pudo hacer screenshot visual con el Browser integrado porque fallo por sandbox de Windows: `CreateProcessAsUserW failed: 5`.
- Playwright no esta instalado localmente en `apps/web`, asi que no se descargaron dependencias nuevas.
- La validacion visual real queda pendiente para Claude o para una sesion con navegador disponible.

## No tocar / no se toco

- `StripeService`
- `BillingController::webhook`
- `WalletService`
- `CreatorLedgerService`
- Refunds/disputes/clawback
- Migraciones financieras
- Creator Fund
- Stripe Connect
- App movil

## Recomendacion para Claude

1. Revisar visualmente `/premium` en desktop/tablet/mobile cuando el navegador este disponible.
2. Confirmar en UI real que el header cambia de "Premium" a "Mi Premium" o "Premium Plus" segun `subscription_tier`.
3. Probar manualmente con super admin:
   - Otorgar Premium Plus a un usuario.
   - Entrar con ese usuario.
   - Confirmar que `/premium`, `/monedas`, header y early access reflejan el estado.
4. Si Claude sigue con arquitectura financiera, tener en cuenta que el fallback de `subscription_tier` en `UnlockService::activePlan()` fue intencional para membresias manuales.

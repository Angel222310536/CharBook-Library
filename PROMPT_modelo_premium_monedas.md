# PROMPT — Implementación del Modelo Premium + Monedas Virtuales (CharBook Library)

> Pásale este prompt completo a un modelo avanzado (Opus / GPT-5 / equivalente) para que diseñe e implemente el sistema. Está escrito para que el modelo entienda contexto, reglas de negocio, economía de monedas, UX del paywall y arquitectura técnica.

---

## CONTEXTO DEL PROYECTO

Estoy construyendo **CharBook Library**, una biblioteca/plataforma de lectura digital (cómics, novelas, historias, series por capítulos). Stack actual:

- **Frontend/Backend:** Next.js (App Router)
- **Base de datos relacional:** PostgreSQL
- **Almacenamiento de archivos:** Cloudflare R2 (libros, EPUB, portadas, audio)
- **Búsqueda:** Meilisearch
- **Pagos:** Stripe + Stripe Connect (para pagar a creadores)
- **Caché/sesiones:** Redis

Quiero que diseñes e implementes un **modelo de monetización híbrido**: muro de pago por lectura (paywall por capítulos) basado en **monedas virtuales**, más **dos niveles de suscripción Premium**, más **anuncios como forma de ganar monedas**. La economía debe ser justa para el lector y rentable para creadores y plataforma.

---

## OBJETIVO GENERAL

Cuando un usuario lee una obra (cómic, novela, historia, serie, etc.), después de leer algunas páginas/capítulos gratis debe aparecer **automáticamente** un aviso para seguir leyendo. Para continuar tiene dos caminos:

1. **Pagar suscripción Premium** → desbloqueo total / lectura ilimitada.
2. **Pagar con monedas virtuales** de la plataforma → desbloquea algunos capítulos (no todos), con una cantidad justa.

Las monedas se consiguen **comprando** (como las monedas de Wattpad) o **realizando actividades** (ver anuncios, rachas de lectura, etc.). Los creadores verificados/destacados pueden activar el muro de pago en sus obras y recibir una parte de lo que pagan los lectores, en una moneda especial canjeable por dinero real.

---

## ECONOMÍA DE DOS MONEDAS (clave del sistema)

Habrá **dos tipos de moneda distintos**, NO intercambiables directamente:

### 1. Monedas de Usuario ("Charcoins" o similar)
- Las usan los lectores para desbloquear lecturas.
- Se consiguen:
  - **Comprando** con dinero real (paquetes tipo Wattpad: 100, 500, 1000…).
  - **Viendo anuncios** (cada anuncio completo = X monedas, con límite diario antifraude).
  - **Actividades:** rachas de lectura, completar un libro, login diario, reseñas verificadas, etc.
- Se **gastan** para seguir leyendo cuando una obra tiene el muro activado.

### 2. Monedas de Creador ("Creator Coins" o similar) — exclusivas
- Solo las acumulan **creadores verificados y destacados**.
- Cuando un lector paga Monedas de Usuario para seguir leyendo una obra con muro activado, una parte se le acredita al creador **pero convertida a Monedas de Creador** (moneda distinta).
- Estas SÍ son **canjeables por dinero real** vía Stripe Connect (payout), sujeto a un mínimo de retiro.

### Regla de reparto (PARÁMETRO CONFIGURABLE — decisión del dueño)
Cuando un usuario paga `N` Monedas de Usuario para desbloquear contenido:
- La plataforma retiene un `%` (default sugerido: **60%**).
- El creador recibe el resto (default sugerido: **40%**) acreditado en Monedas de Creador.

> **IMPORTANTE:** El split debe ser un parámetro central configurable (variable de entorno o tabla `platform_settings`), NO un número hardcodeado. El dueño ajustará el porcentaje. Mostrar siempre el reparto de forma transparente en el panel del creador.

Ejemplo con default 60/40: usuario paga 30 monedas → plataforma retiene 18, creador recibe 12 Monedas de Creador. Diseña la conversión Monedas de Creador → dinero real como una tasa configurable también.

---

## FLUJO DEL PAYWALL DE LECTURA (UX)

1. El usuario abre una obra y lee gratis hasta un umbral configurable (ej. primeras N páginas o primeros N capítulos — definido por el creador o un default de la plataforma).
2. Al alcanzar el umbral aparece **automáticamente** un modal/overlay (sin sacarlo bruscamente de la lectura) que ofrece:
   - **"Hazte Premium"** → desbloqueo total / lectura ilimitada.
   - **"Desbloquear con monedas"** → muestra el costo en monedas (justo) y cuánto le quedaría al usuario.
   - **"Ver un anuncio para ganar monedas"** → si no tiene suficientes.
3. Si paga con monedas, se desbloquean **algunos** capítulos siguientes (cantidad configurable por el creador), NO toda la obra de golpe.
4. El progreso de desbloqueo se guarda por usuario+obra (qué capítulos ya pagó), para que no vuelva a pagar lo ya desbloqueado.
5. Toda validación de monedas y desbloqueos debe ser **server-side** (nunca confiar en el cliente). El contenido protegido se sirve solo tras validar permiso, con URLs firmadas y temporales desde R2.

El muro solo aparece en obras de **creadores verificados/destacados que hayan activado la opción** en su obra. Las demás obras siguen las reglas globales (free / premium).

---

## SUSCRIPCIONES PREMIUM (DOS NIVELES)

### Premium (nivel 1 — base)
- Lectura ilimitada / leer cualquier libro sin muro de monedas.
- Sin anuncios.
- **Más tonos/temas de lectura** (modos de color, fondos).
- **Más estilos de escritura/tipografías** para personalizar la lectura.
- Sincronización de progreso entre dispositivos, marcadores y notas ilimitados.

### Premium Plus (nivel 2 — un poco más caro, más beneficios)
Incluye TODO lo del nivel 1, MÁS:
- **Audiolibro con voces de IA más naturales** (TTS premium): al leer y dar "audiolibro", la voz suena natural, no robótica.
- **Descargas offline reales**: los libros descargados se ven aunque no haya internet (EPUB cifrado / acceso offline seguro).
- **Acceso anticipado a capítulos**: cuando un creador publica capítulos adelantados que aún no son públicos, los suscriptores Plus pueden leerlos antes.
- **Personalización de perfil avanzada**: imágenes de portada de perfil (subidas o predeterminadas), más temas de perfil, badges, etc.
- (Agrega beneficios extra razonables que aporten valor sin romper la economía — p. ej. estadísticas de lectura avanzadas, recomendaciones personalizadas con Meilisearch, prioridad de soporte.)

> Define precios como parámetros configurables. Sugerencia inicial: Premium ~$3-4/mes, Premium Plus ~$6-7/mes (ajustable). Implementar con Stripe (Checkout + Customer Portal + webhooks).

### Detección automática de interacción → pago a creadores
Cuando un lector interactúa con la obra de un creador (lee sus capítulos, incluidos los anticipados que paga con monedas), el sistema debe **detectar automáticamente** esa interacción y acreditar al creador las Monedas de Creador correspondientes, según las reglas de reparto. Esto debe ser robusto y a prueba de fraude (no acreditar por vistas falsas/bots).

---

## CÓMO GANAR MONEDAS (incluye anuncios)

- **Compra directa** (paquetes con Stripe).
- **Ver anuncios recompensados**: si el usuario quiere monedas y no quiere pagar, ve un anuncio completo y recibe X monedas. Implementar límite diario y verificación de visualización completa (callback del proveedor de ads) para evitar abuso.
- **Actividades/gamificación**: rachas de lectura, login diario, completar libros, primera reseña, etc.

Los anuncios NO deben aparecer dentro del lector mientras se lee de forma intrusiva; solo como opción voluntaria para ganar monedas o en el tier gratis en pantallas de catálogo/entre capítulos.

---

## REQUISITOS TÉCNICOS

Diseña e implementa, con el stack indicado:

1. **Esquema de base de datos (PostgreSQL)** — propón tablas como:
   - `users`, `roles` (lector, creador, creador_verificado, creador_destacado, admin)
   - `wallets` (saldo de Monedas de Usuario por usuario)
   - `creator_wallets` (saldo de Monedas de Creador)
   - `coin_transactions` (compras, gastos, recompensas por anuncios, actividades — con tipo de moneda)
   - `coin_packages` (paquetes comprables)
   - `subscriptions` (plan: premium / premium_plus, estado, periodo, Stripe IDs)
   - `works` / `chapters` (con flags: muro_activado, umbral_gratis, costo_monedas, capítulos_anticipados)
   - `unlocks` (qué usuario desbloqueó qué capítulo/obra y cuándo)
   - `payouts` (canjes de Monedas de Creador → dinero real vía Stripe Connect)
   - `platform_settings` (split %, tasas de conversión, precios, límites de anuncios)
   - `ad_rewards` (registro antifraude de anuncios vistos)

2. **Lógica server-side** para: validar saldo, descontar monedas, registrar desbloqueos, repartir entre plataforma/creador, acreditar Monedas de Creador, y servir contenido protegido solo a quien tiene permiso.

3. **Integración Stripe**: Checkout para suscripciones y paquetes de monedas; webhooks para activar/renovar/cancelar; Stripe Connect para payouts a creadores; manejo de reembolsos y disputas.

4. **Servir contenido protegido** desde R2 con URLs firmadas temporales; nunca exponer el archivo completo si el usuario no pagó.

5. **Seguridad y antifraude**: validación server-side de todo, verificación de anuncios completados vía callback del proveedor, límites diarios, protección contra bots en la detección de interacción de creadores, idempotencia en transacciones de monedas.

6. **Panel de creador**: ver saldo de Monedas de Creador, historial, activar/desactivar muro por obra, configurar umbral gratis y costo, gestionar capítulos anticipados, solicitar payout.

7. **Panel de admin**: ajustar `platform_settings` (split, precios, tasas, límites), verificar/destacar creadores, ver métricas de ingresos.

---

## ENTREGABLES QUE QUIERO DE TI

1. **Diseño de la economía**: explica el flujo de las dos monedas, el reparto configurable, y cómo se evita el abuso. Señala riesgos económicos (inflación de monedas, arbitraje, fraude de anuncios) y cómo mitigarlos.
2. **Esquema de base de datos** completo (migraciones SQL o Prisma/Drizzle).
3. **Endpoints/Server Actions de Next.js** para: comprar monedas, gastar monedas para desbloquear, recompensa por anuncio, suscribirse (ambos niveles), webhooks de Stripe, payout de creador.
4. **Componentes de UI** clave: modal de paywall durante la lectura, tienda de monedas, pantalla de suscripciones (comparativa de los 2 niveles), panel de creador, personalización de lectura (temas/tipografías).
5. **Lógica de seguridad** para servir contenido protegido desde R2.
6. **Lista de decisiones que el dueño debe tomar** (porcentajes, precios, umbrales) marcadas claramente como parámetros configurables.

Empieza proponiendo el diseño de la economía y el esquema de base de datos antes de escribir código, y avísame de cualquier inconsistencia o riesgo que detectes en las reglas anteriores.

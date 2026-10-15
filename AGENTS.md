# CharBook Library — instrucciones para agentes

Monorepo: **`apps/api`** (Laravel 12 / PHP 8.2, Postgres, Sanctum) + **`apps/web`**
(Next.js 16.2.6, App Router, **Turbopack**, React 19, react-query v5, Tailwind, axios).
Idioma del producto: **español**.

## 👉 Lee primero
**[`HANDOFF.md`](./HANDOFF.md)** — resumen de lo último trabajado (features, archivos,
endpoints, migraciones, tests y pendientes). Empieza por ahí.

`apps/web/AGENTS.md` añade reglas propias de Next.js 16 (hay breaking changes; consulta
`node_modules/next/dist/docs/` antes de tocar APIs de Next).

## Gotchas críticos del entorno
- **Turbopack se corrompe** con frecuencia (404 fantasma en rutas que existen, perfil sin
  cargar). Recuperación: parar `npm run dev`, `rm -rf apps/web/.next`, reiniciar. Señal:
  navegar da 404 pero `curl localhost:3000/<ruta>` da 200.
- **`php artisan serve` es monohilo**: no lances requests en paralelo (parece colgado).
- **Sin Imagick/GD/Ghostscript**: no se puede rasterizar PDF→imagen en el server.
- Acceso a capítulos y descargas se deciden SIEMPRE server-side en
  `apps/api/app/Services/UnlockService.php` (paywall + lectura secuencial). No confíes en el cliente.

## Verificación
```bash
cd apps/web && npx tsc --noEmit
cd apps/api && php artisan test      # suite completa en verde
```

Commit/push solo si el usuario lo pide.

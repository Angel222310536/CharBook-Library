# CharBook Library — Evolución de Producto v13→v17

**Documento de visión, sistema de diseño y roadmap**
Preparado en clave *Principal Product Designer · UX Architect · Founder · Senior Full-Stack*
Fecha: 29 de mayo de 2026 · Estado base: Fases v2 1→11 completadas

---

## 0. TL;DR (para el fundador con prisa)

CharBook no debe competir como "otro Goodreads con lector". El error estratégico de casi todas las plataformas de lectura es tratar el libro como un **catálogo** (Kindle/Apple Books) o la comunidad como un **muro de reseñas post-lectura** (Goodreads/StoryGraph). Ambas dejan vacío el momento más valioso y más solitario: **mientras lees**.

La tesis de CharBook es ocupar ese vacío:

> **CharBook es el lugar donde la lectura deja de ser solitaria sin dejar de ser íntima.**
> El margen del libro —subrayados, notas, presencia de otros lectores— se convierte en la capa social, el motor de descubrimiento y el material de IA. No un feed *sobre* libros: un lugar *dentro* de los libros.

Tres pilares que ninguna plataforma combina hoy:

1. **Marginalia viva** — las anotaciones son el grafo social, no un campo escondido. "Lee con los fantasmas de otros lectores."
2. **El Bibliotecario (IA con contexto de progreso)** — un compañero que sabe *exactamente* por dónde vas y nunca te hace spoiler. Imposible de clonar sin tu lector + tu grafo.
3. **El Atelier** — tu identidad lectora es un *lugar habitable* y vivo (no una estantería en cuadrícula), con estado en tiempo real ("Pulse").

La identidad visual propia se llama **"Tinta Viva"**: papel cálido, tinta ciruela profunda y un **rojo de corrector (Vermellón)** como color de marca —ningún competidor es dueño del rojo— más un **fósforo** para estados "en vivo". Tipografía editorial con carácter (Fraunces) sobre una grotesque humanista (Hanken). Navegación por **Spine** (lomos) en lugar de tab bar.

Roadmap por impacto real: **F13 Lector Vivo → F14 El Bibliotecario + CharBook Plus → F15 Comunidad → F16 El Oído (audio IA) → F17 El Atelier del Autor.**

---

## 1. Análisis profundo del estado actual

### 1.1 Lo que ya tienes (y es fuerte)

Tras revisar el monorepo (`apps/api` Laravel 12 + `apps/web` Next.js 16):

- **Datos ricos por libro**: el modelo `Book` ya guarda `views_count`, `downloads_count`, `favorites_count`, `rating_average/count`, `access_type`, `price`, `file_type` (EPUB), ISBNs y enlaces a Google Books / Open Library. Buena base para señales de comportamiento.
- **Identidad + permisos sólidos**: Google OAuth + Sanctum (Bearer) + Spatie roles. Listo para features sociales y de creador sin rehacer auth.
- **Infra de búsqueda y media**: Meilisearch (búsqueda instantánea ya enrutada en `/books/search-instant`), R2 para archivos, proxy de archivo (`file-proxy`) — ya resuelves el servido seguro de EPUB.
- **Marketplace con tracking**: `AffiliateLink` + `AffiliateClick` con endpoint `/go` de redirección. Monetización mínima ya cableada.
- **Lector EPUB embebido** y biblioteca personal + favoritos + reviews con estrellas.

### 1.2 Diagnóstico honesto (lo que hoy te haría indistinguible)

- **La identidad visual "navy + dorado premium" no es defendible.** Es bonita pero es el uniforme de lo "premium genérico" (Audible, apps de finanzas, relojería). No comunica *lectura* ni es ownable. Un competidor la replica en una tarde.
- **El valor vive en single-player.** Favoritos y reviews son post-lectura: llegan tarde y no crean retorno diario. No hay nada que pase *durante* la lectura ni que dependa de otras personas → cero efecto de red, cero razón para volver mañana.
- **La IA está ausente del producto** pese a estar en la lista de stack (sólo se usa para auto-metadata en admin). Es el mayor activo sin explotar.
- **El descubrimiento es una cuadrícula.** Featured/recent/popular son rankings; no hay *taste*, ni mood, ni serendipia, ni curación.
- **Monetización = sólo afiliados.** Techo bajo y dependiente de terceros. Falta suscripción y economía de creador.

### 1.3 Implicación estratégica

Tienes una base técnica excelente y un producto *correcto*. Lo que falta no es más catálogo: es un **bucle de retorno diario**, una **capa social nativa al acto de leer**, y una **capa de IA que sólo tú puedes ofrecer** porque depende de tu lector y tu grafo. Todo lo que sigue ataca exactamente eso.

---

## 2. Las 6 preguntas del brief

### 2.1 ¿Qué hace que los usuarios regresen *a diario*?

El retorno diario nace de tres motores que CharBook puede tener y los rivales no combinan:

- **Continuidad viva (Pulse).** Tu estado de lectura es un organismo: "Vas por el 38% de *Crimen y castigo*, dejaste una nota hace 2 días, 3 personas subrayaron el pasaje que sigue." Abrir la app = retomar un hilo, no enfrentar un feed vacío.
- **Marginalia social asíncrona.** Alguien respondió a tu nota; un lector que sigues subrayó algo en el libro que ambos leéis. Notificaciones *con sustancia literaria*, no ruido.
- **El Estante diario.** Una curación breve (humano + IA) que cambia cada día: un pasaje, un libro, una "sala de lectura" abierta. FOMO sano y ritualizable.

### 2.2 ¿Qué genera comunidad?

Comunidad de verdad (no muro de reseñas): **Marginalia compartida**, **Salas de Lectura** (co-lectura en tiempo real / buddy reads), **perfiles Atelier** que se siguen, **Antologías** (listas elevadas a objetos curatoriales colaborativos) y **clubes** con desbloqueo por capítulos y prompts de discusión generados por IA.

### 2.3 ¿Qué genera monetización?

Cinco fuentes, en orden de techo: **CharBook Plus** (suscripción: Bibliotecario IA, narración IA, atmósferas premium, offline), **narración IA / audiolibros instantáneos**, **economía de creador** (autores publican y venden; tú tomas un *take rate*), **afiliados++** (compra física + tips + regalo), y **curación patrocinada** (drops editoriales). Ver §5 para scoring.

### 2.4 ¿Qué se potencia con IA?

El Bibliotecario con contexto de progreso (spoiler-safe), recomendaciones por *taste-graph* (embeddings sobre subrayados, no género), **Reading DNA** (retrato poético y compartible de tu gusto), recaps "ponme al día", narración IA, y descubrimiento por *mood/momento*.

### 2.5 ¿Qué puede ser viral?

Artefactos hermosos y compartibles: **Passage Cards** (citas como objeto de diseño), **Reading DNA**, **CharBook Wrapped**, **marginalia compartida** ("mira lo que escribí en el margen") y **regala un pasaje**. Todos generan imagen lista para Instagram/TikTok con marca CharBook incrustada.

### 2.6 ¿Qué crea identidad única?

La **marca del corrector** (el rojo Vermellón de la marginalia), la navegación **Spine**, las **Living Covers**, el lenguaje visual **Tinta Viva** y la metáfora del **Atelier**. Detalle completo en §4.

---

## 3. La tesis diferenciadora (el "por qué CharBook")

Posicionamiento de una frase:

> **"Goodreads te dice qué leíste. Kindle te deja leer. CharBook te deja leer *con otros, dentro del libro*, acompañado por una inteligencia que sabe exactamente por dónde vas."**

El **moat** (foso defensivo) se construye en capas que se refuerzan:

1. **Capa de datos propietaria:** subrayados + notas + posición de lectura + dwell time por pasaje. Nadie más la tiene de tus usuarios.
2. **Capa de IA encima de esa data:** el Bibliotecario y las recomendaciones por taste mejoran con cada subrayado. Más uso → mejor IA → más uso.
3. **Capa de red social en la marginalia:** seguir lectores y anotar juntos crea costes de cambio. Tu biblioteca social no es portable a un rival.
4. **Capa de oferta (creadores):** cuando autores publican directo, tienes catálogo exclusivo. Demanda pegajosa + oferta exclusiva = plataforma.

Cada fase del roadmap añade una capa, en el orden en que cada una se vuelve defendible.

---

## 4. Sistema de diseño "Tinta Viva"

> Filosofía: **el texto y el papel están vivos y responden a la luz, al tiempo y a la presencia de otros.** Nada es plano. La interfaz se comporta como tinta sobre papel de buena gramaje: absorbe, sangra, se asienta. El "premium" no viene del oro, viene del *craft* editorial.

Es deliberadamente lo contrario de las referencias prohibidas: no es el sepia de Kindle, ni el crema-serif de Apple Books, ni el blanco-feed de Goodreads, ni el dark-violeta de StoryGraph, ni el naranja-comunidad de Wattpad.

### 4.1 Principios

1. **Editorial, no app.** Composición de revista literaria: jerarquía tipográfica fuerte, márgenes generosos, asimetría intencional. La cuadrícula uniforme está prohibida.
2. **El margen es sagrado.** El espacio en blanco a los lados del texto es donde vive la marginalia. El layout reserva siempre ese carril.
3. **Vivo > estático.** Portadas que respiran, presencia de lectores en tiempo real, tinta que sangra al revelar. Movimiento con física, nunca decorativo.
4. **Cálido, no clínico.** Papel hueso, nunca blanco puro (#FFF prohibido). Negro puro prohibido: la tinta es ciruela profunda.
5. **Un solo acento de marca.** El Vermellón se reserva para *lo humano dentro del texto*: marginalia, subrayados, lo "vivo". Nunca como relleno de botones.

### 4.2 Paleta (OKLCH — coherente con tu pipeline actual)

**Neutros — "Papel & Tinta"**

| Token | Light (Papel) | Dark (Velada) | Uso |
|---|---|---|---|
| `--paper` | `oklch(0.97 0.012 85)` hueso cálido | `oklch(0.16 0.018 310)` ciruela casi negra | Fondo |
| `--ink` | `oklch(0.22 0.03 312)` ciruela tinta | `oklch(0.95 0.01 85)` hueso | Texto |
| `--ink-soft` | `oklch(0.45 0.03 312)` | `oklch(0.72 0.02 312)` | Texto secundario |
| `--surface` | `oklch(0.99 0.008 85)` | `oklch(0.20 0.02 312)` | Tarjetas |
| `--hairline` | `oklch(0.22 0.03 312 / 12%)` | `oklch(0.95 0.01 85 / 14%)` | Bordes finos |

**Marca — "Corrector"**

| Token | Valor | Uso |
|---|---|---|
| `--vermellon` | `oklch(0.60 0.20 24)` rojo corrector | Marginalia, subrayados, marca, lo "humano" |
| `--vermellon-wash` | `oklch(0.60 0.20 24 / 14%)` | Resalte de subrayado sobre texto |
| `--phosphor` | `oklch(0.86 0.17 165)` menta fósforo | Estados "en vivo": Pulse, presencia, salas activas |
| `-- link-blue` | `oklch(0.58 0.13 250)` azul de archivo | Enlaces de datos/metadata (frío, secundario) |

Regla de oro: **Vermellón = personas. Fósforo = tiempo real. Azul = datos.** Tres voces de color, nunca se mezclan.

### 4.3 Tipografía

| Rol | Fuente | Por qué | Notas |
|---|---|---|---|
| Display / títulos | **Fraunces** (variable) | Serif "literaria" con eje óptico y *wonk*; tiene carácter sin ser anticuada. Gratis. | Pesos 300–600, `opsz` alto en hero, *italic* para citas |
| UI / cuerpo de interfaz | **Hanken Grotesk** | Grotesque humanista, cálida y legible en móvil. Diferente del omnipresente Inter. | 400/500/600 |
| Texto de lectura (lector) | **Newsreader** | Serif diseñada para lectura larga en pantalla; conmutador con Fraunces y un sans a gusto del lector. | Tamaño y interlineado controlados por el usuario |
| Metadata / "voz marginalia" | **Spline Sans Mono** | Mono para datos (ISBN, %, fechas) y para la "letra de máquina" de las notas del sistema. | Tracking ligero |

Escala tipográfica (ratio 1.25, base 16px móvil / 17px desktop). Los títulos de obra usan Fraunces con **optical size alta** y tracking negativo para sensación de portada de libro.

### 4.4 Movimiento y animación ("física de tinta")

- **Ink-bleed reveal:** al cargar contenido, el texto/imagen aparece como tinta que sangra desde el centro (mask + blur que se resuelve en ~280ms, easing `cubic-bezier(.2,.8,.2,1)`).
- **Page settle:** las tarjetas y portadas "se asientan" con un micro-overshoot de resorte (spring), nunca un fade plano.
- **Living Cover:** la portada tiene parallax sutil al mover el cursor/girar el móvil + un brillo que recorre el lomo. Si hay lectores activos en ese libro, late un punto fósforo.
- **Marginalia ink:** subrayar dibuja el trazo Vermellón con la velocidad de una pluma (path animation), no aparece de golpe.
- **Spine transition:** navegar entre secciones desliza el "lomo" activo como sacar un libro del estante.
- **Respeto a `prefers-reduced-motion`:** todo degrada a fades sobrios.

### 4.5 Navegación única — "The Spine"

En lugar de tab bar (móvil) o sidebar genérica (desktop), la navegación primaria es un **carril de lomos verticales**:

- **Desktop:** rail estrecho a la izquierda con "lomos" tipográficos verticales (Atelier · Estante · Leyendo · Salas · Buscar). El lomo activo sobresale ligeramente, como un libro a medio sacar.
- **Móvil:** los lomos viven en una barra inferior, pero el gesto estrella es **"tirar del lomo"**: un *swipe up* sobre el lomo central abre la lectura activa (vuelta inmediata a "donde iba"). El acto físico de retomar.
- **Command palette ⌘K** (ya lo tienes) se reposiciona como "El Índice": búsqueda + acciones + saltar a cualquier marginalia.

### 4.6 Componentes propios (los que dan identidad)

1. **Living Cover** — portada con parallax, brillo de lomo y punto-fósforo de presencia. Unidad atómica del catálogo.
2. **Margin Layer** — carril de marginalia plegable junto al texto del lector. Subrayados Vermellón, notas, respuestas, presencia.
3. **Passage Card** — generador de citas: tipografía editorial sobre papel Tinta Viva, marca CharBook discreta, exportable a imagen/Story. Motor viral.
4. **Pulse** — widget de estado vivo de lectura (progreso, racha como arco narrativo, actividad de tu círculo).
5. **Constellation** — vista de descubrimiento espacial: libros como estrellas en un mapa de gusto, no cuadrícula.
6. **Reading Room Bar** — barra ambiental cuando lees "acompañado": avatares con punto fósforo, posición relativa de cada lector en el libro.
7. **Ex Libris Stamp** — insignias coleccionables con estética de sello/grabado (gamificación con gusto, anti-Duolingo).
8. **El Estante (Daily)** — bloque editorial diario, formato portada de revista.

### 4.7 Layouts

- **Atelier (home/perfil):** editorial asimétrico. Hero de "continuar leyendo" (Living Cover grande + Pulse) + El Estante del día + actividad de marginalia de tu círculo. Nada de filas Netflix idénticas.
- **Catálogo:** alterna entre cuadrícula editorial (tamaños variables, no uniformes) y Constellation. Filtros por mood/momento además de género.
- **Detalle de obra (SSR, ya lo tienes):** portada Living Cover a sangre, sinopsis editorial, "marginalia destacada de la comunidad" como gancho social, afiliados integrados con dignidad.
- **Lector:** el texto centrado con **Margin Layer** a un lado; tipografía y atmósfera controlables; barra de sala si lees acompañado.

### 4.8 Experiencia móvil única vs. desktop premium

- **Móvil-first (gestual y de una mano):** "tirar del lomo" para retomar; subrayar con long-press que dibuja tinta; Passage Card en dos toques; atmósferas con sonido ambiente opcional. Todo alcanzable con el pulgar.
- **Desktop premium (espacio y craft):** Margin Layer permanente visible junto al texto; Constellation a pantalla completa; multi-columna editorial; ⌘K "El Índice"; hover-states con física de tinta. El desktop se siente como un escritorio de lectura, no una web responsive estirada.

---

## 5. Catálogo de features de alto impacto (con scoring)

Escala **1–5** (5 = máximo). *Dificultad* alta = más esfuerzo. Construible sobre tu stack actual salvo nota.

### 5.1 Inteligencia Artificial

| # | Feature | Valor usuario | Dificultad téc. | Crecimiento | Monetización |
|---|---|:--:|:--:|:--:|:--:|
| A1 | **El Bibliotecario** — chat con contexto de progreso, spoiler-safe (RAG sobre el libro recortado a tu %). | 5 | 4 | 4 | 5 |
| A2 | **Taste-graph recs** — embeddings sobre subrayados/comportamiento; "quienes anotaron este pasaje también amaron…". | 4 | 4 | 3 | 3 |
| A3 | **Reading DNA** — retrato poético y compartible de tu gusto lector. | 4 | 3 | 5 | 2 |
| A4 | **"Ponme al día"** — recap spoiler-safe al retomar tras días sin leer. | 4 | 3 | 2 | 3 |
| A5 | **Descubrimiento por mood/momento** — "tengo 20 min y quiero algo luminoso". | 4 | 3 | 3 | 3 |

*Infra:* `pgvector` sobre tu Postgres + API LLM. No requiere ML propio. El Bibliotecario es el feature-faro (headline) y la principal palanca de Plus.

### 5.2 Lectura

| # | Feature | Valor | Dific. | Crec. | Monet. |
|---|---|:--:|:--:|:--:|:--:|
| L1 | **Margin Layer / marginalia** (privada primero, schema social-ready). | 5 | 4 | 5 | 3 |
| L2 | **Sync de progreso multi-dispositivo.** *(tu pendiente)* | 4 | 2 | 2 | 2 |
| L3 | **Passage Cards** (citas compartibles). | 4 | 2 | 5 | 2 |
| L4 | **Atmósferas** — temas tipográficos + soundscapes por libro/género (premium). | 3 | 2 | 3 | 3 |

### 5.3 Comunidad / Social reading

| # | Feature | Valor | Dific. | Crec. | Monet. |
|---|---|:--:|:--:|:--:|:--:|
| C1 | **Marginalia social** — subrayados/notas compartidos, seguir lectores. | 5 | 4 | 5 | 3 |
| C2 | **Salas de Lectura** — co-lectura/buddy reads con presencia en tiempo real. | 4 | 4 | 5 | 3 |
| C3 | **Perfil Atelier** — identidad lectora viva (no estantería). *(tu pendiente)* | 4 | 2 | 4 | 2 |
| C4 | **Antologías** — listas como objetos curatoriales colaborativos. *(tu pendiente)* | 4 | 2 | 4 | 3 |
| C5 | **Clubes** — desbloqueo por capítulos + prompts de discusión IA. | 4 | 3 | 4 | 4 |

### 5.4 Gamificación (con gusto, anti-grind)

| # | Feature | Valor | Dific. | Crec. | Monet. |
|---|---|:--:|:--:|:--:|:--:|
| G1 | **Rachas como arco narrativo** — "temporadas" en vez de números culpabilizantes. | 3 | 2 | 3 | 2 |
| G2 | **Ex Libris** — sellos coleccionables por retos/hitos. | 3 | 2 | 4 | 3 |
| G3 | **CharBook Wrapped** — recap anual + mini-recaps de temporada. Viral. | 4 | 3 | 5 | 2 |

### 5.5 Descubrimiento / Autores / Audio / Monetización

| # | Feature | Valor | Dific. | Crec. | Monet. |
|---|---|:--:|:--:|:--:|:--:|
| D1 | **Constellation** — descubrimiento espacial por gusto. | 4 | 4 | 4 | 2 |
| D2 | **El Estante (Daily)** — curación diaria humano+IA. | 3 | 2 | 3 | 3 |
| N1 | **Narración IA / audiolibros instantáneos** — TTS premium; el dominio público se vuelve catálogo de audio al instante. | 5 | 4 | 4 | 5 |
| N2 | **Immersion read+listen** — texto y audio sincronizados (whispersync propio). | 4 | 4 | 3 | 4 |
| M1 | **CharBook Plus** — suscripción que envuelve IA + audio + atmósferas + offline. | 5 | 3 | 3 | 5 |
| M2 | **Author Studio** — publicación directa, venta, payouts; *take rate*. | 5 | 5 | 5 | 5 |
| M3 | **Afiliados++ + regalo + tips** — evolución de tu marketplace. | 3 | 2 | 4 | 4 |

**Lectura de la matriz:** los "quick wins" de alto valor y baja dificultad (L1 base privada, L2, L3, C3, C4, D2, G-set) van pronto. Los faros de diferenciación/monetización (A1, M1, N1) anclan la mitad del roadmap. El moat de oferta (M2 Author Studio) cierra, por ser el más caro y el que necesita demanda ya pegajosa.

---

## 6. Roadmap v13→v17 (ordenado por impacto real)

> Criterio de orden: **cada fase debe (a) subir retención o monetización de forma medible y (b) añadir una capa de moat**, empezando por lo que hace pegajoso el single-player antes de invertir en red y oferta.

### FASE 13 — "El Lector Vivo" 🩸
**Objetivo:** convertir el acto de leer en algo pegajoso y sembrar el grafo social/viral.
**Incluye:** L2 sync de progreso multi-dispositivo · L1 Margin Layer (subrayados/notas **privados**, schema preparado para social) · L3 Passage Cards · **Pulse** (estado vivo) · sistema de diseño **Tinta Viva** aplicado al lector y al home.
**Por qué primero:** bajo-medio coste técnico, retención inmediata, y crea el dato propietario (subrayados) del que dependen TODAS las fases siguientes. Las Passage Cards dan viralidad desde el día 1.
**KPIs:** D1/D7 retention, sesiones de lectura/usuario, nº de subrayados, shares de Passage Card.

### FASE 14 — "El Bibliotecario" + CharBook Plus 🤖💳
**Objetivo:** encender el diferenciador de IA y la primera fuente de ingreso recurrente.
**Incluye:** A1 Bibliotecario (RAG spoiler-safe con `pgvector`) · A4 "Ponme al día" · A2 taste-graph recs v1 · **M1 CharBook Plus** (paywall que envuelve la IA + offline) · A3 Reading DNA como gancho de conversión/viral.
**Por qué aquí:** sobre el dato de F13, la IA es defendible y *demuestra* valor que justifica pago. Reading DNA alimenta adquisición mientras el Bibliotecario alimenta conversión.
**KPIs:** conversión free→Plus, % sesiones con Bibliotecario, MRR, retención de pagadores.

### FASE 15 — "La Comunidad" 👥
**Objetivo:** activar efecto de red y costes de cambio.
**Incluye:** C1 marginalia **social** (abre el schema de F13) · C3 perfiles Atelier · C4 Antologías colaborativas · C2 Salas de Lectura v1 (buddy reads) · notificaciones con sustancia.
**Por qué aquí:** sólo merece la pena hacer social cuando el single-player ya es excelente (si no, ruido sobre vacío). Ahora la marginalia compartida tiene volumen de subrayados que mostrar.
**KPIs:** follows, % usuarios con ≥1 conexión, marginalia compartida/usuario, retención de cohortes conectadas vs. solitarias.

### FASE 16 — "El Oído" 🎧
**Objetivo:** abrir la categoría audio casi gratis y reforzar Plus.
**Incluye:** N1 narración IA / audiolibros instantáneos (todo tu catálogo de dominio público se vuelve audio) · N2 immersion read+listen sincronizado · L4 Atmósferas premium.
**Por qué aquí:** con TTS de calidad + tus 26+ clásicos de Gutenberg tienes catálogo de audio sin licencias; potente palanca de Plus y diferenciación frente a quien separa texto y audio en apps distintas.
**KPIs:** minutos escuchados, adopción audio entre pagadores, uplift de retención con immersion.

### FASE 17 — "El Atelier del Autor" ✍️
**Objetivo:** moat de oferta + motores de adquisición viral.
**Incluye:** M2 Author Studio (publicación directa, venta, payouts, *take rate*) · G2 Ex Libris + G1 temporadas + G3 Wrapped · D1 Constellation · D2 El Estante diario · C5 clubes.
**Por qué al final:** es lo más caro (pagos, moderación, herramientas de autor) y sólo rinde cuando la demanda ya es pegajosa y monetiza. Cierra el flywheel: autores traen lectores → lectores subrayan → IA mejora → más lectores → más autores.
**KPIs:** autores activos, GMV de creador, take-rate revenue, K-factor de Wrapped/DNA.

### Vista de una línea

```
F13 Lector Vivo  → retención + dato propietario   (capa de datos)
F14 Bibliotecario+Plus → diferenciación IA + MRR   (capa de IA)
F15 Comunidad    → efecto de red + lock-in          (capa social)
F16 El Oído      → nueva categoría + Plus++          (expansión)
F17 Atelier Autor→ moat de oferta + viralidad        (capa de oferta)
```

---

## 7. Riesgos y notas de implementación

- **Scope de F14 (IA):** empieza con un solo modelo de embeddings y un LLM vía API; cachea respuestas del Bibliotecario por (libro, rango de %) para coste y latencia. El "spoiler-safe" se logra recortando el contexto RAG al `progress` del usuario — es una regla de retrieval, no magia.
- **Marginalia social (F15) y moderación:** lanza primero "círculo" (sólo a quien sigues) antes que público global, para contener spam/toxicidad.
- **Narración IA (F16) y derechos de voz:** usa voces con licencia comercial clara; etiqueta el audio como sintético.
- **Author Studio (F17) y pagos:** Stripe Connect / payouts y KYC; es el bloque que justifica posponerlo.
- **Gamificación anti-daño:** rachas como narrativa, sin notificaciones culpabilizantes ni mecánicas de pérdida agresivas (coherente con principios de bienestar del lector).
- **No reinventes lo que funciona:** Meilisearch, Sanctum, R2 y tu pipeline OKLCH se quedan; Tinta Viva es una *recapa de tokens*, no una reescritura.

---

*Siguiente paso sugerido: elegir el alcance de la Fase 13 y empezar por el dato propietario (subrayados) + Passage Cards, que dan retención y viralidad con el menor esfuerzo. El showcase HTML adjunto muestra "Tinta Viva" en vivo para validar la dirección visual antes de tocar el código del lector.*

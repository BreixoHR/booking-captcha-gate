# Historial de versiones

## 2.0.0 · 2026-10-06: versión pública (plugin)
- El widget de reservas ya no está en el HTML: se entrega desde un endpoint REST **tras verificar reCAPTCHA en el servidor** (hostname y, en v3, acción y puntuación).
- Identificador estable por bloque (hash del contenido) y límite de intentos por IP.
- 20 tests de PHPUnit y un smoke test del plugin completo.

## 1.x · reCAPTCHA Enterprise delante del widget · 2025-12-09
- Capa con reCAPTCHA Enterprise en las fichas de los free tours y en los botones de reserva de los listados, en dos webs.

## 1.0 · reCAPTCHA v2 en formularios · 2025-04-09 → 2025-09-16
- Protección de formularios con reCAPTCHA v2 en el `functions.php` de una web de free tours (dos modificaciones, 2025-09-16).

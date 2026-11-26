# API — Incidencias financieras (V1)

Base: `/api/v1/store/financial-incidents`. Todos los timestamps son UTC ISO-8601 y todos los importes son strings decimales de dos posiciones.

Las fuentes permitidas son `cash_difference` y `route_collection_rejected`. El filtro `date_from`/`date_to` aplica sobre `occurred_at` (cierre de caja o rechazo/verificación de cobranza), usando días de la zona horaria de la tienda.

## Listado

`GET /?source_type=cash_difference&status=detected&date_from=2026-11-25&date_to=2026-11-25&assigned_to={uuid}&per_page=20`

```json
{
  "status": "success",
  "data": {
    "items": [{
      "source_type": "cash_difference",
      "source_id": "0a8d...",
      "status": "detected",
      "amount": "8000.00",
      "source_difference": "-8000.00",
      "occurred_at": "2026-11-25T15:32:10+00:00",
      "assigned_to": null,
      "reference": { "cash_session_id": "0a8d..." },
      "source_summary": { "business_date": "2026-11-25", "expected_amount": "12000.00", "real_amount": "4000.00", "cashier": { "id": "...", "name": "Ana" }, "closed_by": { "id": "...", "name": "Luis" }, "notes": "Faltante informado" },
      "tracking": null
    }],
    "total": 1,
    "per_page": 20,
    "current_page": 1,
    "last_page": 1,
    "date_filter_semantics": "occurred_at es el cierre de caja o la fecha de rechazo/verificación de la cobranza, filtrada por fecha local de la tienda."
  },
  "errors": null
}
```

Para `route_collection_rejected`, `source_difference` es `null`; `amount` es el importe declarado, no una pérdida confirmada. `reference` contiene `route_id`, `route_stop_id` y `commercial_operation_id`; `source_summary` incluye operación, medio de pago, declarador, verificador y motivo de rechazo.

## Contadores

`GET /counters?source_type=route_collection_rejected&date_from=2026-11-01`

```json
{ "status": "success", "data": { "detected": 3, "in_review": 1, "resolved": 4, "dismissed": 2 }, "errors": null }
```

## Responsables elegibles

`GET /assignees` requiere `financial_incidents.view` y `financial_incidents.manage`.
Devuelve solamente usuarios activos de la tienda autenticada que el endpoint de asignación también acepta, ordenados por nombre e identificador. No depende de `multi_user`.

```json
{ "status": "success", "data": [{ "id": "a1b2...", "name": "María" }], "errors": null }
```

## Detalle e historial

`GET /{sourceType}/{sourceId}` devuelve el mismo objeto de un ítem de listado. Cuando existe seguimiento, `tracking` agrega `id`, `assigned_at`, `resolution_type`, `resolution_note`, `resolved_at` y `resolved_by`.

`GET /{sourceType}/{sourceId}/history`

```json
{
  "status": "success",
  "data": [{
    "id": "...", "action": "assigned", "actor": { "id": "...", "name": "Luis" },
    "previous_status": "in_review", "new_status": "in_review",
    "previous_assigned_to": { "id": "...", "name": "Ana" },
    "new_assigned_to": { "id": "...", "name": "María" },
    "comment": "Reasignación", "metadata": null,
    "created_at": "2026-11-25T16:00:00+00:00"
  }],
  "errors": null
}
```

## Gestión

Todos requieren `financial_incidents.view` y `financial_incidents.manage`; la respuesta exitosa es el detalle actualizado (`200`).

| Endpoint | Body obligatorio | Acción de historial |
| --- | --- | --- |
| `POST /{type}/{id}/start-review` | Ninguno; `comment` opcional | `review_started` |
| `POST /{type}/{id}/assign` | `assigned_to` UUID; `comment` opcional | `assigned` |
| `POST /{type}/{id}/comments` | `comment` string | `commented` |
| `POST /{type}/{id}/resolve` | `resolution_type`, `resolution_note` | `resolved` |
| `POST /{type}/{id}/dismiss` | `reason` string | `dismissed` |

Ejemplos de body:

```json
{ "comment": "Se inició la revisión" }
```

```json
{ "assigned_to": "a1b2c3d4-0000-0000-0000-000000000000", "comment": "Responsable operativo" }
```

```json
{ "resolution_type": "confirmed_loss", "resolution_note": "Faltante corroborado contra comprobantes." }
```

```json
{ "reason": "La cobranza fue cargada duplicada en otra ruta." }
```

Los resultados permitidos de resolución son `justified_difference`, `regularized`, `confirmed_loss` y `other`. La observación es obligatoria para toda resolución. Transiciones permitidas: `detected → in_review`, `in_review → resolved` e `in_review → dismissed`. Estados: `detected`, `in_review`, `resolved`, `dismissed`; los dos últimos son terminales en V1.

Errores: `403` por feature o permiso, `404` si la fuente no existe/no pertenece a la tienda/no continúa siendo incidente, y `422` para tipo inválido, payload inválido, responsable de otra tienda/inactivo o transición no permitida.

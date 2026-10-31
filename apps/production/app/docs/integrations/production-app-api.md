# Internal Production Application API — v1

This document is the integration contract for the C# WPF production application. The web application remains the API server. Existing web endpoints and the `/client-orders` screen are not used by the WPF client.

## Transport

Use the internal HTTP base URL. The API intentionally does not redirect HTTP requests to HTTPS in the company network and does not require device credentials. `Accept: application/json` is appropriate for JSON endpoints; PDF endpoints return `application/pdf`.

## Pending orders

`GET /api/internal/v1/client-orders`

The response contains the same pending-order data used by the `/client-orders` view, ordered by shipping date:

```json
{
  "status": "ok",
  "data": [{
    "id": 123,
    "id_customer": 45,
    "fullname": "Jan Kowalski",
    "company": "Example Sp. z o.o.",
    "status": 1,
    "order_description": "Dostawa poranna",
    "create_date": "2026-07-23",
    "create_order_time": "09:15:00",
    "expected_shipping_date": "2026-07-24",
    "id_proforma": 10,
    "id_wdt": null,
    "is_freebie": 0,
    "order_batch_key": null,
    "batch_index": null,
    "batch_total": 0,
    "order_type": "STANDARD",
    "supplement_push_status": null,
    "integrated_customer": 0,
    "totalSumKg": 12.5
  }]
}
```

Use `is_freebie`, `order_type`, `order_batch_key`, `batch_index`, and `batch_total` to reproduce the web view's GRATIS, DOMÓWIENIE, and split-order labels. Only pending orders are returned.

## Picking-list data (without PDF)

`POST /api/internal/v1/picking-lists/preview`

Use the same request body as for a PDF. The response contains the content of the selected PDF pages as structured data. Each entry in `orders` represents exactly one selected order, in the order in which its ID was submitted. Products are **never aggregated between orders**. This lets the production application display or process the same product rows as the PDF before requesting a printout.

```json
{
  "status": "ok",
  "data": {
    "orders": [{
      "order_id": 123,
      "customer": "Jan Kowalski, Example Sp. z o.o.",
      "shipping_date": "24-07-2026",
      "is_freebie": false,
      "products": [{
        "category_name": "Warzywa",
        "product_name": "Marchew 10 kg",
        "quantity_carton": 2
      }]
    }]
  }
}
```

Rows with a quantity of zero or less are omitted, exactly as in the PDF. This endpoint is read-only and does not create an audit entry. Handle `400`, `401`, `403`, `422`, and `500` in the same way as the PDF endpoint.

## Picking-list PDF

`POST /api/internal/v1/picking-lists`

```http
Content-Type: application/json
Accept: application/pdf

{"order_ids":[123,124]}
```

On success the response is a binary PDF with `Content-Type: application/pdf` and an attachment filename in `Content-Disposition`. Its layout is exactly the existing web picking-list template; the selected orders are rendered in the submitted order. The server writes an audit record before responding. Do not call the endpoint with an empty list.

This PDF endpoint is public and does not create a device-attributed audit record.

Error responses are JSON: `400` malformed request, `401` invalid credential, `403` disabled device, `422` missing/invalid/non-pending order IDs, `500` generation or audit failure.

```csharp
using var request = new HttpRequestMessage(HttpMethod.Post, baseUrl + "/api/internal/v1/picking-lists");
request.Headers.Add("X-Device-Id", deviceId);
request.Headers.Add("X-Api-Key", apiKey);
request.Headers.Accept.ParseAdd("application/pdf");
request.Content = JsonContent.Create(new { order_ids = selectedOrderIds });
using var response = await httpClient.SendAsync(request);
response.EnsureSuccessStatusCode();
byte[] pdf = await response.Content.ReadAsByteArrayAsync();
```

Save `pdf` to a temporary `.pdf` file or pass it to the selected Windows print mechanism. Do not regenerate a document merely to obtain another copy: every successful request is recorded in the audit history.

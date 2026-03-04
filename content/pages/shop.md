---
title: Shop
excerpt: Produktkatalog und Cart API des shop-Plugins.
---

Der Shop nutzt das Plugin `shop` und stellt JSON-Endpunkte bereit:

- `/shop/products.json`
- `/shop/cart`
- `/shop/cart/add`
- `/shop/cart/update`
- `/shop/cart/remove`
- `/shop/cart/clear`
- `/shop/checkout/intent`

Beispiel:

```bash
curl -s http://localhost:8080/shop/products.json
```

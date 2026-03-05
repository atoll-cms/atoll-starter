---
title: Shop
excerpt: Product catalogue and cart API of the shop plugin.
---

The shop uses the `shop` plugin and provides JSON endpoints:

- `/shop/products.json`
- `/shop/cart`
- `/shop/cart/add`
- `/shop/cart/update`
- `/shop/cart/remove`
- `/shop/cart/clear`
- `/shop/checkout/intent`

Example:

```bash
curl -s http://localhost:8080/shop/products.json
```

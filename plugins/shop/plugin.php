<?php

declare(strict_types=1);

use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Support\Config;
use Atoll\Support\Yaml;

$root = dirname(__DIR__, 2);
$productsDir = $root . '/content/shop';
$ordersFile = $root . '/content/data/shop-orders.jsonl';
$configPath = $root . '/config.yaml';
$cartCookieName = 'atoll_shop_cart';

$loadProducts = static function () use ($productsDir): array {
    if (!is_dir($productsDir)) {
        return [
            'list' => [],
            'by_slug' => [],
            'by_id' => [],
        ];
    }

    $list = [];
    $bySlug = [];
    $byId = [];

    foreach (glob($productsDir . '/*.md') ?: [] as $file) {
        $raw = (string) file_get_contents($file);
        if (preg_match('/^---\s*\R(.*?)\R---\s*\R?(.*)$/s', $raw, $match) !== 1) {
            continue;
        }

        $frontmatter = Yaml::parse((string) $match[1]);
        if (!is_array($frontmatter)) {
            continue;
        }

        if (($frontmatter['draft'] ?? false) === true || ($frontmatter['active'] ?? true) === false) {
            continue;
        }

        $filename = pathinfo($file, PATHINFO_FILENAME);
        $slug = (string) (preg_replace('/^\d{4}-\d{2}-/', '', $filename) ?: $filename);
        $id = (string) ($frontmatter['id'] ?? $filename);
        $price = is_numeric($frontmatter['price'] ?? null) ? (float) $frontmatter['price'] : null;
        $stock = is_numeric($frontmatter['stock'] ?? null) ? max(0, (int) $frontmatter['stock']) : null;

        $product = [
            'id' => $id,
            'slug' => $slug,
            'sku' => (string) ($frontmatter['sku'] ?? $slug),
            'title' => (string) ($frontmatter['title'] ?? $filename),
            'price' => $price,
            'currency' => strtoupper((string) ($frontmatter['currency'] ?? 'EUR')),
            'excerpt' => (string) ($frontmatter['excerpt'] ?? ''),
            'image' => (string) ($frontmatter['image'] ?? ''),
            'stock' => $stock,
            'url' => '/shop/' . $slug,
        ];

        $list[] = $product;
        $bySlug[$slug] = $product;
        $byId[$id] = $product;
    }

    usort($list, static fn (array $a, array $b): int => strcmp((string) $a['title'], (string) $b['title']));

    return [
        'list' => $list,
        'by_slug' => $bySlug,
        'by_id' => $byId,
    ];
};

$readCart = static function (array $cookies) use ($cartCookieName): array {
    $raw = (string) ($cookies[$cartCookieName] ?? '');
    if ($raw === '') {
        return ['items' => []];
    }

    $decoded = json_decode(rawurldecode($raw), true);
    if (!is_array($decoded)) {
        return ['items' => []];
    }

    $items = [];
    foreach (($decoded['items'] ?? []) as $slug => $qty) {
        if (!is_string($slug) || !is_numeric($qty)) {
            continue;
        }
        $count = (int) $qty;
        if ($count > 0) {
            $items[$slug] = $count;
        }
    }

    return ['items' => $items];
};

$buildCartCookie = static function (array $cart) use ($cartCookieName): string {
    $json = json_encode($cart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        $json = '{"items":{}}';
    }

    return sprintf(
        '%s=%s; Path=/; Max-Age=%d; SameSite=Lax',
        $cartCookieName,
        rawurlencode($json),
        60 * 60 * 24 * 30
    );
};

$expireCartCookie = static function () use ($cartCookieName): string {
    return sprintf('%s=; Path=/; Max-Age=0; SameSite=Lax', $cartCookieName);
};

$extractInput = static function (Request $request): array {
    if ($request->isJson()) {
        return $request->json();
    }

    return array_merge($request->query, $request->post);
};

$requirePost = static function (Request $request): ?Response {
    if ($request->method === 'POST') {
        return null;
    }

    return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)
        ->withHeader('Allow', 'POST');
};

$resolveProduct = static function (array $products, string $key): ?array {
    $normalized = trim($key);
    if ($normalized === '') {
        return null;
    }

    return $products['by_slug'][$normalized] ?? $products['by_id'][$normalized] ?? null;
};

$buildCartSummary = static function (array $cart, array $products): array {
    $rows = [];
    $subtotal = 0.0;
    $currency = 'EUR';

    foreach (($cart['items'] ?? []) as $slug => $qty) {
        if (!is_string($slug) || !is_numeric($qty)) {
            continue;
        }

        $product = $products['by_slug'][$slug] ?? $products['by_id'][$slug] ?? null;
        if (!is_array($product)) {
            continue;
        }

        $count = max(1, (int) $qty);
        $price = is_numeric($product['price'] ?? null) ? (float) $product['price'] : 0.0;
        $lineTotal = $price * $count;
        $subtotal += $lineTotal;
        $currency = (string) ($product['currency'] ?? $currency);

        $rows[] = [
            'id' => $product['id'],
            'slug' => $product['slug'],
            'title' => $product['title'],
            'quantity' => $count,
            'unit_price' => round($price, 2),
            'line_total' => round($lineTotal, 2),
            'currency' => $product['currency'],
            'url' => $product['url'],
            'image' => $product['image'],
        ];
    }

    return [
        'items' => $rows,
        'item_count' => array_sum(array_map(static fn (array $row): int => (int) $row['quantity'], $rows)),
        'subtotal' => round($subtotal, 2),
        'currency' => $currency,
    ];
};

return [
    'name' => 'shop',
    'description' => 'Lightweight product catalog with cart and checkout intent API',
    'version' => '0.2.0',
    'hooks' => [
        'head:meta' => static function (): string {
            return '<meta name="atoll-shop" content="enabled">';
        },
    ],
    'routes' => [
        '/shop/health' => static fn (): array => [
            'ok' => true,
            'plugin' => 'shop',
            'features' => ['catalog', 'cart', 'checkout_intent'],
        ],
        '/shop/products.json' => static function () use ($loadProducts): array {
            $products = $loadProducts();
            return ['ok' => true, 'products' => $products['list']];
        },
        '/shop/cart' => static function (Request $request) use ($loadProducts, $readCart, $buildCartSummary): array {
            $products = $loadProducts();
            $cart = $readCart($request->cookies);
            return ['ok' => true, 'cart' => $buildCartSummary($cart, $products)];
        },
        '/shop/cart/add' => static function (Request $request) use (
            $requirePost,
            $extractInput,
            $loadProducts,
            $readCart,
            $resolveProduct,
            $buildCartSummary,
            $buildCartCookie
        ): Response {
            $methodCheck = $requirePost($request);
            if ($methodCheck !== null) {
                return $methodCheck;
            }

            $input = $extractInput($request);
            $productKey = (string) ($input['slug'] ?? $input['id'] ?? '');
            $quantity = max(1, (int) ($input['quantity'] ?? 1));

            $products = $loadProducts();
            $product = $resolveProduct($products, $productKey);
            if ($product === null) {
                return Response::json(['ok' => false, 'error' => 'Product not found'], 404);
            }

            $slug = (string) $product['slug'];
            $cart = $readCart($request->cookies);
            $current = (int) ($cart['items'][$slug] ?? 0);
            $next = $current + $quantity;
            if (is_numeric($product['stock'] ?? null)) {
                $next = min($next, max(0, (int) $product['stock']));
            }

            if ($next <= 0) {
                unset($cart['items'][$slug]);
            } else {
                $cart['items'][$slug] = $next;
            }

            return Response::json([
                'ok' => true,
                'cart' => $buildCartSummary($cart, $products),
            ])->withHeader('Set-Cookie', $buildCartCookie($cart));
        },
        '/shop/cart/update' => static function (Request $request) use (
            $requirePost,
            $extractInput,
            $loadProducts,
            $readCart,
            $resolveProduct,
            $buildCartSummary,
            $buildCartCookie
        ): Response {
            $methodCheck = $requirePost($request);
            if ($methodCheck !== null) {
                return $methodCheck;
            }

            $input = $extractInput($request);
            $productKey = (string) ($input['slug'] ?? $input['id'] ?? '');
            $quantity = (int) ($input['quantity'] ?? 0);

            $products = $loadProducts();
            $product = $resolveProduct($products, $productKey);
            if ($product === null) {
                return Response::json(['ok' => false, 'error' => 'Product not found'], 404);
            }

            $slug = (string) $product['slug'];
            $cart = $readCart($request->cookies);

            if ($quantity <= 0) {
                unset($cart['items'][$slug]);
            } else {
                if (is_numeric($product['stock'] ?? null)) {
                    $quantity = min($quantity, max(0, (int) $product['stock']));
                }
                $cart['items'][$slug] = $quantity;
            }

            return Response::json([
                'ok' => true,
                'cart' => $buildCartSummary($cart, $products),
            ])->withHeader('Set-Cookie', $buildCartCookie($cart));
        },
        '/shop/cart/remove' => static function (Request $request) use (
            $requirePost,
            $extractInput,
            $loadProducts,
            $readCart,
            $resolveProduct,
            $buildCartSummary,
            $buildCartCookie
        ): Response {
            $methodCheck = $requirePost($request);
            if ($methodCheck !== null) {
                return $methodCheck;
            }

            $input = $extractInput($request);
            $productKey = (string) ($input['slug'] ?? $input['id'] ?? '');
            $products = $loadProducts();
            $product = $resolveProduct($products, $productKey);
            if ($product === null) {
                return Response::json(['ok' => false, 'error' => 'Product not found'], 404);
            }

            $slug = (string) $product['slug'];
            $cart = $readCart($request->cookies);
            unset($cart['items'][$slug]);

            return Response::json([
                'ok' => true,
                'cart' => $buildCartSummary($cart, $products),
            ])->withHeader('Set-Cookie', $buildCartCookie($cart));
        },
        '/shop/cart/clear' => static function (Request $request) use (
            $requirePost,
            $loadProducts,
            $buildCartSummary,
            $expireCartCookie
        ): Response {
            $methodCheck = $requirePost($request);
            if ($methodCheck !== null) {
                return $methodCheck;
            }

            $empty = ['items' => []];
            return Response::json([
                'ok' => true,
                'cart' => $buildCartSummary($empty, $loadProducts()),
            ])->withHeader('Set-Cookie', $expireCartCookie());
        },
        '/shop/checkout/intent' => static function (Request $request) use (
            $requirePost,
            $extractInput,
            $loadProducts,
            $readCart,
            $buildCartSummary,
            $ordersFile,
            $configPath,
            $expireCartCookie
        ): Response {
            $methodCheck = $requirePost($request);
            if ($methodCheck !== null) {
                return $methodCheck;
            }

            $products = $loadProducts();
            $cart = $readCart($request->cookies);
            $summary = $buildCartSummary($cart, $products);
            if (($summary['item_count'] ?? 0) < 1) {
                return Response::json(['ok' => false, 'error' => 'Cart is empty'], 400);
            }

            $input = $extractInput($request);
            $orderId = 'ord_' . date('YmdHis') . '_' . random_int(1000, 9999);
            $order = [
                'order_id' => $orderId,
                'created_at' => date('c'),
                'items' => $summary['items'],
                'item_count' => $summary['item_count'],
                'subtotal' => $summary['subtotal'],
                'currency' => $summary['currency'],
                'customer' => [
                    'name' => (string) ($input['name'] ?? ''),
                    'email' => (string) ($input['email'] ?? ''),
                    'note' => (string) ($input['note'] ?? ''),
                ],
                'status' => 'pending',
            ];

            if (!is_dir(dirname($ordersFile))) {
                mkdir(dirname($ordersFile), 0775, true);
            }
            $json = json_encode($order, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                return Response::json(['ok' => false, 'error' => 'Could not encode order payload'], 500);
            }
            file_put_contents($ordersFile, $json . "\n", FILE_APPEND);

            $config = Config::load($configPath);
            $checkoutUrlTemplate = (string) Config::get($config, 'shop.checkout_url', '');
            $checkoutMode = (string) Config::get($config, 'shop.mode', 'manual');
            $clearCart = (bool) Config::get($config, 'shop.clear_cart_on_checkout', true);
            $checkoutUrl = '';
            if ($checkoutUrlTemplate !== '') {
                $checkoutUrl = str_replace('{order_id}', $orderId, $checkoutUrlTemplate);
            }

            $response = Response::json([
                'ok' => true,
                'order_id' => $orderId,
                'checkout_mode' => $checkoutMode,
                'checkout_url' => $checkoutUrl,
                'cart' => $summary,
            ], 201);

            return $clearCart
                ? $response->withHeader('Set-Cookie', $expireCartCookie())
                : $response;
        },
    ],
    'islands' => [],
];

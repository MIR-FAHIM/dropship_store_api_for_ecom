<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

function test(string $name, callable $fn) {
    echo "Testing: {$name}... ";
    try {
        $fn();
        echo "\033[32mPASSED\033[0m\n";
    } catch (\Throwable $e) {
        echo "\033[31mFAILED\033[0m\n";
        echo "  " . $e->getMessage() . "\n";
        exit(1);
    }
}

function sendRequest($kernel, string $method, string $uri, array $data = [], array $headers = []) {
    $server = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'HTTP_ACCEPT' => 'application/json',
    ];

    foreach ($headers as $key => $val) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $val;
    }

    $content = !empty($data) ? json_encode($data) : null;
    if ($content) {
        $server['CONTENT_TYPE'] = 'application/json';
    }

    $request = \Illuminate\Http\Request::create(
        $uri,
        $method,
        [],
        [],
        [],
        $server,
        $content
    );

    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [
        'status' => $response->getStatusCode(),
        'json' => json_decode($response->getContent(), true),
    ];
}

echo "===============================================\n";
echo " MULTI-TENANT ARCHITECTURE TEST SUITE\n";
echo "===============================================\n\n";

// 1. Health check
test("Ping API endpoint", function () use ($kernel) {
    $res = sendRequest($kernel, 'GET', '/api/ping');
    assert($res['status'] === 200, "Expected 200, got {$res['status']}");
    assert($res['json']['status'] === 'ok', "Expected status ok");
});

// 2. Provision Store 1: JashoreBro
test("Provision Store 1 (jashorebro.resellerbrain.com)", function () use ($kernel) {
    $payload = [
        'domain' => 'jashorebro.resellerbrain.com',
        'store_name' => 'Jashore Bro Gadgets',
        'store_phone' => '01711223344',
        'whatsapp_number' => '01711223344',
        'rb_api_key' => 'rb_live_pk_jashore_test_key_123',
        'rb_api_secret' => 'rb_live_sk_jashore_test_secret_456',
        'delivery_inside_dhaka' => 70,
        'delivery_outside_dhaka' => 130,
        'default_markup_amount' => 300,
        'sync_now' => false,
    ];

    $res = sendRequest($kernel, 'POST', '/api/provision/store', $payload);
    assert($res['status'] === 201, "Expected 201, got {$res['status']}: " . json_encode($res['json']));
    assert($res['json']['data']['store']['domain'] === 'jashorebro.resellerbrain.com', "Domain mismatch");
    assert($res['json']['data']['store']['store_name'] === 'Jashore Bro Gadgets', "Store name mismatch");
});

// 3. Provision Store 2: DhakaGadget
test("Provision Store 2 (dhakagadget.resellerbrain.com)", function () use ($kernel) {
    $payload = [
        'domain' => 'dhakagadget.resellerbrain.com',
        'store_name' => 'Dhaka Gadget Hub',
        'store_phone' => '01899887766',
        'whatsapp_number' => '01899887766',
        'rb_api_key' => 'rb_live_pk_dhaka_test_key_789',
        'rb_api_secret' => 'rb_live_sk_dhaka_test_secret_012',
        'delivery_inside_dhaka' => 60,
        'delivery_outside_dhaka' => 120,
        'default_markup_amount' => 400,
        'sync_now' => false,
    ];

    $res = sendRequest($kernel, 'POST', '/api/provision/store', $payload);
    assert($res['status'] === 201, "Expected 201, got {$res['status']}: " . json_encode($res['json']));
    assert($res['json']['data']['store']['domain'] === 'dhakagadget.resellerbrain.com', "Domain mismatch");
    assert($res['json']['data']['store']['store_name'] === 'Dhaka Gadget Hub', "Store name mismatch");
});

// 4. Host header tenant resolution: Store 1
test("Host header resolution: jashorebro.resellerbrain.com", function () use ($kernel) {
    $res = sendRequest($kernel, 'GET', '/api/store/info', [], [
        'host' => 'jashorebro.resellerbrain.com',
    ]);
    assert($res['status'] === 200, "Expected 200, got {$res['status']}: " . json_encode($res['json']));
    assert($res['json']['data']['store_name'] === 'Jashore Bro Gadgets', "Expected Jashore Bro Gadgets, got {$res['json']['data']['store_name']}");
    assert($res['json']['data']['domain'] === 'jashorebro.resellerbrain.com', "Domain mismatch");
});

// 5. Host header tenant resolution: Store 2
test("Host header resolution: dhakagadget.resellerbrain.com", function () use ($kernel) {
    $res = sendRequest($kernel, 'GET', '/api/store/info', [], [
        'host' => 'dhakagadget.resellerbrain.com',
    ]);
    assert($res['status'] === 200, "Expected 200, got {$res['status']}: " . json_encode($res['json']));
    assert($res['json']['data']['store_name'] === 'Dhaka Gadget Hub', "Expected Dhaka Gadget Hub, got {$res['json']['data']['store_name']}");
    assert($res['json']['data']['domain'] === 'dhakagadget.resellerbrain.com', "Domain mismatch");
});

// 6. Unknown domain returns 404
test("Unknown domain returns 404", function () use ($kernel) {
    $res = sendRequest($kernel, 'GET', '/api/store/info', [], [
        'host' => 'nonexistent-store.com',
    ]);
    assert($res['status'] === 404, "Expected 404, got {$res['status']}");
});

// 7. Store isolation: Product creation & catalog isolation
test("Tenant catalog isolation between stores", function () use ($kernel) {
    // 1. Log in to Store 1 Admin
    $login1 = sendRequest($kernel, 'POST', '/api/admin/login', ['password' => 'admin123'], ['host' => 'jashorebro.resellerbrain.com']);
    assert($login1['status'] === 200, "Store 1 login failed: " . json_encode($login1['json']));
    $token1 = $login1['json']['data']['token'];

    // 2. Log in to Store 2 Admin
    $login2 = sendRequest($kernel, 'POST', '/api/admin/login', ['password' => 'admin123'], ['host' => 'dhakagadget.resellerbrain.com']);
    assert($login2['status'] === 200, "Store 2 login failed: " . json_encode($login2['json']));
    $token2 = $login2['json']['data']['token'];

    // Add product to Store 1 (Jashore)
    $res1 = sendRequest($kernel, 'POST', '/api/admin/products', [
        'name' => 'Jashore Leather Bag',
        'retail_price' => 1250,
        'stock' => 10,
    ], [
        'host' => 'jashorebro.resellerbrain.com',
        'authorization' => 'Bearer ' . $token1,
    ]);
    assert($res1['status'] === 201, "Store 1 product creation failed: " . json_encode($res1['json']));

    // Add product to Store 2 (Dhaka)
    $res2 = sendRequest($kernel, 'POST', '/api/admin/products', [
        'name' => 'Dhaka Smart Earbuds',
        'retail_price' => 850,
        'stock' => 20,
    ], [
        'host' => 'dhakagadget.resellerbrain.com',
        'authorization' => 'Bearer ' . $token2,
    ]);
    assert($res2['status'] === 201, "Store 2 product creation failed: " . json_encode($res2['json']));

    // Verify Store 1 ONLY sees Jashore Leather Bag
    $list1 = sendRequest($kernel, 'GET', '/api/store/products', [], [
        'host' => 'jashorebro.resellerbrain.com',
    ]);
    $items1 = $list1['json']['data']['items'];
    $names1 = array_column($items1, 'name');
    assert(in_array('Jashore Leather Bag', $names1), "Store 1 should have Jashore Leather Bag");
    assert(!in_array('Dhaka Smart Earbuds', $names1), "Store 1 MUST NOT see Dhaka Smart Earbuds!");

    // Verify Store 2 ONLY sees Dhaka Smart Earbuds
    $list2 = sendRequest($kernel, 'GET', '/api/store/products', [], [
        'host' => 'dhakagadget.resellerbrain.com',
    ]);
    $items2 = $list2['json']['data']['items'];
    $names2 = array_column($items2, 'name');
    assert(in_array('Dhaka Smart Earbuds', $names2), "Store 2 should have Dhaka Smart Earbuds");
    assert(!in_array('Jashore Leather Bag', $names2), "Store 2 MUST NOT see Jashore Leather Bag!");
});

// 8. Checkout and order isolation
test("Checkout isolation and store scoping", function () use ($kernel) {
    // Log in to Store 1 & Store 2 Admin
    $login1 = sendRequest($kernel, 'POST', '/api/admin/login', ['password' => 'admin123'], ['host' => 'jashorebro.resellerbrain.com']);
    $token1 = $login1['json']['data']['token'];
    $login2 = sendRequest($kernel, 'POST', '/api/admin/login', ['password' => 'admin123'], ['host' => 'dhakagadget.resellerbrain.com']);
    $token2 = $login2['json']['data']['token'];

    // Fetch Dhaka Smart Earbuds product ID
    $list = sendRequest($kernel, 'GET', '/api/store/products', [], [
        'host' => 'dhakagadget.resellerbrain.com',
    ]);
    $prod = $list['json']['data']['items'][0];

    $orderPayload = [
        'customer_name' => 'Farhan Ahmed',
        'customer_phone' => '01712345678',
        'shipping_address' => 'House 14, Road 5, Dhanmondi, Dhaka',
        'district' => 'Dhaka',
        'shipping_fee' => 60,
        'payment_method' => 'cod',
        'items' => [
            ['product_id' => $prod['id'], 'quantity' => 1]
        ]
    ];

    $orderRes = sendRequest($kernel, 'POST', '/api/store/checkout', $orderPayload, [
        'host' => 'dhakagadget.resellerbrain.com',
    ]);
    assert($orderRes['status'] === 201, "Checkout failed: " . json_encode($orderRes['json']));
    $orderNumber = $orderRes['json']['data']['order_number'];

    // Verify order is visible in Store 2 Admin
    $orders2 = sendRequest($kernel, 'GET', '/api/admin/orders', [], [
        'host' => 'dhakagadget.resellerbrain.com',
        'authorization' => 'Bearer ' . $token2,
    ]);
    $orderNumbers2 = array_column($orders2['json']['data']['data'], 'order_number');
    assert(in_array($orderNumber, $orderNumbers2), "Order should belong to Dhaka store");

    // Verify order is NOT visible in Store 1 Admin
    $orders1 = sendRequest($kernel, 'GET', '/api/admin/orders', [], [
        'host' => 'jashorebro.resellerbrain.com',
        'authorization' => 'Bearer ' . $token1,
    ]);
    $orderNumbers1 = array_column($orders1['json']['data']['data'], 'order_number');
    assert(!in_array($orderNumber, $orderNumbers1), "Order MUST NOT leak to Jashore store!");
});

echo "\n\033[32mALL 8 MULTI-TENANT TESTS PASSED WITH 100% SUCCESS!\033[0m\n";

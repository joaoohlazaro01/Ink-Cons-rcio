<?php
require __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$accessToken = getenv('MERCADOPAGO_ACCESS_TOKEN');
if (!$accessToken) {
    die('Access token not configured.');
}

$mp = new \MercadoPago\SDK();
$mp->setAccessToken($accessToken);

// Create a simple preference for testing
$preference = new \MercadoPago\Preference();
$item = new \MercadoPago\Item();
$item->title = 'Teste de Pagamento - Ink Studio';
$item->quantity = 1;
$item->unit_price = 1.00; // R$ 1,00 test value
$preference->items = [$item];
$preference->back_urls = [
    "success" => "http://localhost/Tatuagem/checkout_success.php",
    "failure" => "http://localhost/Tatuagem/checkout_failure.php",
    "pending" => "http://localhost/Tatuagem/checkout_pending.php"
];
$preference->auto_return = "approved";
$preference->save();
$prefId = $preference->id;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Checkout Mercado Pago – Ink Studio</title>
    <script src="https://sdk.mercadopago.com/js/v2"></script>
    <style>
        body {font-family: Arial, sans-serif; background:#121212; color:#e0e0e0; text-align:center; padding:40px;}
        #checkout-btn {margin-top:30px;}
    </style>
</head>
<body>
    <h1>Teste de pagamento</h1>
    <p>Clique no botão abaixo para pagar R$ 1,00 via Mercado Pago.</p>
    <div id="checkout-btn"></div>
    <script>
        const mp = new MercadoPago('<?= getenv("MERCADOPAGO_PUBLIC_KEY") ?>', {
            locale: 'pt-BR'
        });
        mp.checkout({
            preference: { id: '<?= $prefId ?>' },
            render: {
                container: '#checkout-btn',
                label: 'Pagar R$ 1,00'
            }
        });
    </script>
</body>
</html>

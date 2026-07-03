<?php
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$pagamento_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Buscar pagamento e verificar propriedade
$stmt = $pdo->prepare("SELECT p.*, pu.plano_nome FROM pagamentos p 
                       JOIN planos_usuario pu ON p.plano_usuario_id = pu.id 
                       WHERE p.id = :id AND p.usuario_id = :usuario_id");
$stmt->execute(['id' => $pagamento_id, 'usuario_id' => $usuario_id]);
$pagamento = $stmt->fetch();

if (!$pagamento) {
    die("Pagamento não encontrado.");
}

if ($pagamento['status'] === 'pago') {
    header('Location: client.php?msg=ja_pago');
    exit;
}

$erro_mp = '';
$init_point = '';

$host = $_SERVER['HTTP_HOST'];

// detecta ngrok
if (str_contains($host, 'ngrok')) {
    $base_url = "https://agreeably-untortious-amaya.ngrok-free.dev/Tatuagem";
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $base_url = $protocol . $host . "/Tatuagem";
}

$back_url = $base_url . "/retorno.php";

    $preference_data = [
        "items" => [
            [
                "title" => "Consórcio Tattoo - Plano " .
                    $pagamento['plano_nome'] .
                    " (Parcela #" .
                    $pagamento['parcela_numero'] .
                    ")",
                "quantity" => 1,
                "currency_id" => "BRL",
                "unit_price" => (float)$pagamento['valor']
            ]
        ],
        "external_reference" => (string)$pagamento['id'],
        "back_urls" => [
            "success" => $back_url,
            "pending" => $back_url,
            "failure" => $back_url
        ],
        "auto_return" => "approved"
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.mercadopago.com/checkout/preferences',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($preference_data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . MP_ACCESS_TOKEN,
            'Content-Type: application/json',
            'X-Idempotency-Key: ' . uniqid('mp_', true)
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $erro_mp = 'Erro CURL: ' . curl_error($ch);
        curl_close($ch);
    } else {

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res_data = json_decode($response, true);

        if (($http_code == 200 || $http_code == 201)
            && isset($res_data['init_point'])) {

            header('Location: ' . $res_data['init_point']);
            exit;
        } else {

            $erro_mp =
                'Erro Mercado Pago<br><br>' .
                'HTTP: ' . $http_code .
                '<br><br><pre>' .
                htmlspecialchars($response) .
                '</pre>';
        }
    }

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento da Fatura - <?php echo STUDIO_NOME; ?></title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px;">

    <div class="glass-card" style="width: 100%; max-width: 600px; animation: scaleIn 0.4s ease;">
        <!-- Cabeçalho -->
        <div style="text-align: center; margin-bottom: 30px; border-bottom: 1px solid var(--border-color); padding-bottom: 20px;">
            <div style="display: inline-flex; align-items: center; gap: 8px; font-weight: 800; font-size: 1.1rem; margin-bottom: 15px;">
                <i class="fa-solid fa-yin-yang" style="color: var(--accent-gold);"></i>
                <span>INK <span style="color: var(--accent-gold);">CONSÓRCIO</span></span>
            </div>
            <h2>Finalizar Pagamento</h2>
            <p style="color: var(--text-secondary); font-size: 14px;">Parcela #<?php echo $pagamento['parcela_numero']; ?> - Plano <?php echo $pagamento['plano_nome']; ?></p>
        </div>

        <!-- Detalhes do Valor -->
        <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <div>
                <p style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase;">Valor da Mensalidade</p>
                <h3 style="font-size: 28px; color: var(--text-primary); font-weight: 800; margin-top: 4px;">R$ <?php echo number_format($pagamento['valor'], 2, ',', '.'); ?></h3>
            </div>
            <div style="text-align: right;">
                <span class="badge badge-warning"><i class="fa-regular fa-clock"></i> Aguardando Pagamento</span>
            </div>
        </div>

        <!-- Alertas de Erro da API -->
        <?php if (!empty($erro_mp)): ?>
            <div class="alert alert-danger" style="font-size: 13px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 18px;"></i>
                <div>
                    <strong>Integração com Mercado Pago falhou:</strong><br>
                    <?php echo htmlspecialchars($erro_mp); ?>
                </div>
            </div>
        <?php endif; ?>

       <!-- Opções de Pagamento -->
<div style="display: flex; flex-direction: column; gap: 15px;">

    <!-- Mercado Pago -->
    <form method="POST" action="pagamento.php?id=<?php echo $pagamento_id; ?>">
        <input type="hidden" name="gateway" value="mercadopago">
        <button type="submit" class="btn btn-purple btn-block" style="padding:16px;">
            <i class="fa-solid fa-credit-card"></i>
            Pagar via Mercado Pago
        </button>
    </form>

    <!-- Simular Pagamento Aprovado -->
    <form action="retorno.php" method="GET">
        <input type="hidden" name="status" value="approved">
        <input type="hidden" name="payment_id" value="<?php echo $pagamento_id; ?>">

        <button type="submit"
                class="btn btn-gold btn-block"
                style="padding:16px;">
            <i class="fa-solid fa-flask"></i>
            Simular Pagamento Aprovado
        </button>
    </form>

    <!-- Simular Pagamento Pendente -->
    <form action="retorno.php" method="GET">
        <input type="hidden" name="status" value="pending">
        <input type="hidden" name="payment_id" value="<?php echo $pagamento_id; ?>">

        <button type="submit"
                class="btn btn-outline btn-block"
                style="padding:16px;">
            <i class="fa-solid fa-clock"></i>
            Simular Pagamento Pendente
        </button>
    </form>

    <!-- Simular Pagamento Rejeitado -->
    <form action="retorno.php" method="GET">
        <input type="hidden" name="status" value="rejected">
        <input type="hidden" name="payment_id" value="<?php echo $pagamento_id; ?>">

        <button type="submit"
                class="btn btn-danger btn-block"
                style="padding:16px;">
            <i class="fa-solid fa-circle-xmark"></i>
            Simular Pagamento Rejeitado
        </button>
    </form>

    <a href="client.php"
       class="btn btn-outline btn-block"
       style="padding:12px;">
        <i class="fa-solid fa-arrow-left"></i>
        Voltar ao Painel
    </a>

</div>
    </div>

</body>
</html>

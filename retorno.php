<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$status = isset($_GET['status']) ? $_GET['status'] : '';
$payment_id_mp = isset($_GET['payment_id']) ? $_GET['payment_id'] : '';
$external_reference = isset($_GET['external_reference']) ? $_GET['external_reference'] : '';

// Identificar ID de pagamento local
$id_local = 0;
if (!empty($external_reference) && is_numeric($external_reference)) {
    $id_local = intval($external_reference);
} else if (!empty($payment_id_mp) && is_numeric($payment_id_mp)) {
    $id_local = intval($payment_id_mp);
}

$erro = '';
$pagamento = null;
$plano_usuario = null;

if ($id_local > 0) {
    // Buscar pagamento local
    $stmt = $pdo->prepare("SELECT * FROM pagamentos WHERE id = :id");
    $stmt->execute(['id' => $id_local]);
    $pagamento = $stmt->fetch();

    if ($pagamento) {
        // Buscar plano do usuário
        $stmt = $pdo->prepare("SELECT * FROM planos_usuario WHERE id = :id");
        $stmt->execute(['id' => $pagamento['plano_usuario_id']]);
        $plano_usuario = $stmt->fetch();
    } else {
        $erro = 'Registro de pagamento não localizado.';
    }
} else {
    $erro = 'ID do pagamento não foi fornecido.';
}

$processado = false;

// Processar a aprovação se o status for aprovado e o pagamento estiver pendente
if ($pagamento && $plano_usuario && $pagamento['status'] === 'pendente') {
    if ($status === 'approved' || $status === 'success' || $status === 'approved') {
        try {
            $pdo->beginTransaction();

            // 1. Atualizar status do pagamento local
            $stmt = $pdo->prepare("UPDATE pagamentos SET status = 'pago', metodo_pagamento = 'Pix / Cartão (Mercado Pago)', data_pagamento = NOW(), mercado_pago_id = :mp_id WHERE id = :id");
            $stmt->execute([
                'mp_id' => $payment_id_mp ? $payment_id_mp : 'SIMULADO_' . time(),
                'id' => $id_local
            ]);

            // 2. Incrementar parcelas pagas no plano do usuário
            $novas_parcelas = $plano_usuario['parcelas_pagas'] + 1;
            
            // Verificar contemplação automática por quitação (10 parcelas)
            $novo_status = $plano_usuario['status'];
            $metodo_contemplacao = $plano_usuario['metodo_contemplacao'];
            $data_contemplacao = $plano_usuario['data_contemplacao'];

            if ($novas_parcelas >= 10 && $plano_usuario['status'] !== 'quitado') {
                $novo_status = 'quitado';
                $metodo_contemplacao = 'quitacao';
                $data_contemplacao = date('Y-m-d H:i:s');
            } else if ($plano_usuario['status'] === 'ativo' && $novas_parcelas >= 10) {
                // Caso já estivesse contemplado por sorteio e agora quitou
                $novo_status = 'quitado';
            }

            $stmt = $pdo->prepare("UPDATE planos_usuario SET 
                                   parcelas_pagas = :parcelas, 
                                   status = :status, 
                                   metodo_contemplacao = :metodo, 
                                   data_contemplacao = :data_cont 
                                   WHERE id = :id");
            $stmt->execute([
                'parcelas' => $novas_parcelas,
                'status' => $novo_status,
                'metodo' => $metodo_contemplacao,
                'data_cont' => $data_contemplacao,
                'id' => $plano_usuario['id']
            ]);

            // 3. Gerar próxima parcela pendente (se for menor que 10 e se já não existir)
            $proxima_num = $pagamento['parcela_numero'] + 1;
            if ($proxima_num <= 10 && $novo_status === 'ativo' || ($novo_status === 'contemplado' && $proxima_num <= 10)) {
                // Verificar se já existe a próxima parcela
                $stmt = $pdo->prepare("SELECT id FROM pagamentos WHERE plano_usuario_id = :plano_id AND parcela_numero = :num");
                $stmt->execute(['plano_id' => $plano_usuario['id'], 'num' => $proxima_num]);
                if (!$stmt->fetch()) {
                    $stmt = $pdo->prepare("INSERT INTO pagamentos (usuario_id, plano_usuario_id, parcela_numero, valor, status) VALUES (:uid, :plano_id, :num, :valor, 'pendente')");
                    $stmt->execute([
                        'uid' => $pagamento['usuario_id'],
                        'plano_id' => $plano_usuario['id'],
                        'num' => $proxima_num,
                        'valor' => $pagamento['valor']
                    ]);
                }
            }

            $pdo->commit();
            $processado = true;
            
            // Recarregar os dados atualizados para exibir na tela
            $stmt = $pdo->prepare("SELECT * FROM planos_usuario WHERE id = :id");
            $stmt->execute(['id' => $plano_usuario['id']]);
            $plano_usuario = $stmt->fetch();

        } catch (Exception $e) {
            $pdo->rollBack();
            $erro = 'Erro ao atualizar dados: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retorno do Pagamento - <?php echo STUDIO_NOME; ?></title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px;">

    <div class="glass-card" style="width: 100%; max-width: 550px; text-align: center; padding: 40px 30px; animation: scaleIn 0.4s ease;">
        
        <?php if (!empty($erro)): ?>
            <!-- Tela de Erro -->
            <div style="font-size: 60px; color: var(--danger); margin-bottom: 20px;">
                <i class="fa-solid fa-circle-xmark"></i>
            </div>
            <h2 style="margin-bottom: 10px;">Falha no Processamento</h2>
            <p style="color: var(--text-secondary); margin-bottom: 30px;"><?php echo $erro; ?></p>
            <a href="client.php" class="btn btn-outline btn-block">Voltar ao Painel</a>

        <?php elseif ($status === 'approved' || $status === 'success' || ($pagamento && $pagamento['status'] === 'pago')): ?>
            <!-- Tela de Sucesso -->
            <div style="font-size: 60px; color: var(--success); margin-bottom: 20px; animation: pulse 2s infinite;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <h2 style="margin-bottom: 10px;">Pagamento Aprovado!</h2>
            <p style="color: var(--text-secondary); margin-bottom: 25px;">Obrigado! Identificamos o pagamento da Parcela #<?php echo $pagamento['parcela_numero']; ?> do seu plano <?php echo $plano_usuario['plano_nome']; ?>.</p>

            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 15px; text-align: left; margin-bottom: 30px; font-size: 14px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="color: var(--text-secondary);">Parcelas Pagas:</span>
                    <strong style="color: var(--accent-gold);"><?php echo $plano_usuario['parcelas_pagas']; ?> de 10</strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="color: var(--text-secondary);">Valor Quitado:</span>
                    <strong>R$ <?php echo number_format($plano_usuario['parcelas_pagas'] * $plano_usuario['valor_mensal'], 2, ',', '.'); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-secondary);">Status do Plano:</span>
                    <?php if ($plano_usuario['status'] === 'quitado'): ?>
                        <strong class="badge badge-success"><i class="fa-solid fa-gem"></i> Quitado & Liberado</strong>
                    <?php elseif ($plano_usuario['status'] === 'contemplado'): ?>
                        <strong class="badge badge-gold"><i class="fa-solid fa-yin-yang"></i> Contemplado</strong>
                    <?php else: ?>
                        <strong class="badge badge-info">Em Andamento</strong>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($plano_usuario['status'] === 'quitado' || $plano_usuario['status'] === 'contemplado'): ?>
                <div class="alert alert-success" style="margin-bottom: 20px; font-size: 13px;">
                    <i class="fa-solid fa-trophy"></i>
                    <strong>Parabéns!</strong> Seu agendamento de tatuagem já está liberado. Vá para a tela de agendamentos no painel.
                </div>
            <?php endif; ?>

            <a href="client.php" class="btn btn-gold btn-block">Acessar Meu Painel</a>

        <?php elseif ($status === 'pending'): ?>
            <!-- Tela de Pendente -->
            <div style="font-size: 60px; color: var(--warning); margin-bottom: 20px;">
                <i class="fa-solid fa-circle-notch fa-spin"></i>
            </div>
            <h2 style="margin-bottom: 10px;">Pagamento em Análise</h2>
            <p style="color: var(--text-secondary); margin-bottom: 30px;">O Mercado Pago está processando a sua transação. Assim que for aprovado, seu saldo será atualizado automaticamente!</p>
            <a href="client.php" class="btn btn-outline btn-block">Voltar ao Painel</a>

        <?php else: ?>
            <!-- Tela de Cancelado / Erro genérico -->
            <div style="font-size: 60px; color: var(--danger); margin-bottom: 20px;">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>
            <h2 style="margin-bottom: 10px;">Pagamento Cancelado</h2>
            <p style="color: var(--text-secondary); margin-bottom: 30px;">A transação foi recusada ou cancelada pelo gateway de pagamentos. Você pode tentar novamente.</p>
            <a href="client.php" class="btn btn-outline btn-block">Voltar ao Painel</a>
        <?php endif; ?>

    </div>

</body>
</html>

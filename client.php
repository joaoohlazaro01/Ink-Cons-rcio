<?php
session_start();
require_once __DIR__ . '/db.php';

// Verificar login
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_perfil'] !== 'cliente') {
    header('Location: index.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$erro = isset($_GET['erro']) ? htmlspecialchars(urldecode($_GET['erro'])) : '';
$sucesso = isset($_GET['sucesso']) ? (($_GET['sucesso'] === '1') ? 'Agendamento marcado com sucesso! Veja os detalhes abaixo.' : htmlspecialchars(urldecode($_GET['sucesso']))) : '';

// 1. Buscar informações do cliente
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
$stmt->execute(['id' => $usuario_id]);
$usuario = $stmt->fetch();

// 2. Buscar plano ativo do cliente
$stmt = $pdo->prepare("SELECT * FROM planos_usuario WHERE usuario_id = :uid");
$stmt->execute(['uid' => $usuario_id]);
$plano = $stmt->fetch();

// Se por algum erro o plano sumiu, recria
if (!$plano) {
    header('Location: index.php');
    exit;
}

// 3. Processar Novo Lance
if (isset($_POST['action']) && $_POST['action'] === 'dar_lance') {
    $parcelas_lance = intval($_POST['parcelas_lance']);
    $valor_lance = $parcelas_lance * $plano['valor_mensal'];
    
    // Validar se o lance é maior do que as parcelas restantes
    $parcelas_restantes = 10 - $plano['parcelas_pagas'];
    
    if ($parcelas_lance <= 0 || $parcelas_lance > $parcelas_restantes) {
        $erro = "Número de parcelas inválido. Você pode adiantar no máximo $parcelas_restantes parcelas.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO lances (usuario_id, plano_usuario_id, valor_lance, parcelas_pagas, status) 
                                   VALUES (:uid, :plano_id, :valor, :parcelas, 'pendente')");
            $stmt->execute([
                'uid' => $usuario_id,
                'plano_id' => $plano['id'],
                'valor' => $valor_lance,
                'parcelas' => $parcelas_lance
            ]);
            $sucesso = "Lance de R$ " . number_format($valor_lance, 2, ',', '.') . " ($parcelas_lance parcelas) ofertado com sucesso! Aguarde a apuração do administrador.";
        } catch (Exception $e) {
            $erro = "Erro ao ofertar lance: " . $e->getMessage();
        }
    }
}

// 4. Processar Cancelamento de Agendamento
if (isset($_POST['action']) && $_POST['action'] === 'cancelar_agendamento') {
    $agendamento_id = intval($_POST['agendamento_id']);
    try {
        $stmt = $pdo->prepare("DELETE FROM agendamentos WHERE id = :id AND usuario_id = :uid");
        $stmt->execute(['id' => $agendamento_id, 'uid' => $usuario_id]);
        header('Location: client.php?tab=agenda&sucesso=' . urlencode('Agendamento cancelado. Você pode marcar um novo horário.'));
        exit;
    } catch (Exception $e) {
        header('Location: client.php?tab=agenda&erro=' . urlencode('Erro ao cancelar agendamento: ' . $e->getMessage()));
        exit;
    }
}

// 5. Processar Agendamento de Tatuagem
if (isset($_POST['action']) && $_POST['action'] === 'agendar') {
    $tatuador_id = intval($_POST['tatuador_id']);
    $data_agendamento = $_POST['data_agendamento'];
    $horario = $_POST['horario'];
    $descricao = trim($_POST['descricao']);
    
    // Upload de referência (mock)
    $imagem_referencia = '';
    if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['imagem']['tmp_name'];
        $fileName = $_FILES['imagem']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
        $uploadFileDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadFileDir)) {
            mkdir($uploadFileDir, 0777, true);
        }
        $dest_path = $uploadFileDir . $newFileName;
        if(move_uploaded_file($fileTmpPath, $dest_path)) {
            $imagem_referencia = $newFileName;
        }
    }

    if ($tatuador_id <= 0 || empty($data_agendamento) || empty($horario)) {
        header('Location: client.php?tab=agenda&erro=' . urlencode('Selecione o profissional, a data e o horário!'));
        exit;
    } else {
        // Verificar se já existe agendamento neste horário com o mesmo tatuador
        $stmt = $pdo->prepare("SELECT id FROM agendamentos WHERE tatuador_id = :tid AND data_agendamento = :data AND horario = :hora AND status = 'agendado'");
        $stmt->execute(['tid' => $tatuador_id, 'data' => $data_agendamento, 'hora' => $horario]);
        if ($stmt->fetch()) {
            header('Location: client.php?tab=agenda&erro=' . urlencode('Desculpe, este horário já foi agendado. Escolha outro!'));
            exit;
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO agendamentos (usuario_id, tatuador_id, data_agendamento, horario, descricao, imagem_referencia, status) 
                                       VALUES (:uid, :tid, :data, :hora, :desc, :img, 'agendado')");
                $stmt->execute([
                    'uid' => $usuario_id,
                    'tid' => $tatuador_id,
                    'data' => $data_agendamento,
                    'hora' => $horario,
                    'desc' => $descricao,
                    'img' => $imagem_referencia
                ]);
                header('Location: client.php?tab=agenda&sucesso=1');
                exit;
            } catch (Exception $e) {
                header('Location: client.php?tab=agenda&erro=' . urlencode('Erro ao realizar agendamento: ' . $e->getMessage()));
                exit;
            }
        }
    }
}

// Recarregar os dados do plano após processamento de ações
$stmt = $pdo->prepare("SELECT * FROM planos_usuario WHERE usuario_id = :uid");
$stmt->execute(['uid' => $usuario_id]);
$plano = $stmt->fetch();

// 6. Buscar Histórico de Pagamentos do cliente
$stmt = $pdo->prepare("SELECT * FROM pagamentos WHERE usuario_id = :uid ORDER BY parcela_numero ASC");
$stmt->execute(['uid' => $usuario_id]);
$historico_pagamentos = $stmt->fetchAll();

// Achar a fatura atual (primeira pendente)
$fatura_atual = null;
foreach ($historico_pagamentos as $pag) {
    if ($pag['status'] === 'pendente') {
        $fatura_atual = $pag;
        break;
    }
}

// 7. Buscar Lances ofertados
$stmt = $pdo->prepare("SELECT * FROM lances WHERE usuario_id = :uid ORDER BY data_lance DESC");
$stmt->execute(['uid' => $usuario_id]);
$historico_lances = $stmt->fetchAll();

// 8. Buscar Agendamento ativo
$stmt = $pdo->prepare("SELECT a.*, t.nome as tatuador_nome, t.especialidade FROM agendamentos a 
                       JOIN tatuadores t ON a.tatuador_id = t.id 
                       WHERE a.usuario_id = :uid AND a.status = 'agendado' 
                       ORDER BY a.data_agendamento ASC LIMIT 1");
$stmt->execute(['uid' => $usuario_id]);
$agendamento_ativo = $stmt->fetch();

// 9. Buscar todos os Tatuadores e horários já ocupados para validação dinâmica no JS
$stmt = $pdo->query("SELECT * FROM tatuadores");
$tatuadores = $stmt->fetchAll();

$stmt = $pdo->query("SELECT tatuador_id, data_agendamento, horario FROM agendamentos WHERE status = 'agendado'");
$agendamentos_ocupados = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Cliente - <?php echo STUDIO_NOME; ?></title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <div class="dashboard-wrapper">
        <!-- Sidebar Desktop -->
        <aside class="sidebar">
            <div class="sidebar-logo">
                <i class="fa-solid fa-yin-yang"></i>
                <span>INK <span style="color: var(--accent-gold);">CONSÓRCIO</span></span>
            </div>
            
            <ul class="sidebar-menu">
                <li class="menu-item active" id="side-dash"><a href="javascript:void(0)" onclick="switchTab('dashboard')"><i class="fa-solid fa-chart-line"></i> <span>Dashboard</span></a></li>
                <li class="menu-item" id="side-lances"><a href="javascript:void(0)" onclick="switchTab('lances')"><i class="fa-solid fa-gavel"></i> <span>Ofertar Lances</span></a></li>
                <li class="menu-item" id="side-pagar"><a href="javascript:void(0)" onclick="switchTab('pagar')"><i class="fa-solid fa-credit-card"></i> <span>Histórico Financeiro</span></a></li>
                <li class="menu-item" id="side-agenda"><a href="javascript:void(0)" onclick="switchTab('agenda')"><i class="fa-solid fa-calendar-check"></i> <span>Agendar Sessão</span></a></li>
            </ul>

            <div class="sidebar-user">
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($usuario['nome'], 0, 1)); ?></div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars($usuario['nome']); ?></h4>
                        <p>Cliente</p>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn" title="Sair"><i class="fa-solid fa-right-from-bracket"></i></a>
            </div>
        </aside>

        <!-- Menu Inferior Mobile (AppBarber Client Style) -->
        <nav class="mobile-nav">
            <a href="javascript:void(0)" class="mobile-nav-item active" id="mob-dash" onclick="switchTab('dashboard')">
                <i class="fa-solid fa-house"></i>
                <span>Início</span>
            </a>
            <a href="javascript:void(0)" class="mobile-nav-item" id="mob-lances" onclick="switchTab('lances')">
                <i class="fa-solid fa-gavel"></i>
                <span>Lances</span>
            </a>
            <a href="javascript:void(0)" class="mobile-nav-item" id="mob-pagar" onclick="switchTab('pagar')">
                <i class="fa-solid fa-receipt"></i>
                <span>Faturas</span>
            </a>
            <a href="javascript:void(0)" class="mobile-nav-item" id="mob-agenda" onclick="switchTab('agenda')">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Agendar</span>
            </a>
            <a href="logout.php" class="mobile-nav-item" style="color: var(--danger);">
                <i class="fa-solid fa-sign-out-alt"></i>
                <span>Sair</span>
            </a>
        </nav>

        <!-- Área Principal de Conteúdo -->
        <main class="main-content">
            <!-- Cabeçalho Principal -->
            <div class="page-header">
                <div>
                    <h1 id="page-title">Bem-vindo, <?php echo explode(' ', htmlspecialchars($usuario['nome']))[0]; ?>!</h1>
                    <p style="color: var(--text-secondary); font-size: 13px; margin-top: 5px;">Acompanhe o andamento do seu consórcio e faça seu agendamento.</p>
                </div>
                <div class="user-details-mobile" style="display: none; align-items: center; gap: 10px;">
                    <!-- Mostrado apenas no cabeçalho mobile -->
                    <div class="user-avatar" style="width: 32px; height: 32px; font-size: 14px;"><?php echo strtoupper(substr($usuario['nome'], 0, 1)); ?></div>
                </div>
            </div>

            <!-- Alertas -->
            <?php if (!empty($erro)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo $erro; ?></div>
            <?php endif; ?>
            <?php if (!empty($sucesso)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $sucesso; ?></div>
            <?php endif; ?>

            <!-- ================= TAB: DASHBOARD ================= -->
            <section id="tab-dashboard" class="tab-section active">
                <!-- Métricas -->
                <div class="metrics-grid">
                    <div class="glass-card metric-card">
                        <div class="metric-data">
                            <p>Plano Selecionado</p>
                            <h3><?php echo $plano['plano_nome']; ?></h3>
                        </div>
                        <div class="metric-icon gold"><i class="fa-solid fa-gem"></i></div>
                    </div>
                    <div class="glass-card metric-card">
                        <div class="metric-data">
                            <p>Mensalidade</p>
                            <h3>R$ <?php echo number_format($plano['valor_mensal'], 2, ',', '.'); ?></h3>
                        </div>
                        <div class="metric-icon info"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                    </div>
                    <div class="glass-card metric-card">
                        <div class="metric-data">
                            <p>Quitado no Consórcio</p>
                            <h3>R$ <?php echo number_format($plano['parcelas_pagas'] * $plano['valor_mensal'], 2, ',', '.'); ?></h3>
                        </div>
                        <div class="metric-icon success"><i class="fa-solid fa-piggy-bank"></i></div>
                    </div>
                    <div class="glass-card metric-card">
                        <div class="metric-data">
                            <p>Status Contemplação</p>
                            <h3>
                                <?php if ($plano['status'] === 'quitado'): ?>
                                    <span style="color: var(--success); font-size: 16px;">Quitado / Liberado</span>
                                <?php elseif ($plano['status'] === 'contemplado'): ?>
                                    <span style="color: var(--accent-gold); font-size: 16px;">Contemplado!</span>
                                <?php else: ?>
                                    <span style="color: var(--warning); font-size: 16px;">Aguardando</span>
                                <?php endif; ?>
                            </h3>
                        </div>
                        <div class="metric-icon purple"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                    </div>
                </div>

                <!-- Progresso e Contemplação -->
                <div class="glass-card consortium-progress">
                    <div class="progress-header">
                        <h3>Progresso do Consórcio (Parcelas Quitadas)</h3>
                        <span style="color: var(--accent-gold); font-weight: 700;"><?php echo $plano['parcelas_pagas']; ?> de 10 meses</span>
                    </div>
                    <div class="progress-bar-wrapper">
                        <div class="progress-bar-fill" style="width: <?php echo ($plano['parcelas_pagas'] * 10); ?>%"></div>
                    </div>
                    <div class="progress-details">
                        <span>R$ 0,00</span>
                        <span>Crédito Acumulado: R$ <?php echo number_format($plano['parcelas_pagas'] * $plano['valor_mensal'], 2, ',', '.'); ?> / R$ <?php echo number_format($plano['valor_total'], 2, ',', '.'); ?></span>
                        <span>R$ <?php echo number_format($plano['valor_total'], 2, ',', '.'); ?></span>
                    </div>
                </div>

                <!-- Fatura Pendente Atual -->
                <div class="glass-card" style="margin-bottom: 30px;">
                    <h3 style="margin-bottom: 15px;"><i class="fa-solid fa-file-invoice-dollar" style="color: var(--accent-gold);"></i> Fatura Mensal Atual</h3>
                    
                    <?php if ($fatura_atual): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); padding: 18px; border-radius: var(--radius-md);">
                            <div>
                                <h4 style="font-size: 16px;">Parcela #<?php echo $fatura_atual['parcela_numero']; ?></h4>
                                <p style="font-size: 13px; color: var(--text-secondary); margin-top: 4px;">Valor de pagamento referente a mensalidade deste mês.</p>
                                <p style="font-size: 18px; color: var(--accent-gold); font-weight: 800; margin-top: 8px;">R$ <?php echo number_format($fatura_atual['valor'], 2, ',', '.'); ?></p>
                            </div>
                            <div>
                                <a href="pagamento.php?id=<?php echo $fatura_atual['id']; ?>" class="btn btn-gold">
                                    <i class="fa-solid fa-credit-card"></i> Pagar Fatura
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px; color: var(--text-secondary); font-size: 14px;">
                            <i class="fa-solid fa-circle-check" style="color: var(--success); font-size: 32px; margin-bottom: 12px; display: block;"></i>
                            Parabéns! Todas as faturas abertas foram quitadas! Nenhuma cobrança pendente.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Status de Contemplação Detalhado -->
                <div class="glass-card">
                    <h3 style="margin-bottom: 15px;"><i class="fa-solid fa-yin-yang" style="color: var(--accent-gold);"></i> Status do Seu Agendamento</h3>
                    <?php if ($plano['status'] === 'ativo'): ?>
                        <div style="background: rgba(255, 159, 67, 0.05); border: 1px solid rgba(255, 159, 67, 0.15); border-radius: var(--radius-md); padding: 20px; text-align: center;">
                            <i class="fa-solid fa-lock" style="font-size: 40px; color: var(--warning); margin-bottom: 15px;"></i>
                            <h4 style="font-size: 16px; margin-bottom: 8px;">Aguardando Liberação / Contemplação</h4>
                            <p style="font-size: 13px; color: var(--text-secondary); max-width: 500px; margin: 0 auto;">Para liberar o agendamento de sua tatuagem de <strong>R$ <?php echo number_format($plano['valor_total'], 2, ',', '.'); ?></strong>, continue pagando suas faturas para participar do sorteio mensal, faça uma proposta de lance na aba <strong>Lances</strong>, ou complete a quitação de 10 meses.</p>
                        </div>
                    <?php else: ?>
                        <div style="background: rgba(16, 172, 132, 0.05); border: 1px solid rgba(16, 172, 132, 0.15); border-radius: var(--radius-md); padding: 20px;">
                            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                                <div style="width: 50px; height: 50px; background: rgba(16, 172, 132, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--success);">
                                    <i class="fa-solid fa-unlock-keyhole"></i>
                                </div>
                                <div style="flex-grow: 1;">
                                    <h4 style="font-size: 16px;">Tatuagem Liberada para Agendamento!</h4>
                                    <p style="font-size: 13px; color: var(--text-secondary); margin-top: 4px;">
                                        Contemplado via: <strong><?php echo ucfirst($plano['metodo_contemplacao']); ?></strong> em <?php echo date('d/m/Y', strtotime($plano['data_contemplacao'])); ?>.
                                    </p>
                                </div>
                                <div>
                                    <?php if ($agendamento_ativo): ?>
                                        <button onclick="switchTab('agenda')" class="btn btn-outline">Ver Detalhes do Horário</button>
                                    <?php else: ?>
                                        <button onclick="switchTab('agenda')" class="btn btn-gold">Agendar Tatuagem Agora</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>


            <!-- ================= TAB: LANCES ================= -->
            <section id="tab-lances" class="tab-section">
                <div class="glass-card" style="margin-bottom: 30px;">
                    <h3 style="margin-bottom: 10px;"><i class="fa-solid fa-gavel" style="color: var(--accent-gold);"></i> Ofertar Lance de Antecipação</h3>
                    <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 25px;">
                        O lance é a oferta de adiantamento de parcelas. O cliente que ofertar o maior número de parcelas adiantadas no mês é contemplado! Se o seu lance não for o vencedor, você não paga nada. Se for o vencedor, o valor é quitado e seu crédito de tatuagem é liberado.
                    </p>

                    <?php if ($plano['status'] !== 'ativo'): ?>
                        <div class="alert alert-info">
                            <i class="fa-solid fa-info-circle"></i> Você já está contemplado! Não há necessidade de dar lances.
                        </div>
                    <?php else: ?>
                        <form method="POST" action="client.php">
                            <input type="hidden" name="action" value="dar_lance">
                            
                            <div class="form-group" style="max-width: 400px;">
                                <label>Quantidade de parcelas para adiantar</label>
                                <select name="parcelas_lance" class="form-control" style="background-color: var(--bg-secondary);">
                                    <?php 
                                    $max_adiantar = 10 - $plano['parcelas_pagas'];
                                    for ($i = 1; $i <= $max_adiantar; $i++): 
                                    ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?> Parcela(s) - R$ <?php echo number_format($i * $plano['valor_mensal'], 2, ',', '.'); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-gold"><i class="fa-solid fa-paper-plane"></i> Enviar Proposta de Lance</button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Histórico de lances -->
                <div class="glass-card">
                    <h3 style="margin-bottom: 15px;">Seus Lances Ofertados</h3>
                    <div class="table-wrapper">
                        <?php if (count($historico_lances) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Data do Lance</th>
                                        <th>Parcelas Ofertadas</th>
                                        <th>Valor Ofertado</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($historico_lances as $lance): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y H:i', strtotime($lance['data_lance'])); ?></td>
                                            <td><?php echo $lance['parcelas_pagas']; ?></td>
                                            <td>R$ <?php echo number_format($lance['valor_lance'], 2, ',', '.'); ?></td>
                                            <td>
                                                <?php if ($lance['status'] === 'pendente'): ?>
                                                    <span class="badge badge-warning">Em Apuração</span>
                                                <?php elseif ($lance['status'] === 'aprovado'): ?>
                                                    <span class="badge badge-success">Vencedor!</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">Não Contemplado</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--text-secondary);">Nenhum lance ofertado ainda.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>


            <!-- ================= TAB: HISTÓRICO FINANCEIRO ================= -->
            <section id="tab-pagar" class="tab-section">
                <div class="glass-card">
                    <h3 style="margin-bottom: 15px;"><i class="fa-solid fa-receipt" style="color: var(--accent-gold);"></i> Histórico de Faturas do Consórcio</h3>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nº da Parcela</th>
                                    <th>Valor</th>
                                    <th>Status</th>
                                    <th>Data de Vencimento / Pagamento</th>
                                    <th>Método</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historico_pagamentos as $pag): ?>
                                    <tr>
                                        <td><strong>Parcela #<?php echo $pag['parcela_numero']; ?></strong></td>
                                        <td>R$ <?php echo number_format($pag['valor'], 2, ',', '.'); ?></td>
                                        <td>
                                            <?php if ($pag['status'] === 'pago'): ?>
                                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> Pago</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning"><i class="fa-regular fa-clock"></i> Pendente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo $pag['status'] === 'pago' ? date('d/m/Y H:i', strtotime($pag['data_pagamento'])) : 'Pendente de Pagamento'; ?>
                                        </td>
                                        <td>
                                            <?php echo $pag['metodo_pagamento'] ? htmlspecialchars($pag['metodo_pagamento']) : '-'; ?>
                                        </td>
                                        <td>
                                            <?php if ($pag['status'] === 'pendente'): ?>
                                                <a href="pagamento.php?id=<?php echo $pag['id']; ?>" class="btn btn-gold" style="padding: 6px 14px; font-size: 12px;"><i class="fa-solid fa-credit-card"></i> Pagar</a>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-size: 12px;"><i class="fa-solid fa-lock"></i> Pago</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>


            <!-- ================= TAB: AGENDAMENTO ================= -->
            <section id="tab-agenda" class="tab-section">
                <?php if ($plano['status'] === 'ativo'): ?>
                    <!-- Travado se não estiver contemplado -->
                    <div class="glass-card" style="text-align: center; padding: 60px 40px;">
                        <div style="font-size: 70px; color: var(--text-muted); margin-bottom: 20px;">
                            <i class="fa-solid fa-calendar-times"></i>
                        </div>
                        <h2 style="margin-bottom: 12px;">Agendamento Bloqueado</h2>
                        <p style="color: var(--text-secondary); max-width: 500px; margin: 0 auto 25px;">Sua área de agendamento de horários (estilo AppBarber) estará liberada assim que você for contemplado pelo consórcio.</p>
                        <button onclick="switchTab('dashboard')" class="btn btn-gold">Voltar ao Início</button>
                    </div>
                <?php else: ?>
                    <!-- Liberado -->
                    <?php if ($agendamento_ativo): ?>
                        <!-- Se já possuir um agendamento ativo -->
                        <div class="glass-card" style="max-width: 700px; margin: 0 auto;">
                            <div style="text-align: center; margin-bottom: 25px;">
                                <div style="font-size: 50px; color: var(--accent-gold); margin-bottom: 15px;">
                                    <i class="fa-solid fa-calendar-check"></i>
                                </div>
                                <h2>Sua Sessão está Agendada!</h2>
                                <p style="color: var(--text-secondary);">Veja abaixo as informações completas do seu horário.</p>
                            </div>

                            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 25px; margin-bottom: 25px;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                    <div>
                                        <p style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Profissional</p>
                                        <h4 style="font-size: 16px; margin-top: 4px;"><?php echo htmlspecialchars($agendamento_ativo['tatuador_nome']); ?></h4>
                                        <p style="font-size: 12px; color: var(--accent-gold);"><?php echo htmlspecialchars($agendamento_ativo['especialidade']); ?></p>
                                    </div>
                                    <div>
                                        <p style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Data e Horário</p>
                                        <h4 style="font-size: 16px; margin-top: 4px;"><?php echo date('d/m/Y', strtotime($agendamento_ativo['data_agendamento'])); ?></h4>
                                        <p style="font-size: 14px; font-weight: 700; color: var(--text-primary);"><?php echo substr($agendamento_ativo['horario'], 0, 5); ?>h</p>
                                    </div>
                                </div>
                                <div style="margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 15px;">
                                    <p style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Descrição da Tatuagem</p>
                                    <p style="font-size: 13px; color: var(--text-secondary); margin-top: 4px;"><?php echo nl2br(htmlspecialchars($agendamento_ativo['descricao'])); ?></p>
                                </div>
                                <?php if ($agendamento_ativo['imagem_referencia']): ?>
                                    <div style="margin-top: 15px;">
                                        <p style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px;">Imagem de Referência</p>
                                        <img src="uploads/<?php echo htmlspecialchars($agendamento_ativo['imagem_referencia']); ?>" style="max-width: 150px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);" alt="Referência">
                                    </div>
                                <?php endif; ?>
                            </div>

                            <form method="POST" action="client.php" onsubmit="return confirm('Deseja realmente cancelar este agendamento? O horário será liberado para outros clientes.');">
                                <input type="hidden" name="action" value="cancelar_agendamento">
                                <input type="hidden" name="agendamento_id" value="<?php echo $agendamento_ativo['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-block"><i class="fa-solid fa-calendar-minus"></i> Desmarcar Sessão</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <!-- Formulário de Agendamento Passo a Passo -->
                        <div class="glass-card scheduling-container">
                            <h2 style="text-align: center; margin-bottom: 25px;"><i class="fa-regular fa-calendar-plus" style="color: var(--accent-gold);"></i> Escolha seu Horário</h2>

                            <!-- Passos Progressivos -->
                            <div class="wizard-steps">
                                <div class="wizard-step active" id="step-header-1">
                                    <div class="step-num">1</div>
                                    <div class="step-label">Tatuador</div>
                                </div>
                                <div class="wizard-step" id="step-header-2">
                                    <div class="step-num">2</div>
                                    <div class="step-label">Data e Hora</div>
                                </div>
                                <div class="wizard-step" id="step-header-3">
                                    <div class="step-num">3</div>
                                    <div class="step-label">Ideia & Confirmar</div>
                                </div>
                            </div>

                            <form method="POST" action="client.php" enctype="multipart/form-data" id="agendarForm">
<input type="hidden" name="action" value="agendar">
    <input type="hidden" name="tatuador_id" id="selected_tatuador_id">
                                
                                <!-- PASSO 1 -->
                                <div class="wizard-pane active" id="step-pane-1">
                                    <h3 style="font-size: 16px; margin-bottom: 15px; text-align: center;">Com quem você deseja tatuar?</h3>
                                    
                                    <div class="artists-grid">
                                        <?php foreach ($tatuadores as $t): 
                                            $is_unico = (count($tatuadores) === 1);
                                        ?>
                                            <div class="artist-card <?php echo $is_unico ? 'selected' : ''; ?>" onclick="selectArtist(this, <?php echo $t['id']; ?>, '<?php echo htmlspecialchars($t['nome']); ?>')">
                                                <input type="radio" name="tatuador_id" value="<?php echo $t['id']; ?>" id="tatuador_radio_<?php echo $t['id']; ?>" style="display:none;" <?php echo $is_unico ? 'checked' : ''; ?> required>
                                                <div class="artist-img">
                                                    <i class="fa-solid fa-circle-user"></i>
                                                </div>
                                                <h4><?php echo htmlspecialchars($t['nome']); ?></h4>
                                                <p><?php echo htmlspecialchars($t['especialidade']); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="text-align: right;">
                                        <button type="button" class="btn btn-gold" id="btn-next-1" <?php echo (count($tatuadores) === 1) ? '' : 'disabled'; ?> onclick="goToStep(2)">Próximo Passo <i class="fa-solid fa-arrow-right"></i></button>
                                    </div>
                                </div>

                                <!-- PASSO 2: Selecionar Data e Hora -->
                                <div class="wizard-pane" id="step-pane-2">
                                    <h3 style="font-size: 16px; margin-bottom: 15px; text-align: center;">Escolha o Dia e o Horário</h3>
                                    
                                    <div class="form-group" style="max-width: 300px; margin: 0 auto 25px;">
                                        <label>Selecione a data</label>
                                        <!-- Data mínima é amanhã -->
                                        <input type="date" id="data_agendamento" name="data_agendamento" class="form-control" style="background-color: var(--bg-secondary);" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required onchange="onDateOrArtistChange()">
                                    </div>

                                    <div id="horarios-section" style="display:none;">
                                        <h4 style="font-size: 14px; margin-bottom: 10px; text-align: center; text-transform: uppercase; color: var(--text-secondary);">Horários Disponíveis</h4>
                                        <div class="time-grid" id="time-grid-container">
                                            <!-- Inserido dinamicamente via JS -->
                                        </div>
                                    </div>

                                    <!-- Campo oculto para o horário selecionado -->
                                    <input type="hidden" name="horario" id="selected_horario">

                                    <div style="display: flex; justify-content: space-between; margin-top: 30px;">
                                        <button type="button" class="btn btn-outline" onclick="goToStep(1)"><i class="fa-solid fa-arrow-left"></i> Anterior</button>
                                        <button type="button" class="btn btn-gold" id="btn-next-2" disabled onclick="goToStep(3)">Próximo Passo <i class="fa-solid fa-arrow-right"></i></button>
                                    </div>
                                </div>

                                <!-- PASSO 3: Descrição e Confirmação -->
                                <div class="wizard-pane" id="step-pane-3">
                                    <h3 style="font-size: 16px; margin-bottom: 20px; text-align: center;">Detalhes da sua Tatuagem</h3>

                                    <div class="form-group">
                                        <label>Nos conte a sua ideia de tatuagem (Tamanho aproximado, local do corpo, referências...)</label>
                                        <textarea name="descricao" rows="4" class="form-control" placeholder="Ex: Tatuagem de lobo geométrica no antebraço, cerca de 15cm..." required></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label>Imagem de Referência (opcional)</label>
                                        <input type="file" name="imagem" accept="image/*" class="form-control">
                                        <p style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Envie uma imagem para auxiliar o tatuador no seu decalque.</p>
                                    </div>

                                    <!-- Resumo das Escolhas -->
                                    <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 15px; margin-top: 25px; margin-bottom: 25px;">
                                        <h4 style="font-size: 13px; color: var(--accent-gold); text-transform: uppercase; margin-bottom: 10px;">Resumo do Agendamento</h4>
                                        <p style="font-size: 14px;">Profissional: <strong id="resumo-artista">-</strong></p>
                                        <p style="font-size: 14px;">Data: <strong id="resumo-data">-</strong></p>
                                        <p style="font-size: 14px;">Horário: <strong id="resumo-hora">-</strong></p>
                                    </div>

                                    <div style="display: flex; justify-content: space-between;">
                                        <button type="button" class="btn btn-outline" onclick="goToStep(2)"><i class="fa-solid fa-arrow-left"></i> Anterior</button>
                                        <button type="submit" class="btn btn-gold" onclick="return validarAgendamento()"><i class="fa-solid fa-calendar-check"></i> Finalizar Agendamento</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

        </main>
    </div>

    <!-- Scripts do Painel do Cliente -->
    <script>
        // Dados de agendamentos ocupados injetados pelo PHP para verificação rápida sem AJAX
        const agendamentosOcupados = <?php echo json_encode($agendamentos_ocupados); ?>;
        
        let selectedArtistId = <?php echo count($tatuadores) === 1 ? $tatuadores[0]['id'] : 'null'; ?>;
        let selectedArtistName = "<?php echo count($tatuadores) === 1 ? addslashes($tatuadores[0]['nome']) : ''; ?>";
        let currentStep = 1;

        // Troca de Abas
        function switchTab(tabId) {
            // Esconder todas as abas de nível principal (não os passos do wizard)
            document.querySelectorAll('.tab-section').forEach(el => el.classList.remove('active'));
            // Remover classe active do menu desktop
            document.querySelectorAll('.sidebar-menu .menu-item').forEach(el => el.classList.remove('active'));
            // Remover classe active do menu mobile
            document.querySelectorAll('.mobile-nav-item').forEach(el => el.classList.remove('active'));

            // Exibir a aba selecionada
            document.getElementById('tab-' + tabId).classList.add('active');

            // Se for a aba de agenda, reativar o passo 1 do wizard
            if (tabId === 'agenda') {
                const stepPane1 = document.getElementById('step-pane-1');
                if (stepPane1 && currentStep === 1) {
                    document.querySelectorAll('[id^="step-pane-"]').forEach(el => el.classList.remove('active'));
                    stepPane1.classList.add('active');
                }
            }

            // Ativar item na sidebar desktop
            const sideEl = document.getElementById('side-' + (tabId === 'dashboard' ? 'dash' : tabId));
            if (sideEl) sideEl.classList.add('active');

            // Ativar item na barra mobile
            const mobEl = document.getElementById('mob-' + (tabId === 'dashboard' ? 'dash' : tabId));
            if (mobEl) mobEl.classList.add('active');

            // Mudar título do cabeçalho
            const titleMap = {
                'dashboard': 'Bem-vindo, <?php echo explode(' ', htmlspecialchars($usuario['nome']))[0]; ?>!',
                'lances': 'Lances do Consórcio',
                'pagar': 'Histórico Financeiro',
                'agenda': 'Agendamento de Horário'
            };
            document.getElementById('page-title').innerText = titleMap[tabId];
        }

        // Navegação do Passo a Passo (Wizard) do Agendamento
        function goToStep(step) {
            // Esconder os painéis do wizard
            document.getElementById('step-pane-1').classList.remove('active');
            document.getElementById('step-pane-2').classList.remove('active');
            document.getElementById('step-pane-3').classList.remove('active');

            // Desativar classes de progresso visual
            document.getElementById('step-header-1').classList.remove('active', 'completed');
            document.getElementById('step-header-2').classList.remove('active', 'completed');
            document.getElementById('step-header-3').classList.remove('active', 'completed');

            // Ativar painel e atualizar status visuais
            document.getElementById('step-pane-' + step).classList.add('active');

            if (step === 1) {
                document.getElementById('step-header-1').classList.add('active');
            } else if (step === 2) {
                document.getElementById('step-header-1').classList.add('completed');
                document.getElementById('step-header-2').classList.add('active');
            } else if (step === 3) {
                document.getElementById('step-header-1').classList.add('completed');
                document.getElementById('step-header-2').classList.add('completed');
                document.getElementById('step-header-3').classList.add('active');
                
                // Atualizar resumo do agendamento
                document.getElementById('resumo-artista').innerText = selectedArtistName;
                
                // Formatar Data
                const dateInput = document.getElementById('data_agendamento').value;
                const dateParts = dateInput.split('-');
                document.getElementById('resumo-data').innerText = `${dateParts[2]}/${dateParts[1]}/${dateParts[0]}`;
                
                // Formatar Hora
                const hora = document.getElementById('selected_horario').value;
                document.getElementById('resumo-hora').innerText = hora + 'h';
            }
            
            currentStep = step;
        }

        // Seleção do Tatuador
        function selectArtist(el, id, nome) {
            selectedArtistId = id;
            selectedArtistName = nome;

            // Marcar cartão como selecionado visualmente
            document.querySelectorAll('.artist-card').forEach(el => el.classList.remove('selected'));
            document.getElementById('selected_tatuador_id').value = id;

            // Marcar o rádio oculto
            document.getElementById('tatuador_radio_' + id).checked = true;
            // Desbloquear botão de avançar
            document.getElementById('btn-next-1').disabled = false;
            
            // Se mudar de artista, recalcular horários
            onDateOrArtistChange();
        }

        // Chamado quando muda a data ou o tatuador para gerar a grade de horários livre de colisões
        function onDateOrArtistChange() {
            const dataSel = document.getElementById('data_agendamento').value;
            if (!selectedArtistId || !dataSel) return;

            document.getElementById('horarios-section').style.display = 'block';
            
            const gridContainer = document.getElementById('time-grid-container');
            gridContainer.innerHTML = ''; // Limpar slots anteriores

            // Horários padrões do estúdio
            const horariosDisponiveis = ['09:00', '10:30', '13:30', '15:00', '16:30', '18:00'];
            
            // Encontrar horários já ocupados para este artista neste dia específico
            const ocupadosNesseDia = agendamentosOcupados
                .filter(ag => parseInt(ag.tatuador_id) === selectedArtistId && ag.data_agendamento === dataSel)
                .map(ag => ag.horario.substring(0, 5));

            horariosDisponiveis.forEach(hora => {
                const slot = document.createElement('div');
                slot.className = 'time-slot';
                slot.innerText = hora;

                if (ocupadosNesseDia.includes(hora)) {
                    slot.classList.add('disabled');
                    slot.innerText += ' (Ocupado)';
                } else {
                    slot.onclick = () => selectTime(slot, hora);
                }

                gridContainer.appendChild(slot);
            });

            // Limpar horário selecionado anterior caso mude o dia/artista
            document.getElementById('selected_horario').value = '';
            document.getElementById('btn-next-2').disabled = true;
        }

        // Seleção de Horário
        function selectTime(element, hora) {
            // Desmarcar todos os outros
            document.querySelectorAll('.time-slot').forEach(el => el.classList.remove('selected'));
            // Selecionar o atual
            element.classList.add('selected');
            
            // Setar no input oculto
            document.getElementById('selected_horario').value = hora;
            
            // Habilitar avançar
            document.getElementById('btn-next-2').disabled = false;
        }
        
        // Validação antes de enviar o formulário
        function validarAgendamento() {
            const horario = document.getElementById('selected_horario').value;
            const data = document.getElementById('data_agendamento').value;
            const descricao = document.querySelector('[name="descricao"]').value.trim();
            
            if (!selectedArtistId) {
                alert('Por favor, selecione um profissional!');
                goToStep(1);
                return false;
            }
            if (!data) {
                alert('Por favor, selecione uma data!');
                goToStep(2);
                return false;
            }
            if (!horario) {
                alert('Por favor, selecione um horário disponível!');
                goToStep(2);
                return false;
            }
            if (!descricao) {
                alert('Por favor, descreva sua ideia de tatuagem!');
                return false;
            }
            return true;
        }
        
        // Se houver parâmetro de aba na URL (ex: redirecionado após agendamento)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('tab')) {
            switchTab(urlParams.get('tab'));
        }
    </script>
</body>
</html>

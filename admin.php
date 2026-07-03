<?php
session_start();
require_once __DIR__ . '/db.php';

// Verificar login de administrador
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_perfil'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$erro = '';
$sucesso = '';

// --- PROCESSAR AÇÃO: APROVAR PAGAMENTO MANUALMENTE ---
if (isset($_GET['action']) && $_GET['action'] === 'aprovar_pagamento') {
    $pagamento_id = intval($_GET['id']);
    
    // Buscar pagamento
    $stmt = $pdo->prepare("SELECT * FROM pagamentos WHERE id = :id AND status = 'pendente'");
    $stmt->execute(['id' => $pagamento_id]);
    $pagamento = $stmt->fetch();

    if ($pagamento) {
        try {
            $pdo->beginTransaction();

            // 1. Marcar como pago
            $stmt = $pdo->prepare("UPDATE pagamentos SET status = 'pago', metodo_pagamento = 'Aprovado por Admin', data_pagamento = NOW() WHERE id = :id");
            $stmt->execute(['id' => $pagamento_id]);

            // 2. Incrementar parcelas no plano do usuário
            $stmt = $pdo->prepare("SELECT * FROM planos_usuario WHERE id = :id");
            $stmt->execute(['id' => $pagamento['plano_usuario_id']]);
            $plano = $stmt->fetch();

            $novas_parcelas = $plano['parcelas_pagas'] + 1;
            $novo_status = $plano['status'];
            $metodo_contemplacao = $plano['metodo_contemplacao'];
            $data_contemplacao = $plano['data_contemplacao'];

            if ($novas_parcelas >= 10 && $plano['status'] !== 'quitado') {
                $novo_status = 'quitado';
                $metodo_contemplacao = 'quitacao';
                $data_contemplacao = date('Y-m-d H:i:s');
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
                'id' => $plano['id']
            ]);

            // 3. Gerar próxima parcela pendente (se for menor que 10 e se já não existir)
            $proxima_num = $pagamento['parcela_numero'] + 1;
            if ($proxima_num <= 10 && ($novo_status === 'ativo' || $novo_status === 'contemplado')) {
                $stmt = $pdo->prepare("SELECT id FROM pagamentos WHERE plano_usuario_id = :plano_id AND parcela_numero = :num");
                $stmt->execute(['plano_id' => $plano['id'], 'num' => $proxima_num]);
                if (!$stmt->fetch()) {
                    $stmt = $pdo->prepare("INSERT INTO pagamentos (usuario_id, plano_usuario_id, parcela_numero, valor, status) VALUES (:uid, :plano_id, :num, :valor, 'pendente')");
                    $stmt->execute([
                        'uid' => $pagamento['usuario_id'],
                        'plano_id' => $plano['id'],
                        'num' => $proxima_num,
                        'valor' => $pagamento['valor']
                    ]);
                }
            }

            $pdo->commit();
            $sucesso = "Pagamento da Parcela #{$pagamento['parcela_numero']} aprovado com sucesso!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $erro = "Erro ao aprovar pagamento: " . $e->getMessage();
        }
    } else {
        $erro = "Pagamento já aprovado ou não localizado.";
    }
}

// --- PROCESSAR AÇÃO: CONTEMPLAR MANUALMENTE ---
if (isset($_GET['action']) && $_GET['action'] === 'contemplar_manual') {
    $plano_usuario_id = intval($_GET['plano_id']);
    
    $stmt = $pdo->prepare("SELECT pu.*, u.nome FROM planos_usuario pu JOIN usuarios u ON pu.usuario_id = u.id WHERE pu.id = :id AND pu.status = 'ativo'");
    $stmt->execute(['id' => $plano_usuario_id]);
    $plano_alvo = $stmt->fetch();

    if ($plano_alvo) {
        try {
            $stmt = $pdo->prepare("UPDATE planos_usuario SET 
                                   status = 'contemplado', 
                                   metodo_contemplacao = 'sorteio', 
                                   data_contemplacao = NOW() 
                                   WHERE id = :id");
            $stmt->execute(['id' => $plano_usuario_id]);
            $sucesso = "Cliente <strong>{$plano_alvo['nome']}</strong> contemplado(a) com sucesso! O agendamento foi liberado.";
        } catch (Exception $e) {
            $erro = "Erro ao contemplar cliente: " . $e->getMessage();
        }
    } else {
        $erro = "Cliente não encontrado ou já está contemplado.";
    }
}

// --- PROCESSAR AÇÃO: CONFIRMAR SORTEIO ---
if (isset($_GET['action']) && $_GET['action'] === 'confirmar_sorteio') {
    $plano_usuario_id = intval($_GET['plano_usuario_id']);
    
    $stmt = $pdo->prepare("SELECT pu.*, u.nome FROM planos_usuario pu JOIN usuarios u ON pu.usuario_id = u.id WHERE pu.id = :id AND pu.status = 'ativo'");
    $stmt->execute(['id' => $plano_usuario_id]);
    $plano_vencedor = $stmt->fetch();

    if ($plano_vencedor) {
        try {
            $stmt = $pdo->prepare("UPDATE planos_usuario SET 
                                   status = 'contemplado', 
                                   metodo_contemplacao = 'sorteio', 
                                   data_contemplacao = NOW() 
                                   WHERE id = :id");
            $stmt->execute(['id' => $plano_usuario_id]);
            $sucesso = "Sorteio gravado com sucesso! Cliente <strong>{$plano_vencedor['nome']}</strong> foi contemplado(a)!";
        } catch (Exception $e) {
            $erro = "Erro ao gravar sorteio: " . $e->getMessage();
        }
    } else {
        $erro = "O cliente já está contemplado ou não foi localizado.";
    }
}

// --- PROCESSAR AÇÃO: APURAR LANCE ---
if (isset($_POST['action']) && $_POST['action'] === 'processar_lance') {
    $lance_id = intval($_POST['lance_id']);
    $decisao = $_POST['decisao']; // 'aprovar' ou 'recusar'

    $stmt = $pdo->prepare("SELECT l.*, pu.usuario_id, u.nome, pu.plano_nome FROM lances l 
                           JOIN planos_usuario pu ON l.plano_usuario_id = pu.id 
                           JOIN usuarios u ON l.usuario_id = u.id 
                           WHERE l.id = :id AND l.status = 'pendente'");
    $stmt->execute(['id' => $lance_id]);
    $lance = $stmt->fetch();

    if ($lance) {
        try {
            $pdo->beginTransaction();

            if ($decisao === 'aprovar') {
                // 1. Aprovar o lance selecionado
                $stmt = $pdo->prepare("UPDATE lances SET status = 'aprovado' WHERE id = :id");
                $stmt->execute(['id' => $lance_id]);

                // 2. Contemplar o cliente do plano
                $stmt = $pdo->prepare("UPDATE planos_usuario SET 
                                       status = 'contemplado', 
                                       metodo_contemplacao = 'lance', 
                                       data_contemplacao = NOW() 
                                       WHERE id = :id");
                $stmt->execute(['id' => $lance['plano_usuario_id']]);

                // 3. Quitar as parcelas do lance na tabela de pagamentos
                // Pegar parcelas pendentes (do número atual em diante) correspondente às parcelas_pagas do lance
                $stmt = $pdo->prepare("SELECT id, parcela_numero FROM pagamentos WHERE plano_usuario_id = :plano_id AND status = 'pendente' ORDER BY parcela_numero ASC LIMIT :limite");
                // PDO emulado às vezes precisa de bindValue para INTEGER em LIMIT
                $stmt->bindValue(':plano_id', $lance['plano_usuario_id'], PDO::PARAM_INT);
                $stmt->bindValue(':limite', $lance['parcelas_pagas'], PDO::PARAM_INT);
                $stmt->execute();
                $pagamentos_a_quitar = $stmt->fetchAll();

                $quitados_count = 0;
                foreach ($pagamentos_a_quitar as $p_quit) {
                    $stmt = $pdo->prepare("UPDATE pagamentos SET status = 'pago', metodo_pagamento = 'Lance Contemplado', data_pagamento = NOW() WHERE id = :id");
                    $stmt->execute(['id' => $p_quit['id']]);
                    $quitados_count++;
                }

                // Incrementar a quantidade de parcelas quitadas no plano
                $stmt = $pdo->prepare("UPDATE planos_usuario SET parcelas_pagas = parcelas_pagas + :count WHERE id = :id");
                $stmt->execute(['count' => $quitados_count, 'id' => $lance['plano_usuario_id']]);

                // Recriar a próxima fatura se não tiver atingido 10 e não houver pendente
                $stmt = $pdo->prepare("SELECT * FROM planos_usuario WHERE id = :id");
                $stmt->execute(['id' => $lance['plano_usuario_id']]);
                $plano_atualizado = $stmt->fetch();

                if ($plano_atualizado['parcelas_pagas'] < 10) {
                    $proxima = $plano_atualizado['parcelas_pagas'] + 1;
                    $stmt = $pdo->prepare("SELECT id FROM pagamentos WHERE plano_usuario_id = :plano_id AND parcela_numero = :num");
                    $stmt->execute(['plano_id' => $plano_atualizado['id'], 'num' => $proxima]);
                    if (!$stmt->fetch()) {
                        $stmt = $pdo->prepare("INSERT INTO pagamentos (usuario_id, plano_usuario_id, parcela_numero, valor, status) VALUES (:uid, :plano_id, :num, :valor, 'pendente')");
                        $stmt->execute([
                            'uid' => $plano_atualizado['usuario_id'],
                            'plano_id' => $plano_atualizado['id'],
                            'num' => $proxima,
                            'valor' => $plano_atualizado['valor_mensal']
                        ]);
                    }
                } else {
                    // Se quitou as 10
                    $stmt = $pdo->prepare("UPDATE planos_usuario SET status = 'quitado' WHERE id = :id");
                    $stmt->execute(['id' => $plano_atualizado['id']]);
                }

                // 4. Recusar todos os outros lances PENDENTES daquele mesmo grupo/plano neste momento
                $stmt = $pdo->prepare("UPDATE lances SET status = 'recusado' WHERE plano_usuario_id = :plano_id AND status = 'pendente'");
                $stmt->execute(['plano_id' => $lance['plano_usuario_id']]);

                $sucesso = "Lance de R$ " . number_format($lance['valor_lance'], 2, ',', '.') . " do(a) cliente <strong>{$lance['nome']}</strong> APROVADO! O plano foi contemplado.";
            } else {
                // Recusar o lance
                $stmt = $pdo->prepare("UPDATE lances SET status = 'recusado' WHERE id = :id");
                $stmt->execute(['id' => $lance_id]);
                $sucesso = "Lance do(a) cliente <strong>{$lance['nome']}</strong> recusado com sucesso.";
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $erro = "Erro ao processar decisão do lance: " . $e->getMessage();
        }
    } else {
        $erro = "Lance não encontrado ou já processado.";
    }
}

// --- BUSCAR DADOS DE VISUALIZAÇÃO GERAIS ---
// 1. Métricas
$count_clientes = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'cliente'")->fetchColumn();
$count_ativos = $pdo->query("SELECT COUNT(*) FROM planos_usuario WHERE status = 'ativo'")->fetchColumn();
$count_contemplados = $pdo->query("SELECT COUNT(*) FROM planos_usuario WHERE status IN ('contemplado', 'quitado')")->fetchColumn();
$total_recebido = $pdo->query("SELECT SUM(valor) FROM pagamentos WHERE status = 'pago'")->fetchColumn() ?: 0.00;

// 2. Lista de Clientes e Planos
$stmt = $pdo->query("SELECT u.nome, u.email, u.telefone, pu.plano_nome, pu.parcelas_pagas, pu.valor_mensal, pu.status, pu.id as plano_id 
                     FROM usuarios u 
                     JOIN planos_usuario pu ON u.id = pu.usuario_id 
                     ORDER BY u.criado_em DESC");
$membros = $stmt->fetchAll();

// 3. Faturas Pendentes (para aprovação manual)
$stmt = $pdo->query("SELECT p.*, u.nome, pu.plano_nome FROM pagamentos p 
                     JOIN usuarios u ON p.usuario_id = u.id 
                     JOIN planos_usuario pu ON p.plano_usuario_id = pu.id 
                     WHERE p.status = 'pendente' 
                     ORDER BY p.data_criacao DESC");
$faturas_pendentes = $stmt->fetchAll();

// 4. Lances Pendentes
$stmt = $pdo->query("SELECT l.*, u.nome, pu.plano_nome, pu.parcelas_pagas as pagas_atual 
                     FROM lances l 
                     JOIN usuarios u ON l.usuario_id = u.id 
                     JOIN planos_usuario pu ON l.plano_usuario_id = pu.id 
                     WHERE l.status = 'pendente' 
                     ORDER BY l.valor_lance DESC");
$lances_pendentes = $stmt->fetchAll();

// 5. Agendamentos
$stmt = $pdo->query("SELECT a.*, u.nome as cliente_nome, u.telefone as cliente_tel, t.nome as tatuador_nome 
                     FROM agendamentos a 
                     JOIN usuarios u ON a.usuario_id = u.id 
                     JOIN tatuadores t ON a.tatuador_id = t.id 
                     ORDER BY a.data_agendamento ASC, a.horario ASC");
$agendamentos = $stmt->fetchAll();

// 6. Buscar Clientes Ativos Elegíveis para Sorteio por plano
$elegiveis_bronze = $pdo->query("SELECT pu.id, u.nome FROM planos_usuario pu JOIN usuarios u ON pu.usuario_id = u.id WHERE pu.plano_nome = 'Bronze' AND pu.status = 'ativo' AND pu.parcelas_pagas > 0")->fetchAll(PDO::FETCH_ASSOC);
$elegiveis_prata = $pdo->query("SELECT pu.id, u.nome FROM planos_usuario pu JOIN usuarios u ON pu.usuario_id = u.id WHERE pu.plano_nome = 'Prata' AND pu.status = 'ativo' AND pu.parcelas_pagas > 0")->fetchAll(PDO::FETCH_ASSOC);
$elegiveis_ouro = $pdo->query("SELECT pu.id, u.nome FROM planos_usuario pu JOIN usuarios u ON pu.usuario_id = u.id WHERE pu.plano_nome = 'Ouro' AND pu.status = 'ativo' AND pu.parcelas_pagas > 0")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Administrativo - <?php echo STUDIO_NOME; ?></title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <div class="dashboard-wrapper">
        <!-- Sidebar Admin -->
        <aside class="sidebar">
            <div class="sidebar-logo">
                <i class="fa-solid fa-yin-yang" style="color: var(--accent-gold);"></i>
                <span>PAINEL <span style="color: var(--accent-gold);">ADMIN</span></span>
            </div>
            
            <ul class="sidebar-menu">
                <li class="menu-item active" id="side-clientes"><a href="javascript:void(0)" onclick="switchTab('clientes')"><i class="fa-solid fa-users"></i> <span>Clientes</span></a></li>
                <li class="menu-item" id="side-financeiro"><a href="javascript:void(0)" onclick="switchTab('financeiro')"><i class="fa-solid fa-circle-dollar-to-slot"></i> <span>Aprovar Faturas</span></a></li>
                <li class="menu-item" id="side-sorteios"><a href="javascript:void(0)" onclick="switchTab('sorteios')"><i class="fa-solid fa-wand-magic-sparkles"></i> <span>Sorteador</span></a></li>
                <li class="menu-item" id="side-lances"><a href="javascript:void(0)" onclick="switchTab('lances')"><i class="fa-solid fa-gavel"></i> <span>Apurar Lances</span></a></li>
                <li class="menu-item" id="side-agendas"><a href="javascript:void(0)" onclick="switchTab('agendas')"><i class="fa-solid fa-calendar-days"></i> <span>Agenda Geral</span></a></li>
            </ul>

            <div class="sidebar-user">
                <div class="user-info">
                    <div class="user-avatar" style="background: var(--accent-purple-gradient); color: white;"><i class="fa-solid fa-user-gear"></i></div>
                    <div class="user-details">
                        <h4>Admin Studio</h4>
                        <p>Gestão Geral</p>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn" title="Sair"><i class="fa-solid fa-right-from-bracket"></i></a>
            </div>
        </aside>

        <!-- Menu Mobile Admin -->
        <nav class="mobile-nav">
            <a href="javascript:void(0)" class="mobile-nav-item active" id="mob-clientes" onclick="switchTab('clientes')">
                <i class="fa-solid fa-users"></i>
                <span>Clientes</span>
            </a>
            <a href="javascript:void(0)" class="mobile-nav-item" id="mob-financeiro" onclick="switchTab('financeiro')">
                <i class="fa-solid fa-file-invoice-dollar"></i>
                <span>Faturas</span>
            </a>
            <a href="javascript:void(0)" class="mobile-nav-item" id="mob-sorteios" onclick="switchTab('sorteios')">
                <i class="fa-solid fa-gift"></i>
                <span>Sorteador</span>
            </a>
            <a href="javascript:void(0)" class="mobile-nav-item" id="mob-lances" onclick="switchTab('lances')">
                <i class="fa-solid fa-gavel"></i>
                <span>Lances</span>
            </a>
            <a href="javascript:void(0)" class="mobile-nav-item" id="mob-agendas" onclick="switchTab('agendas')">
                <i class="fa-solid fa-calendar"></i>
                <span>Agenda</span>
            </a>
        </nav>

        <!-- Área Principal de Conteúdo -->
        <main class="main-content">
            <!-- Cabeçalho Admin -->
            <div class="page-header">
                <div>
                    <h1 id="page-title">Gestão de Consórcios</h1>
                    <p style="color: var(--text-secondary); font-size: 13px; margin-top: 5px;">Painel Geral do Estúdio de Tatuagem</p>
                </div>
            </div>

            <!-- Alertas -->
            <?php if (!empty($erro)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo $erro; ?></div>
            <?php endif; ?>
            <?php if (!empty($sucesso)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $sucesso; ?></div>
            <?php endif; ?>

            <!-- Métricas Gerais -->
            <div class="metrics-grid">
                <div class="glass-card metric-card">
                    <div class="metric-data">
                        <p>Total Clientes</p>
                        <h3><?php echo $count_clientes; ?></h3>
                    </div>
                    <div class="metric-icon gold"><i class="fa-solid fa-users"></i></div>
                </div>
                <div class="glass-card metric-card">
                    <div class="metric-data">
                        <p>Consórcios Ativos</p>
                        <h3><?php echo $count_ativos; ?></h3>
                    </div>
                    <div class="metric-icon info"><i class="fa-solid fa-yin-yang"></i></div>
                </div>
                <div class="glass-card metric-card">
                    <div class="metric-data">
                        <p>Tattoos Contempladas</p>
                        <h3><?php echo $count_contemplados; ?></h3>
                    </div>
                    <div class="metric-icon success"><i class="fa-solid fa-circle-check"></i></div>
                </div>
                <div class="glass-card metric-card">
                    <div class="metric-data">
                        <p>Faturamento Geral</p>
                        <h3>R$ <?php echo number_format($total_recebido, 2, ',', '.'); ?></h3>
                    </div>
                    <div class="metric-icon purple"><i class="fa-solid fa-sack-dollar"></i></div>
                </div>
            </div>

            <!-- ================= TAB: CLIENTES ================= -->
            <section id="tab-clientes" class="tab-section active">
                <div class="glass-card">
                    <h3 style="margin-bottom: 15px;">Listagem de Clientes e Planos</h3>
                    <div class="table-wrapper">
                        <?php if (count($membros) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nome / Email</th>
                                        <th>Telefone</th>
                                        <th>Plano</th>
                                        <th>Parcelas Pagas</th>
                                        <th>Valor Mensal</th>
                                        <th>Status do Consórcio</th>
                                        <th>Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($membros as $m): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($m['nome']); ?></strong><br>
                                                <span style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($m['email']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($m['telefone']); ?></td>
                                            <td><?php echo htmlspecialchars($m['plano_nome']); ?></td>
                                            <td><strong style="color: var(--accent-gold);"><?php echo $m['parcelas_pagas']; ?> / 10</strong></td>
                                            <td>R$ <?php echo number_format($m['valor_mensal'], 2, ',', '.'); ?></td>
                                            <td>
                                                <?php if ($m['status'] === 'quitado'): ?>
                                                    <span class="badge badge-success">Quitado & Liberado</span>
                                                <?php elseif ($m['status'] === 'contemplado'): ?>
                                                    <span class="badge badge-gold">Contemplado</span>
                                                <?php else: ?>
                                                    <span class="badge badge-info">Em Andamento</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($m['status'] === 'ativo'): ?>
                                                    <a href="admin.php?action=contemplar_manual&plano_id=<?php echo $m['plano_id']; ?>" 
                                                       class="btn btn-gold" 
                                                       style="padding: 6px 12px; font-size: 11px;"
                                                       onclick="return confirm('Liberar agendamento de tatuagem para <?php echo addslashes($m['nome']); ?>?');"
                                                    >
                                                        <i class="fa-solid fa-unlock"></i> Contemplar
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--success); font-size: 13px;"><i class="fa-solid fa-check-circle"></i> Liberado</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--text-secondary);">Nenhum cliente cadastrado no consórcio.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- ================= TAB: APROVAR FATURAS ================= -->
            <section id="tab-financeiro" class="tab-section">
                <div class="glass-card">
                    <h3 style="margin-bottom: 5px;">Aprovação de Faturas Pendentes</h3>
                    <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">Use esta tela para dar baixa manual em faturas pagas via Pix externo ou depósito, ou para testar o fluxo localmente.</p>
                    
                    <div class="table-wrapper">
                        <?php if (count($faturas_pendentes) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Plano</th>
                                        <th>Fatura</th>
                                        <th>Valor</th>
                                        <th>Gerada Em</th>
                                        <th>Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($faturas_pendentes as $fat): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($fat['nome']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($fat['plano_nome']); ?></td>
                                            <td>Parcela #<?php echo $fat['parcela_numero']; ?></td>
                                            <td><strong style="color: var(--accent-gold);">R$ <?php echo number_format($fat['valor'], 2, ',', '.'); ?></strong></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($fat['data_criacao'])); ?></td>
                                            <td>
                                                <a href="admin.php?action=aprovar_pagamento&id=<?php echo $fat['id']; ?>" class="btn btn-gold" style="padding: 6px 14px; font-size: 12px;" onclick="return confirm('Deseja realmente aprovar este pagamento manualmente?');">
                                                    <i class="fa-solid fa-circle-check"></i> Dar Baixa
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--text-secondary);">Nenhuma fatura aguardando aprovação no momento.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- ================= TAB: SORTEADOR ================= -->
            <section id="tab-sorteios" class="tab-section">
                <div class="glass-card" style="margin-bottom: 30px;">
                    <h3><i class="fa-solid fa-wand-magic-sparkles" style="color: var(--accent-gold);"></i> Realizar Sorteio do Consórcio</h3>
                    <p style="color: var(--text-secondary); font-size: 13px; margin-top: 5px; margin-bottom: 25px;">
                        Escolha o grupo de consórcio e faça o sorteio mensal. Apenas clientes <strong>ativos</strong> com pelo menos <strong>1 parcela paga</strong> participam do sorteio.
                    </p>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                        <!-- Grupo Bronze -->
                        <div class="glass-card" style="background: rgba(255,255,255,0.01);">
                            <h4>Grupo Bronze</h4>
                            <p style="font-size: 12px; color: var(--text-secondary); margin-top: 4px; margin-bottom: 15px;">Crédito de R$ 600,00</p>
                            <div style="font-size: 14px; color: var(--text-primary); margin-bottom: 15px;">
                                Elegíveis: <strong><?php echo count($elegiveis_bronze); ?> cliente(s)</strong>
                            </div>
                            <?php if (count($elegiveis_bronze) > 0): ?>
                                <button onclick="iniciarSorteio('Bronze', <?php echo htmlspecialchars(json_encode($elegiveis_bronze)); ?>)" class="btn btn-gold btn-block">Sortear Grupo</button>
                            <?php else: ?>
                                <button class="btn btn-outline btn-block" disabled>Nenhum Elegível</button>
                            <?php endif; ?>
                        </div>

                        <!-- Grupo Prata -->
                        <div class="glass-card" style="background: rgba(255,255,255,0.01);">
                            <h4>Grupo Prata</h4>
                            <p style="font-size: 12px; color: var(--text-secondary); margin-top: 4px; margin-bottom: 15px;">Crédito de R$ 1.200,00</p>
                            <div style="font-size: 14px; color: var(--text-primary); margin-bottom: 15px;">
                                Elegíveis: <strong><?php echo count($elegiveis_prata); ?> cliente(s)</strong>
                            </div>
                            <?php if (count($elegiveis_prata) > 0): ?>
                                <button onclick="iniciarSorteio('Prata', <?php echo htmlspecialchars(json_encode($elegiveis_prata)); ?>)" class="btn btn-purple btn-block">Sortear Grupo</button>
                            <?php else: ?>
                                <button class="btn btn-outline btn-block" disabled>Nenhum Elegível</button>
                            <?php endif; ?>
                        </div>

                        <!-- Grupo Ouro -->
                        <div class="glass-card" style="background: rgba(255,255,255,0.01);">
                            <h4>Grupo Ouro</h4>
                            <p style="font-size: 12px; color: var(--text-secondary); margin-top: 4px; margin-bottom: 15px;">Crédito de R$ 2.500,00</p>
                            <div style="font-size: 14px; color: var(--text-primary); margin-bottom: 15px;">
                                Elegíveis: <strong><?php echo count($elegiveis_ouro); ?> cliente(s)</strong>
                            </div>
                            <?php if (count($elegiveis_ouro) > 0): ?>
                                <button onclick="iniciarSorteio('Ouro', <?php echo htmlspecialchars(json_encode($elegiveis_ouro)); ?>)" class="btn btn-gold btn-block" style="background: var(--accent-gold-gradient);">Sortear Grupo</button>
                            <?php else: ?>
                                <button class="btn btn-outline btn-block" disabled>Nenhum Elegível</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Painel de Animação do Tambor do Sorteio -->
                <div id="sorteio-drum-section" class="glass-card sorteio-container" style="display:none; border-color: var(--accent-gold); max-width: 600px; margin: 30px auto 0;">
                    <h3 id="sorteio-titulo">Sorteando Grupo...</h3>
                    <p style="color: var(--text-secondary); font-size: 13px;">Girando o globo do estúdio</p>
                    
                    <div class="sorteio-drum-wrapper">
                        <div id="drum-name" class="sorteio-drum">Embaralhando nomes...</div>
                    </div>
                    
                    <div id="vencedor-box" style="display:none; animation: fadeIn 0.5s ease; margin-top: 20px;">
                        <h4 style="color: var(--success); font-size: 20px; margin-bottom: 8px;"><i class="fa-solid fa-trophy"></i> Contemplado!</h4>
                        <div style="font-size: 28px; font-weight: 800; color: white; margin: 15px 0;" id="vencedor-nome">João Silva</div>
                        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 20px;">O crédito de tatuagem foi liberado para este cliente.</p>
                        
                        <a href="#" id="btn-gravar-sorteio" class="btn btn-gold"><i class="fa-solid fa-circle-check"></i> Confirmar Contemplação</a>
                    </div>
                </div>
            </section>

            <!-- ================= TAB: APURAR LANCES ================= -->
            <section id="tab-lances" class="tab-section">
                <div class="glass-card">
                    <h3 style="margin-bottom: 5px;">Lances Aguardando Apuração</h3>
                    <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">O cliente com maior lance (mais parcelas adiantadas) no mês deve ser o vencedor. Ao aprovar, o consórcio é contemplado e as parcelas são dadas como pagas.</p>
                    
                    <div class="table-wrapper">
                        <?php if (count($lances_pendentes) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Plano</th>
                                        <th>Parcelas Ofertadas</th>
                                        <th>Total do Lance</th>
                                        <th>Pago Atual</th>
                                        <th>Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lances_pendentes as $lan): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($lan['nome']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($lan['plano_nome']); ?></td>
                                            <td><strong style="color: var(--accent-gold);"><?php echo $lan['parcelas_pagas']; ?> parcelas</strong></td>
                                            <td>R$ <?php echo number_format($lan['valor_lance'], 2, ',', '.'); ?></td>
                                            <td><?php echo $lan['pagas_atual']; ?> / 10 quitadas</td>
                                            <td>
                                                <div style="display: flex; gap: 8px;">
                                                    <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('Ao APROVAR, este cliente será CONTEMPLADO e as faturas do lance serão dadas como QUITADAS. Confirmar?');">
                                                        <input type="hidden" name="action" value="processar_lance">
                                                        <input type="hidden" name="decisao" value="aprovar">
                                                        <input type="hidden" name="lance_id" value="<?php echo $lan['id']; ?>">
                                                        <button type="submit" class="btn btn-gold" style="padding: 6px 12px; font-size: 11px;"><i class="fa-solid fa-check"></i> Aprovar</button>
                                                    </form>
                                                    <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('Deseja realmente RECUSAR este lance?');">
                                                        <input type="hidden" name="action" value="processar_lance">
                                                        <input type="hidden" name="decisao" value="recusar">
                                                        <input type="hidden" name="lance_id" value="<?php echo $lan['id']; ?>">
                                                        <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size: 11px;"><i class="fa-solid fa-times"></i> Recusar</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--text-secondary);">Nenhum lance pendente de apuração.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- ================= TAB: AGENDA GERAL ================= -->
            <section id="tab-agendas" class="tab-section">
                <div class="glass-card">
                    <h3 style="margin-bottom: 15px;"><i class="fa-solid fa-calendar" style="color: var(--accent-gold);"></i> Agenda Geral de Sessões</h3>
                    
                    <div class="table-wrapper">
                        <?php if (count($agendamentos) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Data / Hora</th>
                                        <th>Cliente</th>
                                        <th>Profissional</th>
                                        <th>Descrição / Referência</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($agendamentos as $ag): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo date('d/m/Y', strtotime($ag['data_agendamento'])); ?></strong><br>
                                                <span style="font-size: 13px; color: var(--accent-gold); font-weight: 700;"><?php echo substr($ag['horario'], 0, 5); ?>h</span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($ag['cliente_nome']); ?></strong><br>
                                                <span style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($ag['cliente_tel']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($ag['tatuador_nome']); ?></td>
                                            <td>
                                                <span style="font-size: 13px; color: var(--text-secondary);"><?php echo nl2br(htmlspecialchars($ag['descricao'])); ?></span>
                                                <?php if ($ag['imagem_referencia']): ?>
                                                    <div style="margin-top: 8px;">
                                                        <a href="uploads/<?php echo htmlspecialchars($ag['imagem_referencia']); ?>" target="_blank" style="font-size: 11px; color: var(--accent-gold); display: inline-flex; align-items: center; gap: 4px;">
                                                            <i class="fa-solid fa-image"></i> Ver Imagem de Referência
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($ag['status'] === 'agendado'): ?>
                                                    <span class="badge badge-gold"><i class="fa-solid fa-calendar-check"></i> Agendado</span>
                                                <?php elseif ($ag['status'] === 'concluido'): ?>
                                                    <span class="badge badge-success">Concluído</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">Cancelado</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--text-secondary);">Nenhuma sessão de tatuagem agendada no momento.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- Scripts de Controle -->
    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab-section').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.sidebar-menu .menu-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.mobile-nav-item').forEach(el => el.classList.remove('active'));

            document.getElementById('tab-' + tabId).classList.add('active');

            const sideEl = document.getElementById('side-' + tabId);
            if (sideEl) sideEl.classList.add('active');

            const mobEl = document.getElementById('mob-' + tabId);
            if (mobEl) mobEl.classList.add('active');

            const titleMap = {
                'clientes': 'Membros do Consórcio',
                'financeiro': 'Aprovação de Pagamentos',
                'sorteios': 'Sorteio de Tatuagem',
                'lances': 'Apuração de Lances',
                'agendas': 'Agenda Geral de Sessões'
            };
            document.getElementById('page-title').innerText = titleMap[tabId];
        }

        // Animação Dinâmica de Sorteio
        function iniciarSorteio(grupo, elegiveis) {
            if (elegiveis.length === 0) return;

            // Mostrar área do tambor
            const drumSection = document.getElementById('sorteio-drum-section');
            drumSection.style.display = 'block';
            drumSection.scrollIntoView({ behavior: 'smooth' });

            document.getElementById('sorteio-titulo').innerText = 'Sorteando Grupo ' + grupo + '...';
            document.getElementById('vencedor-box').style.display = 'none';

            const drumName = document.getElementById('drum-name');
            drumName.classList.add('spinning');

            let index = 0;
            // Intervalo rápido de nomes passando (efeito roleta)
            const interval = setInterval(() => {
                drumName.innerText = elegiveis[index].nome;
                index = (index + 1) % elegiveis.length;
            }, 80);

            // Parar após 3 segundos e definir o vencedor
            setTimeout(() => {
                clearInterval(interval);
                drumName.classList.remove('spinning');

                // Escolha do vencedor aleatório
                const vencedorIndex = Math.floor(Math.random() * elegiveis.length);
                const vencedor = elegiveis[vencedorIndex];

                drumName.innerText = vencedor.nome;
                
                // Mostrar caixa de resultado com botão para gravar no banco
                document.getElementById('vencedor-nome').innerText = vencedor.nome;
                document.getElementById('vencedor-box').style.display = 'block';
                
                // Configurar o link de gravação
                document.getElementById('btn-gravar-sorteio').href = 'admin.php?action=confirmar_sorteio&plano_usuario_id=' + vencedor.id;
            }, 3000);
        }
    </script>
</body>
</html>

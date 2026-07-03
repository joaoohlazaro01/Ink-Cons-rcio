<?php
session_start();
require_once __DIR__ . '/db.php';

// Redireciona se já estiver logado
if (isset($_SESSION['usuario_id'])) {
    if ($_SESSION['usuario_perfil'] === 'admin') {
        header('Location: admin.php');
        exit;
    } else {
        header('Location: client.php');
        exit;
    }
}

$erro = '';
$sucesso = '';

// Processamento do Cadastro
if (isset($_POST['action']) && $_POST['action'] === 'register') {
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];
    $telefone = trim($_POST['telefone']);
    $cpf = trim($_POST['cpf']);
    $plano = $_POST['plano'];

    if (empty($nome) || empty($email) || empty($senha) || empty($telefone) || empty($cpf) || empty($plano)) {
        $erro = 'Todos os campos são obrigatórios!';
    } else {
        // Verificar se e-mail já existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            $erro = 'Este e-mail já está cadastrado!';
        } else {
            try {
                $pdo->beginTransaction();

                // Inserir usuário
                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, telefone, cpf, perfil) VALUES (:nome, :email, :senha, :telefone, :cpf, 'cliente')");
                $stmt->execute([
                    'nome' => $nome,
                    'email' => $email,
                    'senha' => $senha_hash,
                    'telefone' => $telefone,
                    'cpf' => $cpf
                ]);
                $usuario_id = $pdo->lastInsertId();

                // Detalhes do Plano Selecionado
                $valor_mensal = 0;
                $valor_total = 0;
                if ($plano === 'Bronze') {
                    $valor_mensal = 60.00;
                    $valor_total = 600.00;
                } elseif ($plano === 'Prata') {
                    $valor_mensal = 120.00;
                    $valor_total = 1200.00;
                } elseif ($plano === 'Ouro') {
                    $valor_mensal = 250.00;
                    $valor_total = 2500.00;
                }

                // Inserir plano ativo
                $stmt = $pdo->prepare("INSERT INTO planos_usuario (usuario_id, plano_nome, valor_mensal, valor_total) VALUES (:usuario_id, :plano_nome, :valor_mensal, :valor_total)");
                $stmt->execute([
                    'usuario_id' => $usuario_id,
                    'plano_nome' => $plano,
                    'valor_mensal' => $valor_mensal,
                    'valor_total' => $valor_total
                ]);
                $plano_usuario_id = $pdo->lastInsertId();

                // Gerar primeira parcela pendente
                $stmt = $pdo->prepare("INSERT INTO pagamentos (usuario_id, plano_usuario_id, parcela_numero, valor, status) VALUES (:usuario_id, :plano_usuario_id, 1, :valor, 'pendente')");
                $stmt->execute([
                    'usuario_id' => $usuario_id,
                    'plano_usuario_id' => $plano_usuario_id,
                    'valor' => $valor_mensal
                ]);

                $pdo->commit();

                // Logar automaticamente
                $_SESSION['usuario_id'] = $usuario_id;
                $_SESSION['usuario_nome'] = $nome;
                $_SESSION['usuario_perfil'] = 'cliente';

                header('Location: client.php');
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $erro = 'Erro ao realizar cadastro: ' . $e->getMessage();
            }
        }
    }
}

// Processamento do Login
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];

    if (empty($email) || empty($senha)) {
        $erro = 'Por favor, preencha o e-mail e a senha!';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($senha, $usuario['senha'])) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_perfil'] = $usuario['perfil'];

            if ($usuario['perfil'] === 'admin') {
                header('Location: admin.php');
            } else {
                header('Location: client.php');
            }
            exit;
        } else {
            $erro = 'E-mail ou senha incorretos!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo STUDIO_NOME; ?> - Consórcio de Tatuagem</title>
    <link rel="stylesheet" href="styles.css">
    <!-- FontAwesome para ícones -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <!-- Cabeçalho / Navbar -->
    <header style="background: rgba(9, 9, 13, 0.9); backdrop-filter: blur(10px); position: sticky; top: 0; z-index: 100; border-bottom: 1px solid var(--border-color); padding: 15px 5%;">
        <div style="max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; position: relative;">
            <a href="index.php" style="display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 1.3rem;">
                <i class="fa-solid fa-yin-yang" style="color: var(--accent-gold); font-size: 1.6rem;"></i>
                <span style="font-family: 'Outfit', sans-serif;">INK <span style="color: var(--accent-gold);">CONSÓRCIO</span></span>
            </a>
            
            <!-- Botão do Menu Mobile -->
            <button id="menu-toggle" style="display: none; font-size: 22px; color: var(--text-primary); cursor: pointer;">
                <i class="fa-solid fa-bars"></i>
            </button>
            
            <nav id="nav-menu" class="nav-links-container">
                <a href="#como-funciona" class="nav-link" onclick="closeMobileMenu()">Como Funciona</a>
                <a href="#planos" class="nav-link" onclick="closeMobileMenu()">Planos</a>
                <a href="#tatuadores" class="nav-link" onclick="closeMobileMenu()">Tatuadores</a>
                <button onclick="openModal('loginModal'); closeMobileMenu()" class="btn btn-outline" style="padding: 8px 18px; font-size: 13px;">Login</button>
                <button onclick="openModal('registerModal'); closeMobileMenu()" class="btn btn-gold" style="padding: 8px 18px; font-size: 13px;">Cadastre-se</button>
            </nav>
        </div>
    </header>

    <!-- Exibição de alertas se houver erro -->
    <?php if (!empty($erro)): ?>
        <div style="max-width: 1200px; margin: 20px auto 0; padding: 0 20px;">
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation"></i> <?php echo $erro; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Seção Hero -->
    <section class="hero">
        <div class="hero-content">
            <div class="badge-logo">
                <i class="fa-solid fa-gem"></i> O Primeiro Consórcio de Tatuagem do Brasil
            </div>
            <h1>Faça sua Tatuagem dos Sonhos pagando por <span>Mensalidades</span></h1>
            <p>Escolha seu plano mensal, vá pagando aos poucos e seja contemplado por sorteio, lances ou quitação! Agende seu horário diretamente pelo sistema no melhor estilo AppBarber.</p>
            <div style="display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;">
                <button onclick="openModal('registerModal')" class="btn btn-gold"><i class="fa-solid fa-rocket"></i> Entrar no Consórcio</button>
                <a href="#como-funciona" class="btn btn-outline"><i class="fa-solid fa-circle-info"></i> Entenda como funciona</a>
            </div>
        </div>
    </section>

    <!-- Seção Como Funciona -->
    <section id="como-funciona" style="padding: 80px 20px; max-width: 1200px; margin: 0 auto;">
        <div class="section-title">
            <h2>Como Funciona o Consórcio?</h2>
            <p>O jeito mais inteligente de planejar e fazer a sua tatuagem.</p>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; margin-top: 40px;">
            <div class="glass-card" style="text-align: center;">
                <div style="width: 50px; height: 50px; background: rgba(203, 161, 53, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 20px; color: var(--accent-gold); font-weight: 700;">1</div>
                <h3 style="margin-bottom: 12px;">Escolha o Plano</h3>
                <p style="font-size: 14px; color: var(--text-secondary);">Cadastre-se e escolha a mensalidade que cabe no seu bolso (R$ 60, R$ 120 ou R$ 250).</p>
            </div>
            
            <div class="glass-card" style="text-align: center;">
                <div style="width: 50px; height: 50px; background: rgba(203, 161, 53, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 20px; color: var(--accent-gold); font-weight: 700;">2</div>
                <h3 style="margin-bottom: 12px;">Pague Mensalmente</h3>
                <p style="font-size: 14px; color: var(--text-secondary);">Faça os pagamentos de forma fácil e segura via Pix ou Cartão integrados com o Mercado Pago.</p>
            </div>
            
            <div class="glass-card" style="text-align: center;">
                <div style="width: 50px; height: 50px; background: rgba(203, 161, 53, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 20px; color: var(--accent-gold); font-weight: 700;">3</div>
                <h3 style="margin-bottom: 12px;">Contemplação</h3>
                <p style="font-size: 14px; color: var(--text-secondary);">Seja sorteado mensalmente, oferte lances para adiantar ou seja liberado automaticamente ao quitar o plano.</p>
            </div>
            
            <div class="glass-card" style="text-align: center;">
                <div style="width: 50px; height: 50px; background: rgba(203, 161, 53, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 20px; color: var(--accent-gold); font-weight: 700;">4</div>
                <h3 style="margin-bottom: 12px;">Agende a Tattoo</h3>
                <p style="font-size: 14px; color: var(--text-secondary);">Com o crédito liberado, selecione seu tatuador favorito, escolha dia e horário e faça sua arte!</p>
            </div>
        </div>
    </section>

    <!-- Seção de Planos -->
    <section id="planos" style="background: var(--bg-secondary); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);">
        <div class="planos-container">
            <div class="section-title">
                <h2>Escolha seu Plano de Consórcio</h2>
                <p>Todo o valor pago mensalmente é convertido em crédito para a sua tatuagem.</p>
            </div>
            
            <div class="plans-grid">
                <!-- Plano Bronze -->
                <div class="glass-card plan-card bronze">
                    <h3 style="font-size: 1.3rem;">Bronze</h3>
                    <p style="font-size: 12px; color: var(--text-muted); text-transform: uppercase;">Para tatuagens pequenas / fineline</p>
                    <div class="price">
                        R$ 60 <span>/mês</span>
                    </div>
                    <ul>
                        <li><i class="fa-solid fa-circle-check"></i> R$ 600 em crédito de tatuagem</li>
                        <li><i class="fa-solid fa-circle-check"></i> Prazo máximo de 10 meses</li>
                        <li><i class="fa-solid fa-circle-check"></i> Sorteio mensal no grupo</li>
                        <li><i class="fa-solid fa-circle-check"></i> Opção de lances mensais</li>
                    </ul>
                    <button onclick="openRegisterModalWithPlan('Bronze')" class="btn btn-outline btn-block">Quero este Plano</button>
                </div>
                
                <!-- Plano Prata -->
                <div class="glass-card plan-card prata" style="border-color: rgba(255, 255, 255, 0.15);">
                    <div style="position: absolute; top: 15px; right: 15px; background: var(--accent-purple); color: white; font-size: 10px; font-weight: 700; padding: 4px 10px; border-radius: 30px; text-transform: uppercase;">Mais Popular</div>
                    <h3 style="font-size: 1.3rem;">Prata</h3>
                    <p style="font-size: 12px; color: var(--text-muted); text-transform: uppercase;">Para tatuagens médias / realismo</p>
                    <div class="price">
                        R$ 120 <span>/mês</span>
                    </div>
                    <ul>
                        <li><i class="fa-solid fa-circle-check"></i> R$ 1.200 em crédito de tatuagem</li>
                        <li><i class="fa-solid fa-circle-check"></i> Prazo máximo de 10 meses</li>
                        <li><i class="fa-solid fa-circle-check"></i> Sorteio mensal no grupo</li>
                        <li><i class="fa-solid fa-circle-check"></i> Opção de lances mensais</li>
                    </ul>
                    <button onclick="openRegisterModalWithPlan('Prata')" class="btn btn-purple btn-block">Quero este Plano</button>
                </div>
                
                <!-- Plano Ouro -->
                <div class="glass-card plan-card ouro">
                    <h3 style="font-size: 1.3rem;">Ouro</h3>
                    <p style="font-size: 12px; color: var(--text-muted); text-transform: uppercase;">Para fechamentos / projetos grandes</p>
                    <div class="price">
                        R$ 250 <span>/mês</span>
                    </div>
                    <ul>
                        <li><i class="fa-solid fa-circle-check"></i> R$ 2.500 em crédito de tatuagem</li>
                        <li><i class="fa-solid fa-circle-check"></i> Prazo máximo de 10 meses</li>
                        <li><i class="fa-solid fa-circle-check"></i> Sorteio mensal no grupo</li>
                        <li><i class="fa-solid fa-circle-check"></i> Opção de lances mensais</li>
                    </ul>
                    <button onclick="openRegisterModalWithPlan('Ouro')" class="btn btn-gold btn-block">Quero este Plano</button>
                </div>
            </div>
        </div>
    </section>

    <!-- Seção Tatuadores -->
    <section id="tatuadores" style="padding: 80px 20px; max-width: 1200px; margin: 0 auto;">
        <div class="section-title">
            <h2>Nosso Artista</h2>
            <p>Conheça o artista responsável pelo estúdio e por transformar suas ideias em arte na pele.</p>
        </div>
        
        <div style="max-width: 500px; margin: 40px auto 0; text-align: center;">
            <div class="glass-card" style="padding: 30px; border-color: var(--border-highlight);">
                <div style="width: 150px; height: 150px; border-radius: 50%; overflow: hidden; margin: 0 auto 20px; border: 3px solid var(--accent-gold); display: flex; align-items: center; justify-content: center; background: #222;">
                    <i class="fa-solid fa-user-ninja" style="font-size: 60px; color: var(--accent-gold);"></i>
                </div>
                <h3 style="font-size: 1.8rem; margin-bottom: 5px;">Biel Tattoo</h3>
                <p style="color: var(--accent-gold); font-size: 14px; font-weight: 700; margin-bottom: 15px;">
                    <a href="https://instagram.com/bielt_attoo" target="_blank" style="color: var(--accent-gold); display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-brands fa-instagram"></i> @bielt_attoo
                    </a>
                </p>
                <p style="color: var(--accent-gold); font-size: 13px; font-weight: 600; margin-bottom: 12px; text-transform: uppercase;">Fineline, Blackwork & Criações Customizadas</p>
                <p style="font-size: 14px; color: var(--text-secondary); line-height: 1.6;">
                    Especialista em traços finos e delicados, desenhos geométricos e projetos de Blackwork personalizados. Siga no Instagram para conferir o portfólio completo de trabalhos!
                </p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer style="background: #050508; border-top: 1px solid var(--border-color); padding: 50px 20px; text-align: center;">
        <div style="max-width: 1200px; margin: 0 auto;">
            <div style="display: flex; justify-content: center; align-items: center; gap: 10px; margin-bottom: 20px; font-weight: 800;">
                <i class="fa-solid fa-yin-yang" style="color: var(--accent-gold); font-size: 1.4rem;"></i>
                <span style="font-family: 'Outfit', sans-serif;">INK <span style="color: var(--accent-gold);">CONSÓRCIO</span></span>
            </div>
            <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 15px;">&copy; 2026 <?php echo STUDIO_NOME; ?>. Todos os direitos reservados.</p>
            <p style="color: var(--text-muted); font-size: 11px;">Av. da Arte, 1000 - Centro, São Paulo/SP | Desenvolvido com foco na melhor experiência de agendamento.</p>
        </div>
    </footer>

    <!-- MODAL LOGIN -->
    <div id="loginModal" class="modal">
        <div class="glass-card modal-content">
            <span class="modal-close" onclick="closeModal('loginModal')">&times;</span>
            <h2 style="margin-bottom: 10px; font-size: 1.6rem;"><i class="fa-solid fa-right-to-bracket" style="color: var(--accent-gold);"></i> Login do Cliente</h2>
            <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">Acesse sua carteira de consórcio e faça agendamentos.</p>
            
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label>E-mail</label>
                    <input type="email" name="email" class="form-control" placeholder="exemplo@email.com" required>
                </div>
                
                <div class="form-group" style="margin-bottom: 25px;">
                    <label>Senha</label>
                    <input type="password" name="senha" class="form-control" placeholder="Sua senha" required>
                </div>
                
                <button type="submit" class="btn btn-gold btn-block">Entrar</button>
            </form>
            <p style="text-align: center; margin-top: 20px; font-size: 13px; color: var(--text-secondary);">
                Não tem conta? <a href="javascript:void(0)" onclick="switchModal('loginModal', 'registerModal')" style="color: var(--accent-gold); font-weight: 600;">Cadastre-se</a>
            </p>
        </div>
    </div>

    <!-- MODAL CADASTRO -->
    <div id="registerModal" class="modal">
        <div class="glass-card modal-content" style="max-width: 550px;">
            <span class="modal-close" onclick="closeModal('registerModal')">&times;</span>
            <h2 style="margin-bottom: 5px; font-size: 1.6rem;"><i class="fa-solid fa-user-plus" style="color: var(--accent-gold);"></i> Criar sua Conta</h2>
            <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">Entre no consórcio de tatuagem e garanta seu agendamento.</p>
            
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="register">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Nome Completo</label>
                        <input type="text" name="nome" class="form-control" placeholder="João Silva" required>
                    </div>
                    
                    <div class="form-group">
                        <label>E-mail</label>
                        <input type="email" name="email" class="form-control" placeholder="joao@email.com" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Telefone / WhatsApp</label>
                        <input type="text" name="telefone" class="form-control" placeholder="(11) 99999-9999" required>
                    </div>
                    
                    <div class="form-group">
                        <label>CPF</label>
                        <input type="text" name="cpf" class="form-control" placeholder="123.456.789-00" required>
                    </div>
                </div>
                
                <div class="form-row" style="margin-bottom: 20px;">
                    <div class="form-group">
                        <label>Senha</label>
                        <input type="password" name="senha" class="form-control" placeholder="Senha segura" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Selecione seu Plano</label>
                        <select name="plano" id="registerPlano" class="form-control" required style="background-color: var(--bg-secondary);">
                            <option value="Bronze">Bronze (R$ 60/mês)</option>
                            <option value="Prata">Prata (R$ 120/mês)</option>
                            <option value="Ouro">Ouro (R$ 250/mês)</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-gold btn-block">Confirmar e Entrar</button>
            </form>
            <p style="text-align: center; margin-top: 15px; font-size: 13px; color: var(--text-secondary);">
                Já tem conta? <a href="javascript:void(0)" onclick="switchModal('registerModal', 'loginModal')" style="color: var(--accent-gold); font-weight: 600;">Acesse aqui</a>
            </p>
        </div>
    </div>

    <!-- Script para abrir/fechar modais e menu mobile -->
    <script>
        // Controle de Menu Mobile
        const menuToggle = document.getElementById('menu-toggle');
        const navMenu = document.getElementById('nav-menu');

        if (menuToggle && navMenu) {
            menuToggle.addEventListener('click', () => {
                navMenu.classList.toggle('active');
                // Troca ícone
                const icon = menuToggle.querySelector('i');
                if (navMenu.classList.contains('active')) {
                    icon.className = 'fa-solid fa-xmark';
                } else {
                    icon.className = 'fa-solid fa-bars';
                }
            });
        }

        function closeMobileMenu() {
            if (navMenu && navMenu.classList.contains('active')) {
                navMenu.classList.remove('active');
                const icon = menuToggle.querySelector('i');
                if (icon) icon.className = 'fa-solid fa-bars';
            }
        }

        function openModal(id) {
            const modal = document.getElementById(id);
            modal.style.display = 'flex';
            setTimeout(() => {
                modal.classList.add('active');
            }, 10);
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            modal.classList.remove('active');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }

        function switchModal(closeId, openId) {
            closeModal(closeId);
            setTimeout(() => {
                openModal(openId);
            }, 300);
        }

        function openRegisterModalWithPlan(planName) {
            document.getElementById('registerPlano').value = planName;
            openModal('registerModal');
        }

        // Fechar modais ao clicar fora do conteúdo
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal(event.target.id);
            }
        }
    </script>

</body>
</html>

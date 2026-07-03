<?php
require_once __DIR__ . '/config.php';

try {
    // 1. Conexão inicial com o MySQL (sem selecionar banco de dados) para criá-lo se não existir
    $pdo_init = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $pdo_init->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    die("<div style='background:#ff4a4a;color:#fff;padding:20px;font-family:sans-serif;border-radius:8px;margin:20px;text-align:center;'>
            <h2>Erro de Conexão com o Banco de Dados</h2>
            <p>Não foi possível conectar ao MySQL. Certifique-se de que o <strong>Apache</strong> e o <strong>MySQL</strong> estão ativos no seu Painel do XAMPP!</p>
            <p style='font-size:12px;opacity:0.8;'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>
         </div>");
}

try {
    // 2. Conectar ao banco de dados criado
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 3. Criar Tabela de Usuários (Clientes e Admins)
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        senha VARCHAR(255) NOT NULL,
        telefone VARCHAR(20) NOT NULL,
        cpf VARCHAR(20) NOT NULL,
        perfil ENUM('cliente', 'admin') DEFAULT 'cliente',
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");

    // 4. Criar Tabela de Planos Ativos dos Usuários
    $pdo->exec("CREATE TABLE IF NOT EXISTS planos_usuario (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        plano_nome VARCHAR(50) NOT NULL,
        valor_mensal DECIMAL(10, 2) NOT NULL,
        valor_total DECIMAL(10, 2) NOT NULL,
        status ENUM('ativo', 'contemplado', 'quitado') DEFAULT 'ativo',
        parcelas_pagas INT DEFAULT 0,
        metodo_contemplacao ENUM('sorteio', 'lance', 'quitacao', 'nenhum') DEFAULT 'nenhum',
        data_adesao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        data_contemplacao DATETIME DEFAULT NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // 5. Criar Tabela de Pagamentos
    $pdo->exec("CREATE TABLE IF NOT EXISTS pagamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        plano_usuario_id INT NOT NULL,
        parcela_numero INT NOT NULL,
        valor DECIMAL(10, 2) NOT NULL,
        status ENUM('pendente', 'pago') DEFAULT 'pendente',
        mercado_pago_id VARCHAR(100) DEFAULT NULL,
        metodo_pagamento VARCHAR(50) DEFAULT NULL,
        data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        data_pagamento DATETIME DEFAULT NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        FOREIGN KEY (plano_usuario_id) REFERENCES planos_usuario(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // 6. Criar Tabela de Lances
    $pdo->exec("CREATE TABLE IF NOT EXISTS lances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        plano_usuario_id INT NOT NULL,
        valor_lance DECIMAL(10, 2) NOT NULL,
        parcelas_pagas INT NOT NULL, -- Quantidade de parcelas que o lance quita
        status ENUM('pendente', 'aprovado', 'recusado') DEFAULT 'pendente',
        data_lance TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        FOREIGN KEY (plano_usuario_id) REFERENCES planos_usuario(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // 7. Criar Tabela de Tatuadores
    $pdo->exec("CREATE TABLE IF NOT EXISTS tatuadores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        especialidade VARCHAR(100) NOT NULL,
        avatar VARCHAR(255) DEFAULT NULL
    ) ENGINE=InnoDB;");

    // 8. Criar Tabela de Agendamentos
    $pdo->exec("CREATE TABLE IF NOT EXISTS agendamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        tatuador_id INT NOT NULL,
        data_agendamento DATE NOT NULL,
        horario TIME NOT NULL,
        descricao TEXT,
        imagem_referencia VARCHAR(255) DEFAULT NULL,
        status ENUM('agendado', 'concluido', 'cancelado') DEFAULT 'agendado',
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        FOREIGN KEY (tatuador_id) REFERENCES tatuadores(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // Inserir ou atualizar o Tatuador (Biel Tattoo) sem apagar os agendamentos existentes
    // IMPORTANTE: Não usar DELETE aqui pois ON DELETE CASCADE apagaria todos os agendamentos!
    $pdo->exec("INSERT INTO tatuadores (id, nome, especialidade, avatar) 
                VALUES (1, 'Biel Tattoo (@bielt_attoo)', 'Fineline, Blackwork & Criações Customizadas', 'biel.jpg')
                ON DUPLICATE KEY UPDATE 
                    nome = VALUES(nome), 
                    especialidade = VALUES(especialidade), 
                    avatar = VALUES(avatar)");


    // Inserir Usuário Administrador se não existir
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $admin_email = 'admin@inkstudio.com';
        $admin_senha = password_hash('admin123', PASSWORD_DEFAULT);
        
        $insert_admin = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, telefone, cpf, perfil) VALUES (:nome, :email, :senha, :telefone, :cpf, :perfil)");
        $insert_admin->execute([
            'nome' => 'Administrador Ink Studio',
            'email' => $admin_email,
            'senha' => $admin_senha,
            'telefone' => '(11) 98888-8888',
            'cpf' => '000.000.000-00',
            'perfil' => 'admin'
        ]);
    }

} catch (PDOException $e) {
    die("Erro ao inicializar tabelas: " . $e->getMessage());
}
?>

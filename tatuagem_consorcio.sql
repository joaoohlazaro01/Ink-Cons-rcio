-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 16/07/2026 às 22:10
-- Versão do servidor: 10.4.32-MariaDB
-- Versão do PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `tatuagem_consorcio`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `agendamentos`
--

CREATE TABLE `agendamentos` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `tatuador_id` int(11) NOT NULL,
  `data_agendamento` date NOT NULL,
  `horario` time NOT NULL,
  `descricao` text DEFAULT NULL,
  `imagem_referencia` varchar(255) DEFAULT NULL,
  `status` enum('agendado','concluido','cancelado') DEFAULT 'agendado',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `agendamentos`
--

INSERT INTO `agendamentos` (`id`, `usuario_id`, `tatuador_id`, `data_agendamento`, `horario`, `descricao`, `imagem_referencia`, `status`, `criado_em`) VALUES
(3, 2, 1, '2026-07-04', '13:30:00', 'lobo na perna', '53d60739b0ec16b6805bf0996ac208ed.png', 'agendado', '2026-06-28 19:30:34');

-- --------------------------------------------------------

--
-- Estrutura para tabela `lances`
--

CREATE TABLE `lances` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `plano_usuario_id` int(11) NOT NULL,
  `valor_lance` decimal(10,2) NOT NULL,
  `parcelas_pagas` int(11) NOT NULL,
  `status` enum('pendente','aprovado','recusado') DEFAULT 'pendente',
  `data_lance` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `lances`
--

INSERT INTO `lances` (`id`, `usuario_id`, `plano_usuario_id`, `valor_lance`, `parcelas_pagas`, `status`, `data_lance`) VALUES
(1, 2, 1, 60.00, 1, 'recusado', '2026-06-28 19:13:10'),
(2, 4, 3, 240.00, 4, 'pendente', '2026-07-10 22:38:55');

-- --------------------------------------------------------

--
-- Estrutura para tabela `pagamentos`
--

CREATE TABLE `pagamentos` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `plano_usuario_id` int(11) NOT NULL,
  `parcela_numero` int(11) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `status` enum('pendente','pago') DEFAULT 'pendente',
  `mercado_pago_id` varchar(100) DEFAULT NULL,
  `metodo_pagamento` varchar(50) DEFAULT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  `data_pagamento` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `pagamentos`
--

INSERT INTO `pagamentos` (`id`, `usuario_id`, `plano_usuario_id`, `parcela_numero`, `valor`, `status`, `mercado_pago_id`, `metodo_pagamento`, `data_criacao`, `data_pagamento`) VALUES
(1, 2, 1, 1, 60.00, 'pago', '1', 'Pix / Cartão (Mercado Pago)', '2026-06-28 19:12:20', '2026-06-28 16:12:36'),
(2, 2, 1, 2, 60.00, 'pendente', NULL, NULL, '2026-06-28 19:12:36', NULL),
(3, 3, 2, 1, 60.00, 'pago', '3', 'Pix / Cartão (Mercado Pago)', '2026-07-03 20:22:02', '2026-07-03 17:25:49'),
(4, 3, 2, 2, 60.00, 'pendente', NULL, NULL, '2026-07-03 20:25:49', NULL),
(5, 4, 3, 1, 60.00, 'pago', '5', 'Pix / Cartão (Mercado Pago)', '2026-07-10 22:38:35', '2026-07-10 19:39:04'),
(6, 4, 3, 2, 60.00, 'pendente', NULL, NULL, '2026-07-10 22:39:04', NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `planos_usuario`
--

CREATE TABLE `planos_usuario` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `plano_nome` varchar(50) NOT NULL,
  `valor_mensal` decimal(10,2) NOT NULL,
  `valor_total` decimal(10,2) NOT NULL,
  `status` enum('ativo','contemplado','quitado') DEFAULT 'ativo',
  `parcelas_pagas` int(11) DEFAULT 0,
  `metodo_contemplacao` enum('sorteio','lance','quitacao','nenhum') DEFAULT 'nenhum',
  `data_adesao` timestamp NOT NULL DEFAULT current_timestamp(),
  `data_contemplacao` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `planos_usuario`
--

INSERT INTO `planos_usuario` (`id`, `usuario_id`, `plano_nome`, `valor_mensal`, `valor_total`, `status`, `parcelas_pagas`, `metodo_contemplacao`, `data_adesao`, `data_contemplacao`) VALUES
(1, 2, 'Bronze', 60.00, 600.00, 'contemplado', 1, 'sorteio', '2026-06-28 19:12:20', '2026-06-28 16:15:14'),
(2, 3, 'Bronze', 60.00, 600.00, 'ativo', 1, 'nenhum', '2026-07-03 20:22:02', NULL),
(3, 4, 'Bronze', 60.00, 600.00, 'contemplado', 1, 'sorteio', '2026-07-10 22:38:35', '2026-07-10 19:39:35');

-- --------------------------------------------------------

--
-- Estrutura para tabela `tatuadores`
--

CREATE TABLE `tatuadores` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `especialidade` varchar(100) NOT NULL,
  `avatar` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `tatuadores`
--

INSERT INTO `tatuadores` (`id`, `nome`, `especialidade`, `avatar`) VALUES
(1, 'Biel Tattoo (@bielt_attoo)', 'Fineline, Blackwork & Criações Customizadas', 'biel.jpg');

-- --------------------------------------------------------

--
-- Estrutura para tabela `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `telefone` varchar(20) NOT NULL,
  `cpf` varchar(20) NOT NULL,
  `perfil` enum('cliente','admin') DEFAULT 'cliente',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `usuarios`
--

INSERT INTO `usuarios` (`id`, `nome`, `email`, `senha`, `telefone`, `cpf`, `perfil`, `criado_em`) VALUES
(1, 'Administrador Ink Studio', 'admin@inkstudio.com', '$2y$10$IJJJ509RVWVPszty0S3aWuwVVhZKvSVDrGI/58Uwa96tNa57A1P/K', '(11) 98888-8888', '000.000.000-00', 'admin', '2026-06-28 19:03:41'),
(2, 'João Lázaro Tavares Vieira', 'joaolazarotavaresvieira@gmail.com', '$2y$10$yrt71B1BxGOY.ctHpFtbV.6kh3D/7RRQmj1W0sGt4PMCf5Bl4Oxny', '19971335737', '48082359862', 'cliente', '2026-06-28 19:12:20'),
(3, 'Teste', 'fatec@gmail.com', '$2y$10$HrI37eZ32GUoTnIV.hpVZ.gyIdn6THHyYmdCX/.ma9kBcdxmBkg7W', '19971335735', '48082359865', 'cliente', '2026-07-03 20:22:02'),
(4, 'Testando', 'joao.vieira71@fatec.sp.gov.br', '$2y$10$rZM9s3/rBQ0TqaiW41HdvODZ3oTpCb.CR7G1tyvMtwbBg.87rG1n2', '19971335737', '48082359862', 'cliente', '2026-07-10 22:38:35');

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `agendamentos`
--
ALTER TABLE `agendamentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `tatuador_id` (`tatuador_id`);

--
-- Índices de tabela `lances`
--
ALTER TABLE `lances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `plano_usuario_id` (`plano_usuario_id`);

--
-- Índices de tabela `pagamentos`
--
ALTER TABLE `pagamentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `plano_usuario_id` (`plano_usuario_id`);

--
-- Índices de tabela `planos_usuario`
--
ALTER TABLE `planos_usuario`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Índices de tabela `tatuadores`
--
ALTER TABLE `tatuadores`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `agendamentos`
--
ALTER TABLE `agendamentos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `lances`
--
ALTER TABLE `lances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `pagamentos`
--
ALTER TABLE `pagamentos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de tabela `planos_usuario`
--
ALTER TABLE `planos_usuario`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `tatuadores`
--
ALTER TABLE `tatuadores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `agendamentos`
--
ALTER TABLE `agendamentos`
  ADD CONSTRAINT `agendamentos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `agendamentos_ibfk_2` FOREIGN KEY (`tatuador_id`) REFERENCES `tatuadores` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `lances`
--
ALTER TABLE `lances`
  ADD CONSTRAINT `lances_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lances_ibfk_2` FOREIGN KEY (`plano_usuario_id`) REFERENCES `planos_usuario` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `pagamentos`
--
ALTER TABLE `pagamentos`
  ADD CONSTRAINT `pagamentos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pagamentos_ibfk_2` FOREIGN KEY (`plano_usuario_id`) REFERENCES `planos_usuario` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `planos_usuario`
--
ALTER TABLE `planos_usuario`
  ADD CONSTRAINT `planos_usuario_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

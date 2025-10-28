-- Tabela para armazenar logs de webhooks recebidos
-- Útil para debug e auditoria de notificações
CREATE TABLE IF NOT EXISTS `webhook_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `origem` VARCHAR(100) DEFAULT NULL COMMENT 'Origem do webhook (mercadopago, pagseguro, etc)',
  `body` LONGTEXT NOT NULL COMMENT 'Corpo completo da requisição em formato string',
  `ip_origem` VARCHAR(45) DEFAULT NULL COMMENT 'IP de origem da requisição',
  `user_agent` VARCHAR(500) DEFAULT NULL COMMENT 'User agent da requisição',
  `headers` TEXT DEFAULT NULL COMMENT 'Headers da requisição em JSON',
  `method` VARCHAR(10) DEFAULT 'POST' COMMENT 'Método HTTP usado',
  `url` VARCHAR(500) DEFAULT NULL COMMENT 'URL do webhook chamado',
  `dthr_registro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data e hora de criação do registro',
  PRIMARY KEY (`id`),
  KEY `idx_origem` (`origem`),
  KEY `idx_dthr_registro` (`dthr_registro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Logs de webhooks recebidos no sistema';

-- Índice para buscar por origem e data
CREATE INDEX `idx_origem_data` ON `webhook_logs` (`origem`, `dthr_registro`);

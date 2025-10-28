-- Tabela para armazenar transações de pagamento do Mercado Pago (PIX)
-- Esta tabela registra todos os pagamentos gerados e seus status
CREATE TABLE IF NOT EXISTS `mercado_pago_pagamentos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_conta` INT(11) NOT NULL COMMENT 'Referência para a conta do usuário',
  `payment_id` VARCHAR(100) NOT NULL COMMENT 'ID do pagamento no Mercado Pago',
  `status` VARCHAR(50) NOT NULL COMMENT 'Status do pagamento (pending, approved, rejected, etc)',
  `valor` DECIMAL(15,2) NOT NULL COMMENT 'Valor do pagamento',
  `qr_code` TEXT DEFAULT NULL COMMENT 'Código PIX para pagamento',
  `qr_code_base64` LONGTEXT DEFAULT NULL COMMENT 'QR Code em base64 para exibição',
  `ticket_url` VARCHAR(500) DEFAULT NULL COMMENT 'URL do ticket de pagamento',
  `payment_data` LONGTEXT DEFAULT NULL COMMENT 'Dados completos do pagamento em JSON',
  `dthr_registro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data e hora de criação do registro',
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_id` (`payment_id`),
  KEY `idx_id_conta` (`id_conta`),
  KEY `idx_status` (`status`),
  KEY `idx_dthr_registro` (`dthr_registro`),
  CONSTRAINT `fk_mercadopago_conta` FOREIGN KEY (`id_conta`) 
    REFERENCES `contas_usuarios` (`id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Transações de pagamento PIX via Mercado Pago';

-- Índice composto para consultas por conta e status
CREATE INDEX `idx_conta_status` ON `mercado_pago_pagamentos` (`id_conta`, `status`);

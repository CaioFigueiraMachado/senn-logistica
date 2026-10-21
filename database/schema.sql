-- SENN AI-X Logística - esquema do banco (MySQL/MariaDB)
CREATE DATABASE IF NOT EXISTS senn_logistica CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE senn_logistica;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  perfil ENUM('admin','gestor','despachante','motorista') NOT NULL,
  consentimento_lgpd TINYINT(1) NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS veiculos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  placa VARCHAR(10) NOT NULL UNIQUE,
  tipo ENUM('moto','carro','van','caminhao') NOT NULL,
  capacidade_kg DECIMAL(10,2) NOT NULL,
  capacidade_m3 DECIMAL(10,2) NOT NULL,
  autonomia_km DECIMAL(10,2) NOT NULL,
  custo_km DECIMAL(10,2) NOT NULL DEFAULT 1.50,
  status ENUM('disponivel','em_rota','manutencao') NOT NULL DEFAULT 'disponivel',
  proxima_manutencao DATE NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  posicao_em DATETIME NULL
);

CREATE TABLE IF NOT EXISTS motoristas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NULL,
  nome VARCHAR(120) NOT NULL,
  cnh_categoria VARCHAR(3) NOT NULL,
  cnh_validade DATE NOT NULL,
  telefone VARCHAR(20) NOT NULL,
  disponivel TINYINT(1) NOT NULL DEFAULT 1,
  veiculo_id INT NULL,
  consentimento_lgpd TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  FOREIGN KEY (veiculo_id) REFERENCES veiculos(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS rotas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  data_rota DATE NOT NULL,
  veiculo_id INT NOT NULL,
  motorista_id INT NULL,
  distancia_km DECIMAL(10,2) NOT NULL DEFAULT 0,
  tempo_min INT NOT NULL DEFAULT 0,
  custo DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('planejada','liberada','em_andamento','concluida') NOT NULL DEFAULT 'planejada',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (veiculo_id) REFERENCES veiculos(id),
  FOREIGN KEY (motorista_id) REFERENCES motoristas(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS pedidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(30) NOT NULL UNIQUE,
  destinatario VARCHAR(120) NOT NULL,
  endereco VARCHAR(255) NOT NULL,
  regiao VARCHAR(60) NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  janela_inicio DATETIME NOT NULL,
  janela_fim DATETIME NOT NULL,
  peso_kg DECIMAL(10,2) NOT NULL,
  volume_m3 DECIMAL(10,3) NOT NULL,
  prioridade ENUM('baixa','media','alta') NOT NULL DEFAULT 'media',
  status ENUM('pendente','roteirizado','a_caminho','entregue','falha') NOT NULL DEFAULT 'pendente',
  rota_id INT NULL,
  ordem_rota INT NULL,
  risco_atraso DECIMAL(5,2) NULL,
  entregue_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rota_id) REFERENCES rotas(id) ON DELETE SET NULL,
  INDEX idx_status (status),
  INDEX idx_regiao (regiao),
  INDEX idx_janela (janela_inicio)
);

CREATE TABLE IF NOT EXISTS comprovantes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT NOT NULL,
  motorista_id INT NULL,
  tipo ENUM('foto','codigo','assinatura') NOT NULL,
  evidencia TEXT NOT NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  registrado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  FOREIGN KEY (motorista_id) REFERENCES motoristas(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS alertas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('atraso','veiculo_parado','fora_rota','manutencao','cnh','risco') NOT NULL,
  mensagem VARCHAR(255) NOT NULL,
  referencia VARCHAR(60) NULL,
  resolvido TINYINT(1) NOT NULL DEFAULT 0,
  email_enviado TINYINT(1) NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolvido_em DATETIME NULL,
  INDEX idx_alerta (tipo, referencia, resolvido)
);

CREATE TABLE IF NOT EXISTS logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NULL,
  acao VARCHAR(80) NOT NULL,
  detalhe VARCHAR(255) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

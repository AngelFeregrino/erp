-- =========================================
-- 1. Usuarios
-- =========================================
CREATE TABLE usuarios (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(50)  NOT NULL UNIQUE,
  password     VARCHAR(255) NOT NULL, -- contraseña en hash
  role         ENUM('admin','operador') NOT NULL,
  nombre       VARCHAR(100),
  creado_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================
-- 2. Catálogo de Prensas
-- =========================================
CREATE TABLE prensas (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(50) NOT NULL UNIQUE,
  descripcion  TEXT,
  creado_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================
-- 3. Catálogo de Piezas
-- =========================================
CREATE TABLE piezas (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  codigo       VARCHAR(50) NOT NULL UNIQUE, -- No. de parte SINTERQ
  nombre       VARCHAR(100) NOT NULL,
  tipo         VARCHAR(50),
  descripcion  TEXT,
  creado_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================
-- 4. Atributos dinámicos de piezas (modelo EAV)
-- =========================================
CREATE TABLE atributos_pieza (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  pieza_id          INT NOT NULL,
  nombre_atributo   VARCHAR(50) NOT NULL,
  unidad            VARCHAR(20),
  FOREIGN KEY (pieza_id) REFERENCES piezas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================
-- 5. Órdenes de producción (Admin)
-- =========================================
CREATE TABLE ordenes_produccion (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  numero_orden         VARCHAR(20) NOT NULL,
  pieza_id             INT NOT NULL,
  numero_lote          VARCHAR(50),
  cantidad_total_lote  INT, -- se calcula sumando capturas_hora.cantidad
  prensa_id            INT,
  operador_asignado    VARCHAR(100),
  equipo_asignado      VARCHAR(100),
  fecha_liberacion     DATE,
  firma_responsable    VARCHAR(100),
  fecha_inicio         DATE NOT NULL,
  fecha_cierre         DATE,
  estado               ENUM('abierta','cerrada') DEFAULT 'abierta',
  admin_id             INT NOT NULL,
  FOREIGN KEY (pieza_id) REFERENCES piezas(id),
  FOREIGN KEY (prensa_id) REFERENCES prensas(id),
  FOREIGN KEY (admin_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- =========================================
-- 6. Prensas habilitadas por día (Admin)
-- =========================================
CREATE TABLE prensas_habilitadas (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  orden_id    INT NOT NULL,
  fecha       DATE NOT NULL,
  prensa_id   INT NOT NULL,
  pieza_id    INT NOT NULL,
  habilitado  TINYINT(1) DEFAULT 1,
  FOREIGN KEY (orden_id) REFERENCES ordenes_produccion(id) ON DELETE CASCADE,
  FOREIGN KEY (prensa_id) REFERENCES prensas(id) ON DELETE RESTRICT,
  FOREIGN KEY (pieza_id) REFERENCES piezas(id) ON DELETE RESTRICT,
  UNIQUE KEY ux_dia_prensa (orden_id, fecha, prensa_id)
) ENGINE=InnoDB;

-- =========================================
-- 7. Capturas por hora (Operador)
-- =========================================
CREATE TABLE capturas_hora (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  orden_id          INT NOT NULL,
  fecha             DATE NOT NULL,
  prensa_id         INT NOT NULL,
  pieza_id          INT NOT NULL,
  hora_inicio       TIME NOT NULL,
  hora_fin          TIME NOT NULL,
  cantidad          INT,
  observaciones_op  TEXT,
  firma_operador    VARCHAR(100),
  estado            ENUM('pendiente','cerrada') DEFAULT 'pendiente',
  FOREIGN KEY (orden_id) REFERENCES ordenes_produccion(id) ON DELETE CASCADE,
  FOREIGN KEY (prensa_id) REFERENCES prensas(id) ON DELETE RESTRICT,
  FOREIGN KEY (pieza_id) REFERENCES piezas(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =========================================
-- 8. Valores técnicos por hora (EAV)
-- =========================================
CREATE TABLE valores_hora (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  captura_id        INT NOT NULL,
  atributo_pieza_id INT NOT NULL,
  valor             VARCHAR(100),
  FOREIGN KEY (captura_id) REFERENCES capturas_hora(id) ON DELETE CASCADE,
  FOREIGN KEY (atributo_pieza_id) REFERENCES atributos_pieza(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
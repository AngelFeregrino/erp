ALTER TABLE prensas_habilitadas ADD COLUMN turno TINYINT NOT NULL DEFAULT 1;
ALTER TABLE ordenes_produccion ADD COLUMN turno TINYINT NOT NULL DEFAULT 1;
-- índice recomendado si vas a filtrar por fecha+turno 
CREATE INDEX idx_prensas_fecha_turno ON prensas_habilitadas(fecha, turno);
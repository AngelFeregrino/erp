CREATE TABLE rendimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pieza_id INT NOT NULL,
    fecha DATE NOT NULL,
    esperado INT NOT NULL,
    producido INT DEFAULT 0,
    rendimiento DECIMAL(5,2) GENERATED ALWAYS AS (
        CASE 
            WHEN esperado > 0 THEN (producido / esperado) * 100
            ELSE 0
        END
    ) STORED,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pieza_id) REFERENCES piezas(id)
);

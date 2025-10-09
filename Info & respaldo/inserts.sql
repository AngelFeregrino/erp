-- Prensas
INSERT INTO `prensas`(`nombre`, `descripcion`) VALUES ('PO1','prensa 1');
INSERT INTO `prensas`(`nombre`, `descripcion`) VALUES ('PO2','prensa 2');
INSERT INTO `prensas`(`nombre`, `descripcion`) VALUES ('PO3','prensa 3');
INSERT INTO `prensas`(`nombre`, `descripcion`) VALUES ('PO4','prensa 4');
INSERT INTO `prensas`(`nombre`, `descripcion`) VALUES ('PO5','prensa 5');

-- Piezas
INSERT INTO `piezas`(`codigo`, `nombre`, `tipo`, `descripcion`) VALUES ('LIM444','Limiter 444','bronce','circular');
INSERT INTO `piezas`(`codigo`, `nombre`, `tipo`, `descripcion`) VALUES ('OG','Oster grande','oro','cilindro');

-- Atributos para pieza 1
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (1, 'Altura', 'mm');
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (1, 'Diametro Externo', 'mm');
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (1, 'Diametro Interno', 'mm');
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (1, 'Peso', 'gr');
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (1, 'Densidad', 'gr/cm3');

-- Atributos para pieza 2
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (2, 'Altura', 'mm');
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (2, 'Altura Flange', 'mm');
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (2, 'Diametro Externo', 'mm');
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (2, 'Diametro Interno', 'mm');
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (2, 'Diametro Flange', 'mm');
INSERT INTO `atributos_pieza`(`pieza_id`, `nombre_atributo`, `unidad`) VALUES (2, 'Peso', 'gr');
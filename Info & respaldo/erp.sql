-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 11-11-2025 a las 20:04:02
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `erp`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `atributos_pieza`
--

CREATE TABLE `atributos_pieza` (
  `id` int(11) NOT NULL,
  `pieza_id` int(11) NOT NULL,
  `nombre_atributo` varchar(50) NOT NULL,
  `unidad` varchar(20) DEFAULT NULL,
  `valor_predeterminado` decimal(10,2) DEFAULT NULL,
  `tolerancia` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `capturas_hora`
--

CREATE TABLE `capturas_hora` (
  `id` int(11) NOT NULL,
  `orden_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `prensa_id` int(11) NOT NULL,
  `pieza_id` int(11) NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL,
  `cantidad` int(11) DEFAULT NULL,
  `observaciones_op` text DEFAULT NULL,
  `firma_operador` varchar(100) DEFAULT NULL,
  `estado` enum('pendiente','cerrada') DEFAULT 'pendiente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ordenes_produccion`
--

CREATE TABLE `ordenes_produccion` (
  `id` int(11) NOT NULL,
  `numero_orden` varchar(20) NOT NULL,
  `pieza_id` int(11) NOT NULL,
  `numero_lote` varchar(50) DEFAULT NULL,
  `cantidad_total_lote` int(11) DEFAULT NULL,
  `prensa_id` int(11) DEFAULT NULL,
  `operador_asignado` varchar(100) DEFAULT NULL,
  `equipo_asignado` varchar(100) DEFAULT NULL,
  `firma_responsable` varchar(100) DEFAULT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_cierre` date DEFAULT NULL,
  `estado` enum('abierta','cerrada') DEFAULT 'abierta',
  `admin_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `piezas`
--

CREATE TABLE `piezas` (
  `id` int(11) NOT NULL,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `tipo` varchar(50) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `prensas`
--

CREATE TABLE `prensas` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `prensas_habilitadas`
--

CREATE TABLE `prensas_habilitadas` (
  `id` int(11) NOT NULL,
  `orden_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `prensa_id` int(11) NOT NULL,
  `pieza_id` int(11) NOT NULL,
  `habilitado` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `rendimientos`
--

CREATE TABLE `rendimientos` (
  `id` int(11) NOT NULL,
  `pieza_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `esperado` int(11) NOT NULL,
  `producido` int(11) DEFAULT 0,
  `rendimiento` decimal(5,2) GENERATED ALWAYS AS (case when `esperado` > 0 then `producido` / `esperado` * 100 else 0 end) STORED,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `rol` enum('admin','operador') NOT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `valores_hora`
--

CREATE TABLE `valores_hora` (
  `id` int(11) NOT NULL,
  `captura_id` int(11) NOT NULL,
  `atributo_pieza_id` int(11) NOT NULL,
  `valor` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `atributos_pieza`
--
ALTER TABLE `atributos_pieza`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pieza_id` (`pieza_id`);

--
-- Indices de la tabla `capturas_hora`
--
ALTER TABLE `capturas_hora`
  ADD PRIMARY KEY (`id`),
  ADD KEY `orden_id` (`orden_id`),
  ADD KEY `prensa_id` (`prensa_id`),
  ADD KEY `pieza_id` (`pieza_id`);

--
-- Indices de la tabla `ordenes_produccion`
--
ALTER TABLE `ordenes_produccion`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pieza_id` (`pieza_id`),
  ADD KEY `prensa_id` (`prensa_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indices de la tabla `piezas`
--
ALTER TABLE `piezas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`);

--
-- Indices de la tabla `prensas`
--
ALTER TABLE `prensas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `prensas_habilitadas`
--
ALTER TABLE `prensas_habilitadas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_dia_prensa` (`orden_id`,`fecha`,`prensa_id`),
  ADD KEY `prensa_id` (`prensa_id`),
  ADD KEY `pieza_id` (`pieza_id`);

--
-- Indices de la tabla `rendimientos`
--
ALTER TABLE `rendimientos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pieza_id` (`pieza_id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`usuario`);

--
-- Indices de la tabla `valores_hora`
--
ALTER TABLE `valores_hora`
  ADD PRIMARY KEY (`id`),
  ADD KEY `captura_id` (`captura_id`),
  ADD KEY `atributo_pieza_id` (`atributo_pieza_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `atributos_pieza`
--
ALTER TABLE `atributos_pieza`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `capturas_hora`
--
ALTER TABLE `capturas_hora`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ordenes_produccion`
--
ALTER TABLE `ordenes_produccion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `piezas`
--
ALTER TABLE `piezas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `prensas`
--
ALTER TABLE `prensas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `prensas_habilitadas`
--
ALTER TABLE `prensas_habilitadas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `rendimientos`
--
ALTER TABLE `rendimientos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `valores_hora`
--
ALTER TABLE `valores_hora`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `atributos_pieza`
--
ALTER TABLE `atributos_pieza`
  ADD CONSTRAINT `atributos_pieza_ibfk_1` FOREIGN KEY (`pieza_id`) REFERENCES `piezas` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `capturas_hora`
--
ALTER TABLE `capturas_hora`
  ADD CONSTRAINT `capturas_hora_ibfk_1` FOREIGN KEY (`orden_id`) REFERENCES `ordenes_produccion` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `capturas_hora_ibfk_2` FOREIGN KEY (`prensa_id`) REFERENCES `prensas` (`id`),
  ADD CONSTRAINT `capturas_hora_ibfk_3` FOREIGN KEY (`pieza_id`) REFERENCES `piezas` (`id`);

--
-- Filtros para la tabla `ordenes_produccion`
--
ALTER TABLE `ordenes_produccion`
  ADD CONSTRAINT `ordenes_produccion_ibfk_1` FOREIGN KEY (`pieza_id`) REFERENCES `piezas` (`id`),
  ADD CONSTRAINT `ordenes_produccion_ibfk_2` FOREIGN KEY (`prensa_id`) REFERENCES `prensas` (`id`),
  ADD CONSTRAINT `ordenes_produccion_ibfk_3` FOREIGN KEY (`admin_id`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `prensas_habilitadas`
--
ALTER TABLE `prensas_habilitadas`
  ADD CONSTRAINT `prensas_habilitadas_ibfk_1` FOREIGN KEY (`orden_id`) REFERENCES `ordenes_produccion` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `prensas_habilitadas_ibfk_2` FOREIGN KEY (`prensa_id`) REFERENCES `prensas` (`id`),
  ADD CONSTRAINT `prensas_habilitadas_ibfk_3` FOREIGN KEY (`pieza_id`) REFERENCES `piezas` (`id`);

--
-- Filtros para la tabla `rendimientos`
--
ALTER TABLE `rendimientos`
  ADD CONSTRAINT `rendimientos_ibfk_1` FOREIGN KEY (`pieza_id`) REFERENCES `piezas` (`id`);

--
-- Filtros para la tabla `valores_hora`
--
ALTER TABLE `valores_hora`
  ADD CONSTRAINT `valores_hora_ibfk_1` FOREIGN KEY (`captura_id`) REFERENCES `capturas_hora` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `valores_hora_ibfk_2` FOREIGN KEY (`atributo_pieza_id`) REFERENCES `atributos_pieza` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

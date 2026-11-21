-- Base de datos del Sistema de Citas Médicas (ASI Práctica 5, Equipo 7)
-- Ejecutar en srv-bd-dns:  sudo mysql < citas_medicas.sql
-- Antes, cambia <contraseña_segura> por la contraseña real (la misma que pondrán en config.php).

CREATE DATABASE IF NOT EXISTS citas_medicas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'rh_app'@'%' IDENTIFIED BY '<contraseña_segura>';
GRANT ALL PRIVILEGES ON citas_medicas.* TO 'rh_app'@'%';
FLUSH PRIVILEGES;

USE citas_medicas;
CREATE TABLE IF NOT EXISTS citas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  paciente VARCHAR(100) NOT NULL,
  doctor VARCHAR(100) NOT NULL,
  fecha DATE NOT NULL,
  hora TIME NOT NULL,
  estado ENUM('pendiente','confirmada','atendida','cancelada') DEFAULT 'pendiente'
);

-- Datos de prueba para la demo
INSERT INTO citas (paciente, doctor, fecha, hora, estado) VALUES
 ('Ana López',     'Dr. Ramírez', '2026-11-24', '09:00', 'pendiente'),
 ('Luis Martínez', 'Dra. Pérez',  '2026-11-24', '10:30', 'confirmada'),
 ('María Gómez',   'Dr. Ramírez', '2026-11-25', '12:00', 'atendida');

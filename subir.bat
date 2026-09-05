@echo off
chcp 65001 >nul
title Asistente de Subida y Actualizacion a GitHub - WP autocontent
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0subir.ps1"

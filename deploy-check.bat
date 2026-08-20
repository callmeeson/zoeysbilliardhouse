@echo off
title Zoeys Billiard House - Deployment Readiness Check
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy-check.ps1" %*
pause

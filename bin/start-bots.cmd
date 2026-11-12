@echo off
setlocal EnableExtensions DisableDelayedExpansion
title Sissy Fights NXT - 1,000 Playerbot Community
cd /d "%~dp0.."
set "SFNXT_PHP_EXE="
if defined SFNXT_PHP if exist "%SFNXT_PHP%" set "SFNXT_PHP_EXE=%SFNXT_PHP%"
if not defined SFNXT_PHP_EXE if exist "%~dp0..\..\..\php\php.exe" set "SFNXT_PHP_EXE=%~dp0..\..\..\php\php.exe"
if not defined SFNXT_PHP_EXE for %%P in (php.exe) do if not "%%~$PATH:P"=="" set "SFNXT_PHP_EXE=%%~$PATH:P"
if not defined SFNXT_PHP_EXE (
 echo PHP was not found. Put this folder directly under XAMPP\htdocs,
 echo or set the SFNXT_PHP environment variable to your XAMPP php.exe path.
 echo Example: set "SFNXT_PHP=D:\xampp\php\php.exe"
 pause
 exit /b 1
)
"%SFNXT_PHP_EXE%" "%~dp0worker.php" --run
set "SFNXT_EXIT=%ERRORLEVEL%"
echo.
pause
exit /b %SFNXT_EXIT%

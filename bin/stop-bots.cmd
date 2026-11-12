@echo off
setlocal EnableExtensions DisableDelayedExpansion
title Sissy Fights NXT - Stop Community
set "SFNXT_PHP_EXE="
if defined SFNXT_PHP if exist "%SFNXT_PHP%" set "SFNXT_PHP_EXE=%SFNXT_PHP%"
if not defined SFNXT_PHP_EXE if exist "%~dp0..\..\..\php\php.exe" set "SFNXT_PHP_EXE=%~dp0..\..\..\php\php.exe"
if not defined SFNXT_PHP_EXE for %%P in (php.exe) do if not "%%~$PATH:P"=="" set "SFNXT_PHP_EXE=%%~$PATH:P"
if not defined SFNXT_PHP_EXE (
 echo PHP not found. Set SFNXT_PHP to your XAMPP php.exe path.
 pause
 exit /b 1
)
"%SFNXT_PHP_EXE%" "%~dp0worker.php" --stop
set "SFNXT_EXIT=%ERRORLEVEL%"
pause
exit /b %SFNXT_EXIT%

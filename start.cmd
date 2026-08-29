@echo off
TITLE Quark server software for Minecraft: Bedrock Edition
cd /d %~dp0

set PHP_BINARY=

where /q php.exe
if %ERRORLEVEL%==0 (
	set PHP_BINARY=php
)

if exist bin\php\php.exe (
	rem always use the local PHP binary if it exists
	set PHPRC=""
	set PHP_BINARY=bin\php\php.exe
)

if "%PHP_BINARY%"=="" (
	echo Couldn't find a PHP binary in system PATH or "%~dp0bin\php"
	echo Please refer to the installation instructions at https://github.com/Bedrock-Phanatics/Quark#installation
	pause
	exit 1
)

if exist Quark.phar (
	set QUARK_FILE=Quark.phar
) else (
	echo Quark.phar not found
	echo Downloads can be found at https://github.com/Bedrock-Phanatics/Quark/releases
	pause
	exit 1
)

if exist bin\mintty.exe (
	start "" bin\mintty.exe -o Columns=88 -o Rows=32 -o AllowBlinking=0 -o FontQuality=3 -o Font="Consolas" -o FontHeight=10 -o CursorType=0 -o CursorBlinks=1 -h error -t "Quark" -i bin/quark.ico -w max %PHP_BINARY% %QUARK_FILE% --enable-ansi %*
) else (
	REM pause on exitcode != 0 so the user can see what went wrong
	%PHP_BINARY% %QUARK_FILE% %* || pause
)

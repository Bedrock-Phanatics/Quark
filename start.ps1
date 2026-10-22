[CmdletBinding(PositionalBinding=$false)]
param (
	[string]$php = "",
	[switch]$Loop = $false,
	[string]$file = "",
	[string][Parameter(ValueFromRemainingArguments)]$extraQuarkArgs
)

if($php -ne ""){
	$binary = $php
}elseif(Test-Path "bin\php\php.exe"){
	$env:PHPRC = ""
	$binary = "bin\php\php.exe"
	if (!$env:OPENSSL_CONF) { $env:OPENSSL_CONF = [IO.Path]::GetFullPath("bin\php\extras\ssl\openssl.cnf") }
	if (!$env:OPENSSL_MODULES) { $env:OPENSSL_MODULES = [IO.Path]::GetFullPath("bin\php\extras\ssl") }
}elseif((Get-Command php -ErrorAction SilentlyContinue)){
	$binary = "php"
}else{
	echo "Couldn't find a PHP binary in system PATH or $pwd\bin\php"
	echo "Please refer to the installation instructions at https://github.com/Bedrock-Phanatics/Quark#installation"
	pause
	exit 1
}

if($file -eq ""){
	if(Test-Path "Quark.phar"){
	    $file = "Quark.phar"
	}else{
	    echo "Quark.phar not found"
	    echo "Downloads can be found at https://github.com/Bedrock-Phanatics/Quark/releases"
	    pause
	    exit 1
	}
}

function StartServer{
	$command = "powershell -NoProfile " + $binary + " " + $file + " " + $extraQuarkArgs
	iex $command
}

$loops = 0

StartServer

while($Loop){
	if($loops -ne 0){
		echo ("Restarted " + $loops + " times")
	}
	$loops++
	echo "To escape the loop, press CTRL+C now. Otherwise, wait 5 seconds for the server to restart."
	echo ""
	Start-Sleep 5
	StartServer
}

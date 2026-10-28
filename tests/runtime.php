<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if(Bedwire\Protocol::VERSION !== quark\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL){
	throw new RuntimeException('The native and PHP protocol versions do not match');
}
require dirname(__DIR__) . '/build/php/tests/generic/native.php';

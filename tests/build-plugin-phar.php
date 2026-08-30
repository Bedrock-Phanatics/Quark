<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

if($argc !== 3){
	fwrite(STDERR, "Usage: php tests/build-plugin-phar.php <plugin-directory> <output.phar>\n");
	exit(1);
}
$source = realpath($argv[1]);
if($source === false || !is_dir($source)){
	fwrite(STDERR, "Plugin directory does not exist: {$argv[1]}\n");
	exit(1);
}
$output = $argv[2];
$outputDirectory = dirname($output);
if(!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)){
	fwrite(STDERR, "Unable to create output directory: $outputDirectory\n");
	exit(1);
}
if(file_exists($output) && !unlink($output)){
	fwrite(STDERR, "Unable to replace output file: $output\n");
	exit(1);
}
$phar = new Phar($output);
$phar->startBuffering();
$phar->setStub("<?php __HALT_COMPILER();");
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){
	if(!$file->isFile()) continue;
	$path = $file->getPathname();
	$localName = str_replace('\\', '/', substr($path, strlen($source) + 1));
	$phar->addFile($path, $localName);
}
$phar->stopBuffering();
echo "Built $output\n";
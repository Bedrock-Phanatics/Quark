<?php

declare(strict_types=1);

use quark\utils\Filesystem;

require dirname(__DIR__) . '/vendor/autoload.php';

$legacyProperties = "motd=Quark Server\nserver-port=19132\nserver-portv6=19133\nmax-players=20\ngamemode=SURVIVAL\nview-distance=16\nlanguage=eng\n";
$resources = dirname(__DIR__) . '/resources/';
$cases = [
	'legacy' => static function() use ($resources, $legacyProperties) : void{
		yaml_parse(quark\utils\Config::fixYAMLIndexes(Filesystem::fileGetContents($resources . 'quark.yml')));
		quark\utils\Config::parseProperties($legacyProperties);
		$plugins = yaml_parse(Filesystem::fileGetContents($resources . 'plugin_list.yml'));
		if(!is_array($plugins)){
			throw new RuntimeException("Invalid legacy plugin list fixture");
		}
		quark\plugin\PluginGraylist::fromArray($plugins);
	},
	'toml-native' => static function() use ($resources) : void{
		quark\CoreConfig::forget($resources . 'server.toml');
		quark\CoreConfig::validateServer(quark\CoreConfig::load($resources . 'server.toml'));
		quark\CoreConfig::forget($resources . 'plugins.toml');
		quark\CoreConfig::validatePlugins(quark\CoreConfig::load($resources . 'plugins.toml'));
	},
	'toml-php' => static function() use ($resources) : void{
		quark\CoreConfig::validateServer(Internal\Toml\Toml::parseToArray(Filesystem::fileGetContents($resources . 'server.toml')));
		quark\CoreConfig::validatePlugins(Internal\Toml\Toml::parseToArray(Filesystem::fileGetContents($resources . 'plugins.toml')));
	}
];
foreach($cases as $name => $run){
	for($i = 0; $i < 100; ++$i){
		$run();
	}
	$samples = [];
	for($round = 0; $round < 5; ++$round){
		$start = hrtime(true);
		for($i = 0; $i < 1000; ++$i){
			$run();
		}
		$samples[] = (hrtime(true) - $start) / 1000000 / 1000;
	}
	sort($samples);
	printf("%s: %.3f ms/load median; peak %.1f MiB\n", $name, $samples[2], memory_get_peak_usage(true) / 1048576);
}

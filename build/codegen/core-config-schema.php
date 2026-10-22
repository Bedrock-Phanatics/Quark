<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$defaults = quark\CoreConfig::parse(quark\utils\Filesystem::fileGetContents(dirname(__DIR__, 2) . '/resources/server.toml'));
file_put_contents(dirname(__DIR__, 2) . '/generated/CoreConfigSchema.php', "<?php\n\n// Generated from resources/server.toml; run composer update-codegen after editing defaults.\nreturn " . preg_replace('/[ \t]+$/m', '', var_export($defaults, true)) . ";\n");

<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/vendor/autoload.php";

$file = tempnam(sys_get_temp_dir(), "quark-log-bench-");
if($file === false){
	throw new RuntimeException("Cannot create benchmark log file");
}
$writer = new quark\utils\MainLoggerThread($file, null);
$writer->start(pmmp\thread\Thread::INHERIT_NONE);
$line = "2026-01-01 [Server/INFO]: benchmark line\n";
$start = hrtime(true);
for($i = 0; $i < 50000; ++$i){
	$writer->write($line);
}
$writer->syncFlushBuffer();
$elapsed = (hrtime(true) - $start) / 1e9;
$writer->shutdown();
printf("50000 lines: %.3f s, %.0f lines/s, %d bytes\n", $elapsed, 50000 / $elapsed, filesize($file));
unlink($file);

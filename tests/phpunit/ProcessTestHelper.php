<?php

declare(strict_types=1);

namespace quark;

use function fclose;
use function file_get_contents;
use function hrtime;
use function is_resource;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

final class ProcessTestHelper{
	/** @param list<string> $command
	 * @param \Closure(string, string): bool|null $ready
	 * @return array{int, string, string}
	 */
	public static function run(array $command, float $timeout = 10.0, ?\Closure $ready = null) : array{
		$outputPath = tempnam(sys_get_temp_dir(), "quark-stdout-");
		if($outputPath === false){ throw new \RuntimeException("Cannot create subprocess output file"); }
		$errorPath = tempnam(sys_get_temp_dir(), "quark-stderr-");
		if($errorPath === false){ unlink($outputPath); throw new \RuntimeException("Cannot create subprocess error file"); }
		$process = null;
		try{
			// Files keep native stderr capture reliable on Windows
			$process = proc_open($command, [["pipe", "r"], ["file", $outputPath, "wb"], ["file", $errorPath, "wb"]], $pipes);
			if($process === false){ throw new \RuntimeException("Cannot start test subprocess"); }
			$deadline = hrtime(true) + (int) ($timeout * 1e9);
			while(true){
				$status = proc_get_status($process);
				$output = file_get_contents($outputPath);
				$errors = file_get_contents($errorPath);
				if($output === false || $errors === false){ throw new \RuntimeException("Cannot read test subprocess output"); }
				if($ready !== null && $ready($output, $errors)){ return [$status["exitcode"], $output, $errors]; }
				if(!$status["running"]){ return [$status["exitcode"], $output, $errors]; }
				if(hrtime(true) >= $deadline){ throw new \RuntimeException("Test subprocess timed out: " . $errors); }
				usleep(1000);
			}
		}finally{
			if(is_resource($process)){
				if(proc_get_status($process)["running"]){ proc_terminate($process); }
				foreach($pipes as $pipe){ fclose($pipe); }
				proc_close($process);
			}
			unlink($outputPath);
			unlink($errorPath);
		}
	}
}

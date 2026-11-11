<?php

declare(strict_types=1);

$input = @fopen("php://stdin", "r+b");
$output = @fopen("php://stdout", "wb");
$fallback = @fopen("php://stderr", "wb");
if($input === false || $output === false || $fallback === false){ exit(1); }
while(!feof($input)){
	$data = fread($input, 65536);
	if($data === false){ exit(1); }
	$length = strlen($data);
	for($offset = 0; $offset < $length; $offset += $written){
		$written = @fwrite($output, substr($data, $offset));
		if($written === false){
			if($output === $fallback){ exit(1); }
			fclose($output);
			$output = $fallback;
			$written = 0;
			continue;
		}
		if($written === 0){
			$read = $except = null;
			$write = [$output];
			if(@stream_select($read, $write, $except, null) === false){ exit(1); }
			continue;
		}
		$acknowledgement = pack("N", $written);
		for($sent = 0; $sent < 4; $sent += $count){
			$count = @fwrite($input, substr($acknowledgement, $sent));
			if($count === false || $count === 0){ exit(1); }
		}
	}
}
fclose($input);
fclose($output);
if($output !== $fallback){ fclose($fallback); }

<?php

declare(strict_types=1);

$input = fopen("php://stdin", "rb");
$output = fopen("php://stdout", "wb");
if($input === false || $output === false){ exit(1); }
while(!feof($input)){
	$data = fread($input, 65536);
	if($data === false){ exit(1); }
	$length = strlen($data);
	for($offset = 0; $offset < $length; $offset += $written){
		$written = @fwrite($output, substr($data, $offset));
		if($written === false || $written === 0){ exit(1); }
	}
}
fclose($input);
fclose($output);

<?php 
define('MORIARTY_HTTP_CACHE_DIR', 'cache/');
define('MORIARTY_ALWAYS_CACHE_EVERYTHING', 1);
define('MORIARTY_HTTP_CACHE_READ_ONLY', 1);

require 'DataCompiler.php';
$datasetUri = 'http://data.kasabi.com/dataset/digital-city-brighton';
$DataCompiler = new DataCompiler($datasetUri, file_get_contents('data-compilation-void.ttl'));
$DataCompiler->fetchData();

?>

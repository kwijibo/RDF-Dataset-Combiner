<?php 
require 'DataCompiler.php';
define('DIGITAL_BRIGHTON', 'http://data.kasabi.com/dataset/digital-city-brighton');
$DataCompiler = new DataCompiler(DIGITAL_BRIGHTON, file_get_contents('data-compilation-void.ttl'));
$DataCompiler->uploadDataset(DIGITAL_BRIGHTON);

/*
foreach($DataCompiler->getSubsets(DIGITAL_BRIGHTON) as $subsetUri){
  $D = new DataCompiler($subsetUri, file_get_contents('data-compilation-void.ttl'));
  $D->uploadDataset($subsetUri);
}

 */



?>


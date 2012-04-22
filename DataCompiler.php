<?php
define('DP', 'http://keithalexander.co.uk/vocabs/data-puller#');
define('MORIARTY_ARC_DIR', 'arc/');
define('VOID', 'http://rdfs.org/ns/void#');
define('API_KEY', 'xxxxx');
require 'moriarty/simplegraph.class.php';
require 'moriarty/graph.class.php';
define('DCT', 'http://purl.org/dc/terms/');

/*$DataCompiler = new DataCompiler('http://data.kasabi.com/dataset/digitalcity-brighton', file_get_contents('data-compilation-void.ttl'));
$DataCompiler->fetchData();
 */

class KasabiGraph extends Graph {
  function submit_turtle($turtle, $gzip_encode=false){
    return parent::submit_turtle($turtle, $gzip_encode);
  }
}

class DataCompiler {

  var $graph, $uri, $http_factory, $urisFrom, $storeEndpoint, $lastUploaded;

  function __construct($uri, $rdf){
    $this->graph = new SimpleGraph();
    $this->graph->add_rdf($rdf);
    $errors = $this->graph->get_parser_errors();
    if(!empty($errors)){
      throw new Exception("Error parsing RDF:\n" . implode("\n",$errors));
    }
    $this->uri = $uri;
    $this->http_factory = new HttpRequestFactory();
    @$this->urisFrom = json_decode(file_get_contents('urisFrom.json'),1);
    $this->lastUploaded = json_decode(file_get_contents('lastUploaded.json'),1);
    $this->storeEndpoint = new KasabiGraph($uri);
  }

  function __destruct(){
    file_put_contents('urisFrom.json', json_encode($this->urisFrom));
    file_put_contents('lastUploaded.json', json_encode($this->lastUploaded));
  }

  function fetchData(){
    foreach($this->getSubsets($this->uri) as $subset){
      $this->buildDataSet($subset);
    }
  }

  function getSubsets($uri=false){
    if(!$uri) $uri = $this->uri;
    return $this->graph->get_resource_triple_values($uri, VOID.'subset');
  }
  
  function getSourceForDataset($uri){
    if($source = $this->graph->get_first_resource($uri, DCT.'source')){
      return $source;
    } else {
      $parents = $this->graph->get_subjects_where_resource(VOID.'subset', $uri);
      $supersetUri = array_shift($parents);
      if($supersetUri && ($source = $this->getSourceForDataset($supersetUri))){
          return $source;
      }
      return false;
      //throw new Exception("No source could be found for $uri");

    }
  }
  function getSparqlEndpoint($uri){

    $source = $this->getSourceForDataset($uri);
//    var_dump('source', $source);
    if($endpoint = $this->graph->get_first_resource($source, VOID.'sparqlEndpoint')){
      return $endpoint;
    } else {
      $parents = $this->graph->get_subjects_where_resource(VOID.'subset', $uri);
      foreach($parents as $supersetUri){
        $source = $this->getSourceForDataset($supersetUri);
        if($source && $endpoint = $this->graph->get_first_resource($source, VOID.'sparqlEndpoint')){
          return $endpoint;
        }
      }
    }
    $source = $this->getSourceForDataset($uri);
    $slug = array_pop(preg_split('@#|/@',$source));
    if(empty($slug)){
      throw new Exception("can't calculate slug for <$source>, source of <{$uri}>");
    }
    return "http://api.kasabi.com/dataset/{$slug}/apis/sparql";

    throw new Exception("No endpoint found for $uri ");

  }

  function getSubjectsFrom($subsetUri){
    return $this->getUrisFrom($subsetUri, 'subject');
  }

  function getObjectsFrom($subsetUri){
    return $this->getUrisFrom($subsetUri, 'object');
  }


  function getFilenameForSubset($subsetUri){
    $fileName = $this->getDestinationFileName($subsetUri);
    if($dataDump = $this->graph->get_first_resource($subsetUri, VOID.'dataDump')){
      return $dataDump;
    } else if(file_exists($fileName)) {
      return $fileName;
    } else {
      return false;
    }
  }

  function getUrisFrom($subsetUri, $position){
    if(@isset($this->urisFrom[$subsetUri][$position])){
      return $this->urisFrom[$subsetUri][$position];
    }
    echo "\nGetting URIs from $subsetUri";
    $fileName = $this->getFilenameForSubset($subsetUri);
    if(!file_exists($fileName)){
      echo  "\n$subsetUri not a file\n";
      $uris = array();
      $subsets = $this->getSubsets($subsetUri);
      foreach($this->getSubsets($subsetUri) as $subset){
        $uris = array_merge($this->getUrisFrom($subset, $position), $uris);
      }
      
      if(empty($subsets) && empty($uris)){
        echo "\nNo subsets for $subsetUri\n";
        $this->buildDataset($subsetUri);
        return $this->getUrisFrom($subsetUri, $position);
      } else {
        return array_unique($uris);
      }
    }
    echo "\nOpening $fileName";
    $fh = fopen($fileName, 'r');
    $count = 0;
    $uris = array();
    $buffer = '';
    while($line = fgets($fh)){
      $buffer.=$line;
      if($count++ > 100){
        $uris = array_merge($this->getUrisFromBuffer($buffer, $position), $uris);
        $count = 0;
        $buffer='';
      }
    }
    $uris = array_merge($this->getUrisFromBuffer($buffer, $position), $uris);
    $this->urisFrom[$subsetUri][$position] = array_unique($uris);
    return $this->urisFrom[$subsetUri][$position];
  }


  function getUrisFromBuffer($buffer, $position){
      $graph = new SimpleGraph();
      $graph->add_turtle($buffer);
      $uris = array();
        if($position=='subject') {
          $uris = array_merge($graph->get_subjects(), $uris);
        } else if($position=='object'){
          $subjects = $graph->get_subjects();
          foreach($subjects as $s){
            $properties=$graph->get_subject_properties($s);
            foreach($properties as $p){
              $uris = array_merge($graph->get_resource_triple_values($s,$p), $uris);
            }
          }
      }
    return array_unique($uris);
  }

  function buildDataset($uri){

    echo "\nBuilding $uri \n";
    $endpoint = $this->getSparqlEndpoint($uri);
    echo "\n sparqlEndpoint $endpoint\n";
    $storageFileName = $this->getDestinationFileName($uri);
//    @unlink($storageFileName ); //empty file
    
    if(file_exists($storageFileName)){
      echo "\n$uri already built at $storageFileName . skipping ...\n";
      return true; //already built
    } 

    $subsets=$this->getSubsets($uri);
    if(!empty($subsets)){
      foreach($subsets as $subset){
        $this->buildDataset($subset);
      }
    } else if($queryBody = $this->graph->get_first_literal($uri, DP.'constrainedByQuery')) {
      $this->pageQueryResultsToFile($endpoint, $queryBody, $storageFileName);
    } else if($queryBody = $this->graph->get_first_literal($uri, DP.'constrainedByUnpagedQuery')){
      $this->fetchQueryResultsToFile($endpoint, $queryBody, $storageFileName);
    } else if($triplePatterns = $this->graph->get_literal_triple_values($uri, DP.'constrainingTriplePattern')){

      foreach($triplePatterns as $triplePattern){
        $queryBody = "CONSTRUCT { {$triplePattern} } WHERE { {$triplePattern} }";
        $this->pageQueryResultsToFile($endpoint, $queryBody, $storageFileName);
      }
      
    } else if($linktargets = $this->graph->get_resource_triple_values($uri, DP.'constrainedByLinksToSubjectsFrom')) {
      if(!($constructTemplate=$this->graph->get_first_literal($uri, DP.'constructTemplate'))){
        $constructTemplate = 'CONSTRUCT { ?s ?p ?o ; ?link ?item . } WHERE { ?s ?p ?o ; ?link ?item }';
      }
      foreach($linktargets as $linktarget){
        $this->fetchDataLinkingToSubjectsFrom($endpoint, $linktarget, $storageFileName, $constructTemplate);
      }
    } else{
      if($targets= $this->graph->get_resource_triple_values($uri, DP.'constrainedBySubjectsFrom')){
        $uris = array();
        foreach($targets as $target){
          echo "\nGetting Subjects from $target";
          $uris = array_merge($uris, $this->getSubjectsFrom($target));
        }
      } else if($targets = $this->graph->get_resource_triple_values($uri, DP.'constrainedByObjectsFrom')){
        $uris = array();
        foreach($targets as $target){
          echo "\nGetting Objects from $target";
          $uris = array_merge($uris, $this->getObjectsFrom($target));
        }
      } else {
        throw new Exception("No description of how to get data for $uri ");
      }

      $offset=0;
      $length = 50;
      $constructTemplate = $this->graph->get_first_literal($uri, DP.'constructTemplate');
      while(($slice = array_slice($uris, $offset, $length)) && $offset+=$length){
        if($constructTemplate){
          $filter = " FILTER ( ";
            foreach($slice as $no => $describeUri){
              $filter.=" ?item = <$describeUri>";
              if(isset($slice[($no+1)])){
                $filter.='|| ';
              }
            }
            $filter.=') ';
          
          $queryBody = preg_replace('@WHERE\s+\{@im','WHERE { '. $filter, $constructTemplate, 1);
        } else {
          $queryBody = "DESCRIBE <".implode('> <', $slice).'>';
        }
        $this->fetchQueryResultsToFile($endpoint, $queryBody, $storageFileName);
      }
    }
  }

  function pageQueryResultsToFile($endpoint, $queryBody, $fileName){
      $offset =0;
      $continue = true;
      while($continue) {
        $query = "{$queryBody} LIMIT 100 OFFSET $offset ";
        $continue = $this->fetchQueryResultsToFile($endpoint, $query, $fileName);
        $offset = $offset + 100;
    }  
  }

  function fetchDataLinkingToSubjectsFrom($endpoint, $subsetUri, $fileName, $constructTemplate){
    $subjects = $this->getSubjectsFrom($subsetUri);

    foreach($subjects as $subject){
      $query = str_replace('?item ', "<{$subject}> ", $constructTemplate);
      $this->fetchQueryResultsToFile($endpoint, $query, $fileName);
    }

  }

  function fetchQueryResultsToFile($endpoint, $query, $fileName){
        echo "\nQuerying: $query \nFrom: $endpoint\n";
        if(strstr($endpoint, 'api.kasabi.com')){
          $url = $endpoint.'?output=json&query='.urlencode($query).'&apikey='.API_KEY;
        } else {
          $url = $endpoint.'?query='.urlencode($query);
        }
        
        $data = $this->fetchDataFromUrl($url);
        $graph = new SimpleGraph();
        $graph->add_rdf($data);
        $ntriples = $graph->to_ntriples();
        file_put_contents($fileName, $ntriples, FILE_APPEND);
        $ntriples = trim($ntriples);
        return !empty($ntriples);
  }

  function fetchDataFromUrl($url, $no_of_tries=0){
    $request = $this->http_factory->make('GET', $url);
    $response = $request->execute();
    if($response->is_success()){
      return $response->body;
    } else if($response->status_code[0]=='5' AND $no_of_tries < 10) {
      sleep(5);
      return $this->fetchData($url, ++$no_of_tries);
    } else {
      var_dump($response->status_code, $response->body);
      throw new Exception("Bad request for $url");
    }
  }

  function getDestinationFileName($uri){
    return 'data/'.urlencode($uri).'.nt';
  }


  function uploadDataset($uri){
    echo "\nUploading $uri\n";
    $filename = $this->getDestinationFileName($uri);
    if($source = $this->getSourceForDataset($uri)){
      $subsetUri = $source;
    } else {
      $subsetUri = $uri;
    }
    if(file_exists($filename)){
      if(!isset($this->lastUploaded[$subsetUri]) OR $this->lastUploaded[$subsetUri][$this->uri] < filemtime($filename)){
        echo "\nUploading $filename to graph: $subsetUri\n";
        var_dump($this->getStoreEndpoint($subsetUri)->submit_ntriples_in_batches_from_file($filename, 200, 'upload')->status_code);
        if(!is_array($this->lastUploaded[$subsetUri])){ 
          $this->lastUploaded[$subsetUri] = array();
        }
        $this->lastUploaded[$subsetUri][$this->uri] = time();
        sleep(1);
      } else {
        echo "\n$subsetUri unchanged since last upload\n";
      }
    } else {
      echo "\n No file for $uri, trying subsets\n";
      foreach($this->getSubsets($uri) as $subset){
        $this->uploadDataset($subset);
      }
    }
  }

  function getStoreEndpoint($subsetUri){
    $slug = array_pop(preg_split('@#|/@', $this->uri));
    $this->storeEndpoint->uri =  "http://api.kasabi.com/dataset/$slug/store?graph=".urlencode($subsetUri)."&apikey=".API_KEY;
    echo "\nUploading to: {$this->storeEndpoint->uri}\n";
    return $this->storeEndpoint;
  }

}


function upload($r){
  if($r->is_success()){
    print "...";
    //echo $r->request_body;
  } else if($r->status_code[0]==4) {
    echo "\nBad Request\n";
    echo $r->body;
  } else {
    
  }
}
?>

RDF-Dataset-Combiner
====================


Takes a VoID-based RDF description of the SPARQL queries and datasets used to build the target composite dataset. 

The composite dataset is related to its components with `void:subset`

    <a> void:subset <b> .

    <b> dct:source <http://lod-cloud.net/dbpedia> ;
        dp:constrainingTriplePattern """ 
                ?s <http://purl.org/dc/terms/subject> <http://dbpedia.org/resource/Category:Cheese> .
        """ .

    <http://lod-cloud.net/dbpedia> void:sparqlEndpoint <http://live.dbpedia.org/sparql> .

This subset will itself have a `dct:source` (the value of which is
another dataset URI) and either subsets of its own, or a property giving
a SPARQL query or triple pattern:

* `dp:constrainingTriplePattern` (this will be turned into a CONSTRUCT query that pages through the data)
* `dp:constrainedByQuery` (this contains a CONSTRUCT or DESCRIBE query - OFFSET and LIMIT will be added to page through a dataset)
* `dp:constrainedByUnpagedQuery` (a full CONSTRUCT/DESCRIBE query)
* `dp:constructTemplate` : used in conjunction with:
  - `dp:constrainedBySubjectsFrom`
  - `dp:constrainedByObjectsFrom`
  - `dp:constrainedByLinksToSubjectsFrom` (all these point to other
    constructed subsets)


If you have a linkset and you want to bring it and its targets into your
dataset, you can do something like this:

    <a> void:subset <a-b-intersection> .
    <a-b-intersection> dct:source <b> ;
       dp:constrainedByObjectsFrom <a-b-linkset> .
    <b> void:sparqlEndpoint <b/sparql> .
    <a-b-linkset> void:dataDump <a-b-linkset.nt> .

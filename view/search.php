<?php   

global $mdb;    

$entities = array();    
if ($_POST) {   
    $app->redirect('/search/'.urlencode($_POST['searchbox']).'/');  
}   

$result = zkbSearch::getResults($search);   

// if there is only one result, we redirect.    
if (count($result) == 1) {  
    $first = array_shift($result);  
    $type = str_replace('ID', '', $first['type']);  
    $id = $first['id']; 
    return $app->redirect("/$type/$id/");  
}   

$app->render('search.html', array('data' => $result));

<?php   

global $mdb;    

$entities = array();    
if ($_POST) {   
    // Handle redirect for compatibility
    if (isset($GLOBALS['capture_render_data'])) {
        $GLOBALS['redirect_response'] = $GLOBALS['slim3_response']->withStatus(302)->withHeader('Location', '/search/'.urlencode($_POST['searchbox']).'/');
        return;
    } else {
        $app->redirect('/search/'.urlencode($_POST['searchbox']).'/');  
    }
}   

$result = zkbSearch::getResults($search);   

// if there is only one result, we redirect.    
if (count($result) == 1) {  
    $first = array_shift($result);  
    $type = str_replace('ID', '', $first['type']);  
    $id = $first['id']; 
    // Handle redirect for compatibility
    if (isset($GLOBALS['capture_render_data'])) {
        $GLOBALS['redirect_response'] = $GLOBALS['slim3_response']->withStatus(302)->withHeader('Location', "/$type/$id/");
        return;
    } else {
        return $app->redirect("/$type/$id/");  
    }
}   

// Handle render for compatibility
if (isset($GLOBALS['capture_render_data'])) {
    $GLOBALS['render_template'] = 'search.html';
    $GLOBALS['render_data'] = array('data' => $result);
    return;
} else {
    $app->render('search.html', array('data' => $result));
}

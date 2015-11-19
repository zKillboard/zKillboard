<?php

global $baseDir, $redis;

$path = $baseDir."/information/$page.md";
if (!is_file($path)) {
    $app->redirect('/');
}

// Load the markdown file
$markdown = file_get_contents($path);

// Load the markdown parser
$parsedown = new Parsedown();
$output = $parsedown->text($markdown);

if ($page == 'payments') {
    global $adFreeMonthCost;
    $output = str_replace('{cost}', $adFreeMonthCost, $output);
}

if ($page == 'statistics') {
    // Replace certain tags with different data
    $info = array();
    $info['kills'] = number_format($redis->get('zkb:totalKills'), 0);
    $info['crest'] = number_format($redis->get('zkb:crestRemaining'), 0);
    $info['total'] = number_format($redis->get('zkb:totalkills'), 0);
    $info['percentage'] = number_format($info['total'] / $info['kills'] * 100, 2, '.', ',');
    //$info['NextWalletFetch'] = Storage::retrieve('NextWalletFetch');

    foreach ($info as $k => $d) {
        $output = str_replace('{'.$k.'}', $d, $output);
    }

    $info['apistats'] = []; //Db::query("select errorCode, count(*) count from zz_api_log where requestTime >= date_sub(now(), interval 1 hour) group by 1");

    $apitable = '
	<table class="table table-striped table-hover table-bordered">
	  <tr><th>Error</th><th>Count</th></tr>';

    foreach ($info['apistats'] as $data) {
        $apitable .= '<tr>';
        $apitable .= '<td>';

        if ($data['errorCode'] == null) {
            $apitable .= 'No error';
        } else {
            $apitable .= $data['errorCode'];
        }

        $apitable .= '</td>';
        $apitable .= '<td>';
        $apitable .= number_format($data['count']);
        $apitable .= '</td>';
        $apitable .= '</tr>';
    }
    $apitable .= '</table>';

    $output = str_replace('{apitable}', $apitable, $output);

    $info['pointValues'] = Points::getPointValues();
    $pointtable = '<ul>';
    foreach ($info['pointValues'] as $points) {
        $pointtable .= '<li>'.$points[0].': '.$points[1].'</li>';
    }
    $pointtable .= '</ul>';

    $output = str_replace('{pointsystem}', $pointtable, $output);
}

// Load the information page html, which is just the bare minimum to load base.html and whatnot, and then spit out the markdown output!
$app->render('information.html', array('data' => $output));

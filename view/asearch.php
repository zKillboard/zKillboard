<?php

$labels = [
    [
        "highsec",
        "lowsec",
        "nullsec",
        "w-space",
        "abyssal",
    ],
    [
        "solo",
        "100+",
        "1000+",
    ],
    [
        "awox",
        "abyssal-pvp",
        "npc",
    ],
    [

        "bigisk",
        "extremeisk",
        "insaneisk",
    ]
];

$app->render('asearch.html', ['labels' => $labels]);

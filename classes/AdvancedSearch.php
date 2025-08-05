<?php

class AdvancedSearch 
{
    static public $labels = [
        'location' => [
            "loc:highsec", "loc:lowsec", "loc:nullsec", "loc:w-space", "loc:abyssal",
        ],
        'count' => [
            "solo", "#:1", "#:2+", "#:5+", "#:10+", "#:25+", "#:50+", "#:100+", "#:1000+",
        ],
        'timezone' => [
            "tz:au", "tz:eu", "tz:ru", "tz:use", "tz:usw"
        ],
        'flags' => [
            "awox", "ganked", "npc", "pvp", 'padding'
        ],
        'isk' => [
            "isk:1b+", "isk:5b+", "isk:10b+", "isk:100b+"
        ],
        'custom' => [
            'atShip', 'capital', 'cat:0', 'cat:11', 'cat:18', 'cat:22', 'cat:23',  'cat:350001', 'cat:40', 'cat:46', 'cat:6', 'cat:65', 'cat:87'
        ]
    ];
}

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
        'flags' => [
            "awox", "atShip", "ganked", "npc", "pvp", 'padding'
        ],
        'isk' => [
            "isk:1b+", "isk:5b+", "isk:10b+", "isk:100b+"
        ],
        'custom' => [
            'cat:65', 'capital',
        ]
    ];
}

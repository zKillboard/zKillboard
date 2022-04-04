<?php

class AdvancedSearch 
{
    static public $labels = [
        'location' => [
            "highsec", "lowsec", "nullsec", "w-space", "abyssal",
        ],
        'count' => [
            "solo", "2+", "5+", "10+", "25+", "50+", "100+", "1000+",
        ],
        'flags' => [
            "awox", "ganked", "npc", "pvp", 'padding'
        ],
        'isk' => [
            "1b+", "5b+", "10b+", "100b+"
        ],
        'custom' => [
            'cat:65', 'capital',
        ]
    ];
}

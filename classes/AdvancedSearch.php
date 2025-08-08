<?php

class AdvancedSearch 
{
    static public $labels = [
        'Location' => [
            "loc:highsec" => "HighSec", 
            "loc:lowsec" => "LowSec", 
            "loc:nullsec" => "NullSec", 
            "loc:w-space" => "W-Space", 
            "loc:abyssal" => "Abyssal",
        ],
        'Involved' => [
            "solo" => "Solo",
            "#:1" => "Just 1",
            "#:2+" => "2+",
            "#:5+" => "5+", 
            "#:10+" => "10+", 
            "#:25+" => "25+", 
            "#:50+" => "50+", 
            "#:100+" => "100+", 
            "#:1000+" => "1000+"
        ],
        'Primetime' => [
            "tz:au" => "Aus", 
            "tz:eu" => "Eur", 
            "tz:ru" => "Russ", 
            "tz:use" => "US East", 
            "tz:usw" => "US West",
        ],
        'Flags' => [
            "awox" => "Awox", 
            "ganked" => "HighSec Gank", 
            "npc" => "NPC", 
            "pvp" => "PVP", 
            "padding" => "Padding"
        ],
        'Isk' => [
            "isk:1b+" => "1b+", 
            "isk:5b+" => "5b+", 
            "isk:10b+" => "10b+", 
            "isk:100b+" => "100b+"
        ],
        'Custom' => [
            'atShip' => "AT Ships", 
            'capital' => "Capitals", 
            'cat:0' => "Cat 0", 
            'cat:11' => "Cat 11", 
            'cat:18' => "Cat 18", 
            'cat:22' => "Cat 22", 
            'cat:23' => "Cat 23",  
            'cat:350001' => "Cat 350001", 
            'cat:40' => "Cat 40",  
            'cat:46' => "Cat 46", 
            'cat:6' => "Cat 6", 
            'cat:65' => "Cat 65", 
            'cat:87' => "Cat 87"
        ]
    ];
}

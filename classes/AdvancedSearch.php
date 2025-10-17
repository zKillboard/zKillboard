<?php

class AdvancedSearch 
{
    static public $labels = [
        'location' => [
            "loc:highsec" => "HighSec", 
            "loc:lowsec" => "LowSec", 
            "loc:nullsec" => "NullSec", 
            "loc:w-space" => "W-Space", 
            "loc:abyssal" => "Abyssal",
        ],
        'count' => [
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
        'primetime' => [
            "tz:au" => "Aus / China", 
            "tz:eu" => "Europe", 
            "tz:ru" => "Russian", 
            "tz:use" => "USA East", 
            "tz:usw" => "USA West",
        ],
        'flags' => [
            "awox" => "Awox", 
            "ganked" => "HighSec Gank", 
            "npc" => "NPC", 
            "pvp" => "PVP", 
            "padding" => "Padding"
        ],
        'isk' => [
            "isk:1b+" => "1b+", 
            "isk:5b+" => "5b+", 
            "isk:10b+" => "10b+", 
            "isk:100b+" => "100b+",
            "isk:1t+" => "1t+"
        ],
        'custom' => [
            'cat:22' => "Anchored", 
            'atShip' => "AT Ships", 
            'capital' => "Capitals", 
            'cat:18' => "Drone", 
            'cat:87' => "Fighter",
            'cat:46' => "PI", 
            'cat:23' => "POS",  
            'cat:6' => "Ship", 
            'cat:40' => "Sov",  
            'cat:65' => "Structure"
            /*'cat:0' => "Cat 0", */
            /*'cat:11' => "Structure Light Fighter", */
            /*'cat:350001' => "Dust 514", */
        ]
    ];
}

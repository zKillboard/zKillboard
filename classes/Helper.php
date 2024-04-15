<?php

class Helper {

    /**
     * convert isk to usd/eur/gbp
     * @param $totalprice
     * @return float[]|int[]
     */
    public static function iskToUsdEurGbp($totalprice)
    {
        // Prices are based on highest tier plex pack (20k plex)
        $usd = 16.25;
        $eur = 16.25;
        $gbp = 12.5;
        $plex = 500 * Price::getItemPrice(44992, date('Y-m-d H:i'));
        $usdVal = $plex / $usd;
        $eurVal = $plex / $eur;
        $gbpVal = $plex / $gbp;

        return array('usd' => $totalprice / $usdVal, 'eur' => $totalprice / $eurVal, 'gbp' => $totalprice / $gbpVal);
    }

}
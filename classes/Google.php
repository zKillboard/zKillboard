<?php

class Google
{
    public static function analytics($analyticsID, $analyticsName)
    {
        $html = "<script>
            (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
             (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
             m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
             })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

        ga('create', '".$analyticsID."', 'auto');
        ga('send', 'pageview');

        </script>";

        return $html;
    }

    public static function getAd()
    {
        global $dataAdClient, $dataAdSlot;
return '
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>
<!-- zkb Responsive Ad -->
<ins class="adsbygoogle"
     style="display:inline-block;min-width:400px;max-width:970px;width:100%;height:90px"
     data-ad-client="ca-pub-7481220870937701"
     data-ad-slot="2897013058"
     data-ad-format="auto"
     data-full-width-responsive="true"></ins>
<script>
     (adsbygoogle = window.adsbygoogle || []).push({});
</script>


';
        return '
<center>
<script async src="//pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>
<!-- zkb Responsive Ad -->
<ins class="adsbygoogle"
     style="display:block"
     data-ad-client="' . $dataAdClient . '"
     data-ad-slot="' . $dataAdSlot . '"
     data-ad-format="auto"></ins>
<script>
(adsbygoogle = window.adsbygoogle || []).push({});
</script>
</center>
        ';
    }
}

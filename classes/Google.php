<?php

class Google
{
    public static function analytics($analyticsID, $analyticsName)
    {
        return "
<!-- Google tag (gtag.js) -->
<script async src='https://www.googletagmanager.com/gtag/js?id=G-FD2835WTF0'></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-FD2835WTF0');
  gtag('send', 'pageview');
  gtag('send', 'event', 'sanity_check');
</script>
";
    }

    public static function getAd()
    {
        global $dataAdClient, $dataAdSlot;
return '
<div data-fuse="22070413988"></div>
';
    }
}

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

	public static function ad($caPub, $adSlot, $adWidth = 728, $adHeight = 90)
	{
		$html = '
			<script type="text/javascript"><!--
			google_ad_client = "'.$caPub.'";
		google_ad_slot = "'.$adSlot.'";
		google_ad_width = '.$adWidth.';
		google_ad_height = '.$adHeight.';
		//-->
		</script>
			<script type="text/javascript"
			src="//pagead2.googlesyndication.com/pagead/show_ads.js">
			</script>
			';

		return $html;
	}
}

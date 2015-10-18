$(document).ready(function() {
	//setTimeout(updateKillsLastHour, 60000);

	// Check to see if the user has ads enabled
	if ( $("iframe").length == 0 ) {
		//$("#adsensetop, #adsensebottom").html("<div><small><a href='/information/payments/'>Advertising seems to be blocked by your browser. Click here to learn how to disable ads. Otherwise, may all your ships quickly become wrecks!</a></small></div>");
	}

    if ($("[rel=tooltip]").length) {
		$("[rel=tooltip]").tooltip({
			placement: "bottom",
			animation: false
		});
    }

	//
    $('.dropdown-toggle').dropdown();
    $("abbr.timeago").timeago();
    $(".alert").alert()

    // Javascript to enable link to tab
    var url = document.location.toString();
    if (url.match('#')) {
        $('.nav-pills a[href=#'+url.split('#')[1]+']').tab('show') ;
    }

    // Change hash for page-reload
    $('.nav-pills a').on('shown', function (e) {
        window.location.hash = e.target.hash;
    })

	// hide #back-top first
	$("#back-top").hide();

	// fade in #back-top
	$(function () {
		$(window).scroll(function () {
			if ($(this).scrollTop() > 500) {
				$('#back-top').fadeIn();
			} else {
				$('#back-top').fadeOut();
			}
		});

		// scroll body to 0px on click
		$('#back-top a').click(function () {
			$('body,html').animate({
				scrollTop: 0
			}, 100);
			return false;
		});
	});
	
	//add the autocomplete search thing
	$('#searchbox').zz_search( function(data, event) { window.location = '/' + data.type + '/' + data.id + '/'; event.preventDefault(); } );
	
	//and for the tracker entity lookup
	$('#addentitybox').zz_search( function(data) { 
		$('#addentity input[name="entitymetadata"]').val(JSON.stringify(data));
		$('#addentity input[name="addentitybox"]').val(data.name);
		$('#addentity').submit();
	});

    // prevent firing of window.location in table rows if a link is clicked directly
	$('.killListRow a').click(function(e) {
		e.stopPropagation();
	});

	$('a.openMenu').click(function(e){
		$('.content').toggleClass('opened');
		$('.mobileNav').toggleClass('opened');
		e.preventDefault();
	});

	// auto show comments tab on detail page
	if(window.location.hash.match(/comment/)) {
		$('a[href="#comment"]').tab('show');
	}

	if (top !== self) {
		$("#iframed").modal('show');
	}

	if (characterID > 0) {
		$('#characterID').prepend('<img src="http://image.eveonline.com/Character/' + characterID + '_32.jpg" style="height: 24px; width: 24px;"/>');
		$('#fauser').hide();
	}
});

function updateKillsLastHour() {
	$("#lasthour").load("/killslasthour/");
	setTimeout(updateKillsLastHour, 60000);
}

$('body').on('touchstart.dropdown', '.dropdown-menu', function (e) { e.stopPropagation(); });

$(function() {
    //$('.nav-wrapper').height($("#nav").height());
    
    $('#nav').affix({
        offset: { top: $('#nav').offset().top }
    });
});

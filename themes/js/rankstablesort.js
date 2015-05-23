$(document).ready(function() 
    { 
jQuery.extend( jQuery.fn.dataTableExt.oSort, {
    "numeric-comma-pre": function ( a ) {
		var a = a.replace( /<.*?>/g, "" );
        var a = a.replace( /,/, "" );
		var before = a;
        var a = a.replace( /TTT/g, "00000000000000000" );
        var a = a.replace( /TT/g, "000000000000000" );
        var a = a.replace( /T/g, "000000000000" );
        var a = a.replace( /B/g, "000000000" );
        var a = a.replace( /M/g, "000000" );
        var a = a.replace( /K/g, "000" );
        var a = a.replace( /,/, "" );
		var after = a;
		if (before != after) a = a.replace('.', "");
        return parseFloat( a );
    },
 
    "numeric-comma-asc": function ( a, b ) {
        return ((a < b) ? -1 : ((a > b) ? 1 : 0));
    },
 
    "numeric-comma-desc": function ( a, b ) {
        return ((a < b) ? 1 : ((a > b) ? -1 : 0));
    }
} );

	$(".corpstats").dataTable({
        "bPaginate": false,
        "bLengthChange": false,
        "bFilter": false,
        "bSort": true,
        "bInfo": false,
        "bAutoWidth": false,
		"aoColumns": [
			{ "sType" : "string" },
			{ "sType" : "string" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "string" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
		]	
	});
        $(".rank-table").dataTable( {
        "bPaginate": false,
        "bLengthChange": false,
        "bFilter": false,
        "bSort": true,
        "bInfo": false,
        "bAutoWidth": false,
		"aoColumns": [
			{ "sType" : "numeric-comma" },
			{ "sType" : "string" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
			{ "sType" : "numeric-comma" },
		]
    } );
    } 
);

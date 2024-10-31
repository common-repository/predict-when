/*
 * User JS functions
 * 
 * @package PredictWhen
 * @version $Id$
 * @author ian
 * Copyright Ian Haycox, 2012
 */
jQuery(document).ready(function($) {

	$('.predictwhen_user_datepicker').datepicker({
		beforeShow: function() {
	        $(this).datepicker("widget").wrap('<div class="predictwhen"/>');  // Scoping
	    },
	    onClose: function() {
	        $(this).datepicker("widget").unwrap();
	    },
	    dateFormat : 'yy-mm-dd',
		showWeek: true,
		firstDay: 1,
		changeMonth: true,
		changeYear: true
	});
	
	$('.predictwhen_user_monthpicker').monthpicker({
		onShow: 'focus',
		beforeShow: function() {
	        $('#ui-monthpicker-div').wrap('<div class="predictwhen"/>');  // Scoping
	    },
	    onClose: function() {
	    	$('#ui-monthpicker-div').unwrap();
	    },
	    dateFormat : 'yy-mm-01',
		firstDay: 1,
		changeYear: true
	});
	
	$('#predictwhen_date_interval').change(function () {                
	     $('.predictwhen_dateshow').toggle(this.checked);
	     $('.predictwhen_datehide').toggle(!this.checked);
	}).change(); //ensure visible state matches initially
	
	
	$('a.predictwhen_embed_link').click(function() {
		var id = $(this).attr('id');
		$('#predictwhen_embed_' + id).toggle();
		return false;
	});
	
});
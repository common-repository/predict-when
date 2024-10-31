/*
 * Admin JS functions
 * 
 * @package PredictWhen
 * @version $Id$
 * @author ian
 * Copyright Ian Haycox, 2012
 */
jQuery(document).ready(function($) {

	$( "#predictwhen_tabs" ).tabs();
	
	if ($('#predictwhen_colorpicker').length) {
		// Seems to crap out if the selector is missing !!!

		var f = $.farbtastic('#predictwhen_colorpicker');
		var p = $('#predictwhen_colorpicker').css('opacity', 0.25);
		var selected = false;
		$('.predictwhen_colorwell').each(function() {
			f.linkTo(this);
			$(this).css('opacity', 0.75);
		}).focus(
				function() {
					if (selected) {
						$(selected).css('opacity', 0.75).removeClass(
								'predictwhen_colorwell-selected');
					}
					f.linkTo(this);
					p.css('opacity', 1);
					$(selected = this).css('opacity', 1).addClass(
							'predictwhen_colorwell-selected');
				});
	}
	
	$('.predictwhen_datepicker').datepicker({
		dateFormat : 'yy-mm-dd',
		showWeek: true,
		firstDay: 1,
		changeMonth: true,
		changeYear: true,
		minDate: $('#predictwhen_min_date').val(),
		maxDate: $('#predictwhen_max_date').val()
	});
	
	$('.predictwhen_monthpicker').monthpicker({
		showOn: 'focus',
		dateFormat : 'yy-mm-01',
		firstDay: 1,
		changeYear: true,
		minDate: $('#predictwhen_min_date').val(),
		maxDate: $('#predictwhen_max_date').val()
	});

	$('#form-question_id').change(function() {
		this.form.submit();
	});
	
	$('#predictwhen_date_interval').change(function () {                
	     $('.predictwhen_dateshow').toggle(this.checked);
	     $('.predictwhen_datehide').toggle(!this.checked);
	}).change(); //ensure visible state matches initially
	
	$('#predictwhen_registration_required').change(function () {                
	     $('.predictwhen_show').toggle(this.checked);
	     $('.predictwhen_hide').toggle(!this.checked);
	}).change(); //ensure visible state matches initially
	
	$( "#dialog-modal" ).dialog({
		autoOpen: false,
		width: 380,
		modal: true
	});
	$('#scoring-dialog').click(function() {
		$( "#dialog-modal" ).dialog('open');
		return false;
	});
});
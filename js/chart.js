/*
 * Charting functions via Google Charts API
 *
 * @package PredictWhen
 * @version $Id$
 * @author ian
 * Copyright Ian Haycox, 2012
 */

// Load the Visualization API and the corechart package.
google.load('visualization', '1.0', {
	'packages' : [ 'corechart' ], 'language' : PredictWhenAjax.ISO639
});

// Set a callback to run when the Google Visualization API is loaded.
google.setOnLoadCallback(predictwhen_draw_chart);


// Callback that creates and populates a data table,
// instantiates the pie chart, passes in the data and
// draws it.
function predictwhen_draw_chart() {
	
	
	jQuery(document).ready(function($) {
	
		$('.predictwhen_close_datepicker').datepicker({
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
		
		$('form.predictwhen_submit_prediction').submit(function() {
			var formdata = $(this).serialize();
			$.ajax({
				type:'POST',
		        url: PredictWhenAjax.blogUrl,
				data:"action=predictwhen_ajax&" + formdata,
				dataType:"json",
				async:true,
		        success: function(msg){
		        	var id = msg.predictwhen_id;
		        	var div = $('#predictwhen_chart_' + id)[0];
		        	if (msg.predictwhen_ret) {
			        	$('#predictwhen_form_container_' + id).html(msg.predictwhen_msg);
			        	predictwhen_draw_chart_ajax(id, div, false, msg.predictwhen_date_interval);
		        	} else {
		        		$('#predictwhen_message').remove();
			        	$('#predictwhen_form_container_' + id).prepend(msg.predictwhen_msg);
		        	}
		        }
			});
			
			return false;
		}); 
		
		/*
		 * For the preview chart in settings
		 */
		
		$('#predictwhen_chart_preview').each(function() {
			
			var div = this;
			var date_interval = $(this).attr('predictwhen_date_interval');
			
            var gridline_color = '#CCC';
            if ($('#predictwhen_hide_grid_chart').is(':checked')) {
            	gridline_color = $('#predictwhen_color_chart_background').val();
            }
			
			var data = new google.visualization.DataTable($('#predictwhen_preview_data').val());
            var formatter;
            var ca_format = "MMMM d, yyyy";
            if (date_interval == 'months') {
				formatter = new google.visualization.DateFormat({pattern: 'MMMM y'});  // e.g. October 2012
				ca_format = 'MMMM y';
            } else {
				formatter = new google.visualization.DateFormat({formatType: 'long'});  // e.g. October 28, 2012
            }
			
			formatter.format(data, 0);

            var options = {
            		  backgroundColor: {fill:$('#predictwhen_color_chart_background').val() },
		              colors: [$('#predictwhen_color_chart_bars').val(), $('#predictwhen_color_predicted_date').val(), $('#predictwhen_color_predicted_user').val(), $('#predictwhen_color_event_date').val()],
					  isStacked:true,
					  height:300,
					  width:500,
					  hAxis: {format:ca_format, baselineColor:gridline_color, gridlines: {color:gridline_color}},
		              vAxis: {gridlines: {color:gridline_color}, format: '#', maxValue:3, minValue:1 },
					  chartArea: {width: '80%', height: '70%', top:10},
		              legend: {position:'bottom'},
		              bar: {groupWidth:'90%'}
		            };

            var chart = new google.visualization.ColumnChart(div);
            chart.draw(data, options);
		});
		
		/*
		 * For each chart displayed via a shortcode
		 */
		$('div.predictwhen_chart').each(function() {

		    // use $(this) to reference the current div in the loop

			var id = $(this).attr('predictwhen_id');
			var date_interval = $(this).attr('predictwhen_date_interval');
			var div = this;
			
			predictwhen_draw_chart_ajax(id, div, true, date_interval);

		});
		
		function predictwhen_draw_chart_ajax(id, div, show_datepicker, date_interval) {
			
			$.ajax({
		        url: PredictWhenAjax.blogUrl,
				data:"action=predictwhen_ajax&id=" + id,
		        dataType:"json",
		        async: true,
		        success: function(msg){
		        	
		        	// If our Id's do not match then prediction not found
		        	if (id != msg.id) return false;
		        	
		        	if (show_datepicker) {
			        	
		        		if (date_interval == 'months') {
				        	// MonthPicker
		        			
				        	var today = new Date(); 
				        	var ymin = 'c-0';
				        	var ymax = 'c+20';
				        	
				        	// Restrict min/max dates ?
				        	if (msg.min_date != '') {
					        	if ($.datepicker.parseDate('yy-mm-dd', msg.min_date) < today) {
					        		ymin = today.getFullYear();
					        	} else {
					        		ymin = msg.min_date.substr(0,4);
					        	}
				        	}
				        	if (msg.max_date != '') {
				        		ymax = msg.max_date.substr(0,4);
				        	}
				        	
				        	var year_range = ymin + ':' + ymax;
				        	
				        	$( ".datepicker_" + id ).monthpicker({
				        		onSelect: function(dateText, inst) { 
				        			
				        			$.ajax({
				        		        url: PredictWhenAjax.blogUrl,
				        				data:"action=predictwhen_ajax&month=1&date=" + dateText,
				        		        async: true,
				        		        success: function(msg){
		        		        			$('#predictwhen_date'+id).text(msg); 
				        		        			$('#predictwhen_date'+id).text(msg); 
				        		        			$('#predictwhen_date_msg'+id).hide();
				        		        			$('#predictwhen_date_msg_selected'+id).show();
				        		        	
				        		        }
				        			});
				        			
				        			// Enable the Submit button
				        			$('#predictwhen_submit_prediction'+id).removeAttr('disabled');
				        			$('#predictwhen_submit_prediction'+id).removeAttr('title');
				        		},
				    			altField: "#predictwhen_predicted_date_" + id,
				    			altFormat: "yy-mm-01",
				    			dateFormat: 'yy-mm-01',
				    			changeYear: true,
				    			yearRange:year_range,
				    			defaultDate : -1
				    		});
				        	
		        		} else {
				        	// DatePicker
				        	$( ".datepicker_" + id ).datepicker({
				        		onSelect: function(dateText, inst) { 
				        			
				        			$.ajax({
				        		        url: PredictWhenAjax.blogUrl,
				        				data:"action=predictwhen_ajax&date=" + dateText,
				        		        async: true,
				        		        success: function(msg){
		        		        			$('#predictwhen_date'+id).text(msg); 
				        		        			$('#predictwhen_date'+id).text(msg); 
				        		        			$('#predictwhen_date_msg'+id).hide();
				        		        			$('#predictwhen_date_msg_selected'+id).show();
				        		        	
				        		        }
				        			});
				        			
				        			// Enable the Submit button
				        			$('#predictwhen_submit_prediction'+id).removeAttr('disabled');
				        			$('#predictwhen_submit_prediction'+id).removeAttr('title');
				        		},
				    			altField: "#predictwhen_predicted_date_" + id,
				    			altFormat: "yy-mm-dd",
				    			dateFormat: 'yy-mm-dd',
				    			changeYear: true,
				    			defaultDate : -1
				    		});
				        	
				        	var today = new Date(); 
				        	
				        	
				        	// Restrict min/max dates ?
				        	if (msg.min_date != '') {
					        	if ($.datepicker.parseDate('yy-mm-dd', msg.min_date) < today) {
					        		$( ".datepicker_" + id ).datepicker( "option", "minDate", today);
					        	} else {
					        		$( ".datepicker_" + id ).datepicker( "option", "minDate", msg.min_date);
					        	}
				        	} else {
				        		$( ".datepicker_" + id ).datepicker( "option", "minDate", today);
				        	}
				        	if (msg.max_date != '') {
				        		$( ".datepicker_" + id ).datepicker( "option", "maxDate", msg.max_date);
				        	}
		        		}
			        	
			        	// don't highlight current day
			        	$(".datepicker_" + id + " a").removeClass("ui-state-active ui-state-highlight");
			        	
			        	// Handle toggling of the Never option.
			        	$('#predictwhen_never' + id).change(function () {                
			        		$('#predictwhen_show' + id).toggle(this.checked);
			        		$('#predictwhen_hide' + id).toggle(!this.checked);
			        		
			        		$("#predictwhen_predicted_date_" + id).val('');
			        		
			        		if (this.checked) {
			        			// Enable the Submit button
			        			$('#predictwhen_submit_prediction'+id).removeAttr('disabled');
			        			$('#predictwhen_submit_prediction'+id).removeAttr('title');
			        		} else {
			        			$('#predictwhen_date_msg'+id).show();
			        			$('#predictwhen_date_msg_selected'+id).hide();
			        			$('#predictwhen_date'+id).text('');
			        			$('#predictwhen_submit_prediction'+id).attr('disabled', true);
			        		}
			        	}).change(); //ensure visible state matches initially
		        	}
		        	
		            // Create our data table out of JSON data returned from server.
		            var data = new google.visualization.DataTable(msg.data);
		            
		            // Add 'Your Prediction' to chart
		            if (msg.already_predicted != null) {
		            	
		            	// Columns are (date legend), predictions, mean (crowd prediction), event, user prediction
		            	//
		            	// If mean column missing then insert dummies.
		            	
		            	var num = data.getNumberOfColumns();
		            	while (num < 3) {
		            		data.addColumn('number');
		            		num++;
		            	}
		            	
		            	// Add our prediction to a new column
			            var col = data.addColumn('number', PredictWhenAjax.user_label);
			            if (date_interval == 'months') {
			            	if (msg.user_idx >= 0) {
			            		data.setCell(msg.user_idx, col, 1);
			            	}
			            } else {
				            if (num == 3) {
				            	data.addRow([new Date(msg.already_predicted), 0, 0, 1]);
				            } else {
				            	data.addRow([new Date(msg.already_predicted), 0, 0, 0, 1]);
				            }
			            }
		            }
		            
		            var formatter;
		            var ca_format = "MMMM d, yyyy";
		            if (date_interval == 'months') {
						formatter = new google.visualization.DateFormat({pattern: 'MMMM y'});  // e.g. October 2012
						ca_format = 'MMMM y';
		            } else {
						formatter = new google.visualization.DateFormat({formatType: 'long'});  // e.g. October 28, 2012
		            }
					formatter.format(data, 0);
		            
		            var ca_width = '90%';
		            var ca_height = '70%';
		            var bar_width = '90%';
		            
		            if (msg.num_predictions < 2) {
		            	bar_width = '10%';
		            } 
		            if (msg.num_predictions < 10) {
		            	bar_width = '20%';
		            } 
		            
		            var display_tooltips = 'hover';
		            var haxis_position = 'out';
		            if (msg.hide_axis) {
		            	haxis_position = 'none';
		            	display_tooltips = 'none';  // If hAxis title hidden disable tooltips
		            	ca_height = '90%';			// No haxis labels for remove white space
		            }
		            
		            var gridline_color = '#CCC';
		            if (msg.hide_grid) {
		            	gridline_color = msg.color_chart_background;
		            }
		            
		            
		            var options = {
//		              title: msg.title,
//					  titleTextStyle: {color: msg.fontcolor, fontName: msg.fontname, fontSize: msg.fontsize},
		              height:400,
					  chartArea: {width: ca_width, height: ca_height, top:10},
		              hAxis: {title: null, textPosition: haxis_position, format: ca_format, baselineColor:gridline_color, gridlines: {color:gridline_color}},
		              tooltip: {trigger: display_tooltips},
		              colors: [msg.color_chart_bars, msg.color_predicted_date, msg.color_event_date, msg.color_predicted_user],
            		  backgroundColor: {fill: msg.color_chart_background },
		              isStacked:true,
		              legend: {position:'bottom'},
		              vAxis: {gridlines: {color:gridline_color}, format: '#', maxValue:3, minValue:1 },
		              bar: {groupWidth:bar_width}

		            };
		
		            var chart = new google.visualization.ColumnChart(div);
		            
		            chart.draw(data, options);
		        	
				}
		    });

		};
		
        
	});
}

/*
 * Display the entry form
 */
function predictwhen_make_prediction(id) {
	jQuery("button#predictwhen_submit_" + id).hide("slow");
	jQuery("table#predictwhen_entry_form_" + id).show("slow");
	
}
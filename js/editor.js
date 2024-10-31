/*
 * TinyMCE editor functions to insert a shortcode
 * 
 * @package PredictWhen
 * @version $Id$
 * @author ian
 * Copyright Ian Haycox, 2012
 */
jQuery(document).ready(function($) {
	
    function predictwhen_tinymce_button_click() {
        var title = predictwhen_editor_AJAX.title;
        var url = predictwhen_editor_AJAX.url;
        var json_str = predictwhen_editor_AJAX.defaults.replace(/&quot;/g, '"');
        
        // wp_localize_script is buggy, so build url here
        
        url = url + '?action=predictwhen_editor_ajax&editor=1';
        

        var defaults = jQuery.parseJSON(json_str);

        
        tb_show( title, url, false );

        $( '#TB_ajaxContent' ).width( 'auto' ).height( '99%' )
        .click( function(event) {
            var $target = $(event.target);
            var id = 0;
            
            if ( $target.is( '#predictwhen_insert' ) ) {
            	
                var shortcode = '[predictwhen ';
                
            	/*
            	 * Find all the visible elements and get the values
            	 * to build our shortcode parameters
            	 */
            	$('.predictwhen_input:visible').each(function(index) {
            		
            		
            		var name = $(this).attr('name');
            		var val = $(this).val();
            		
            		if (name == 'id') {
            			id = parseInt(val, 10);
            		}
            		
            		if ($(this).attr('type') == 'checkbox') {
            			if (!$(this).attr('checked')) {
            				val = 0;
            			}
            		}
            		
            		/*
            		 * If the value is NOT the same as the default value
            		 * and the shortcode allows the parameter then add it.
            		 */
            		if (val != defaults[name]) {
            			shortcode += ' ' + name + '="' + val + '"';
            		}
            	});
            	
            	// trim and insert if not empty
            	shortcode = shortcode.replace(/^\s\s*/, '').replace(/\s\s*$/, '');
            	if ((shortcode.indexOf("]") == -1)) {
            		shortcode += ']';
            	}
            	
            	if (id != 0) {
            		tinyMCE.execCommand( 'mceInsertContent', 0, shortcode);
            	}
            	
                tb_remove();
            } else {
            	return true;
            }
            return false;
        } );

        return false;
    }

    
    if (typeof(tinymce) != "undefined") {
    
	    tinymce.create('tinymce.plugins.' + predictwhen_editor_AJAX.prefix + 'editor', {
	        // creates control instances based on the control's id.
	        // our button's id is &quot;mygallery_button&quot;
	        createControl : function(id, controlManager) {
	            if (id == predictwhen_editor_AJAX.prefix + 'button') {
	                // creates the button
	                var button = controlManager.createButton(predictwhen_editor_AJAX.prefix + 'button', {
	                    title : predictwhen_editor_AJAX.title,
	                    onclick : function() {
	                		predictwhen_tinymce_button_click();
	                    }
	                });
	                return button;
	            }
	            return null;
	        }
	    });
	 
	    // registers the plugin. DON'T MISS THIS STEP!!!
	    tinymce.PluginManager.add(predictwhen_editor_AJAX.prefix + 'editor', tinymce.plugins.predictwhen_editor);
    }
    
});
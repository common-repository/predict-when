<?php
/**
 * Administration functions.
 * 
 * @author Ian Haycox - ian.haycox@gmail.com
 * @version $Id$
 * @package	 PredictWhen
 * @copyright Copyright 2012
 *
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class PredictWhenAdmin extends PredictWhen {
	
	var $page_size = 10;		// Size of display table
	
	
	var $default_approve_subject = '';
	var $default_approve_body = '';
	var $default_reject_subject = '';
	var $default_reject_body = '';
	
	/**
	 * Cookie expiration times in seconds to prevent multiple predictions
	 */
	
	var $cookie_expirations = array(3600 => '1 hour',
				10800 => '3 hours',
				21600 => '6 hours',
				43200 => '12 hours',
				86400=>'1 day',
				604800=>'1 week',
				2419200 => '1 month');
	
	var $admin_pages = array('top' => '', 'questions' => '', 'add-question' => '', 'predictions' => '', 'options' => '');
	
	/**
	 * Constructor
	 * 
	 * Setup our filters and actions
	 */
	function __construct() {
		
		parent::__construct();
		
		$this->default_approve_subject = 'Your question on %%sitetitle%% has been approved';
		$this->default_approve_body = <<<EOT
<p>Hello %%user%%,</p>

<p>Thank you for inviting predictions to your question '%%question%%'. It has now been approved and is available here:</p>

<p>%%questionurl%%</p>

<p>The wisdom of crowds requires a crowd so be sure to invite others to make their predictions in response to your question.
You might like to use the embed code provided at the foot of this email to add a live update of the chart to your own website. </p>

<p>Thank you for participating.</p>

%%embed%%

EOT;
		
		$this->default_reject_subject = 'Your question on %%sitetitle%% has been rejected';
		$this->default_reject_body = <<<EOT
<p>Hello %%user%%,</p>

<p>Thanks for submitting your question '%%question%%'. Unfortunately it has been rejected for the following reasons:</p>

<p>%%rejectreason%%</p>

<p>Submit another question at <a href="%%siteurl%%">%%sitetitle%%</a>.</p>

<p>Thanks for participating.</p>

EOT;
		
		if (!isset ($_SESSION)) {
			session_start();
		}		
		
		// Activation/deactivation functions
		register_activation_hook($this->file, array(&$this, 'activate'));
		register_deactivation_hook($this->file, array(&$this, 'deactivate'));
		
		// Register a uninstall hook to automatically remove all tables & options
		register_uninstall_hook($this->file, array('PredictWhenAdmin', 'uninstall') );
	
		add_action('admin_init', array(&$this, 'admin_init'));
		add_action('admin_menu', array(&$this, 'admin_menu'));
		add_action('admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'));
		add_action('wp_ajax_predictwhen_editor_ajax', array(&$this,'ajax_editor'));   // Tie up with prefix
		
		add_action( 'admin_body_class', array( &$this, 'admin_body_class' ) );
		add_action( 'save_post', array(&$this, 'save_post' ));
		
		add_filter( 'plugin_action_links_' . plugin_basename($this->file), array(&$this, 'plugin_action_links'));
		add_filter( 'set-screen-option', array($this, 'set_screen_option'), 10, 3);
	}
	
	/**
	 * Activate plugin.
	 * 
	 * Create database tables etc.
	 */
	function activate() {
		/*
		 * Create tables
		 */
		
		global $wpdb;
		
		$charset_collate = 'ENGINE=InnoDB ';
		if ( $wpdb->has_cap( 'collation' ) ) {
			if ( ! empty($wpdb->charset) )
			$charset_collate .= "DEFAULT CHARACTER SET $wpdb->charset";
			if ( ! empty($wpdb->collate) )
			$charset_collate .= " COLLATE $wpdb->collate";
		}
		
		// Plugin database table version
		$db_version = $this->db_version;
		
		/*
		 * Questions that are going to be predicted against
		 * 
		 * status - open, closed, pending
		 */
		$sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}{$this->prefix}question` (
				  `question_id` int(11) NOT NULL AUTO_INCREMENT,
				  `name` varchar(255) NOT NULL,
				  `notes` TEXT NOT NULL,
				  `created` DATE NOT NULL,
				  `start_dt` DATE,
				  `end_dt` DATE,
				  `never` BOOL NOT NULL,
				  `publish` BOOL NOT NULL,
				  `registration_required` BOOL NOT NULL,
				  `limit_multiple` INT(11) NOT NULL,
				  `status` enum('open','closed','pending') NOT NULL,
				  `event_dt` DATE,
				  `event_tm` TIME,
				  `predicted_mean` DATE,
				  `post_id` bigint(20) NULL DEFAULT 0,
				  `date_interval` enum('days','months') NOT NULL DEFAULT 'days',
				  `wwhen` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				  PRIMARY KEY (`question_id`),
				  UNIQUE KEY `unq_question_name` (`name`)
				) $charset_collate";
		$ret = $wpdb->query($sql);
		if ($ret === false) {
			error_log($wpdb->last_error);
		}

		/*
		 * The predicted dates
		 */
		$sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}{$this->prefix}prediction` (
				  `prediction_id` int(11) NOT NULL AUTO_INCREMENT,
				  `question_id` int(11) NOT NULL,
				  `user_id` bigint(20),
				  `event_date` DATE,
				  `ipaddress` varchar(100) NOT NULL,
				  `score` int(11) NOT NULL,
				  `wwhen` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				  PRIMARY KEY (`prediction_id`),
				  KEY `user_id_idx` (`user_id`),
				  UNIQUE KEY `unq_prediction_reg` (`question_id`, `user_id`),
				  CONSTRAINT `{$this->prefix}prediction_fk` FOREIGN KEY (`question_id`) REFERENCES `{$wpdb->prefix}{$this->prefix}question` (`question_id`)
			) $charset_collate";
		$ret = $wpdb->query($sql);
		if ($ret === false) {
			error_log($wpdb->last_error);
		}
		
		// Installed plugin database table version
		$installed_ver = get_option($this->prefix . 'db_version', '100');
		// If the database has changed, update the structure while preserving data
		if ($db_version != $installed_ver) {
				
			if ($installed_ver == "100") {
		
				$wpdb->hide_errors();
				$sql = "ALTER TABLE  `{$wpdb->prefix}{$this->prefix}question` ADD  `date_interval` enum('days','months') NOT NULL DEFAULT 'days' AFTER  `post_id`";
				@$wpdb->query($sql);
		
				update_option($this->prefix.'db_version', "101");
			}
		
		}
		update_option($this->prefix.'db_version', $db_version);
		
		
		
		// Default options
		add_option($this->prefix.'color_chart_background', $this->default_color_chart_background);
		add_option($this->prefix.'color_chart_bars', $this->default_color_chart_bars);
		add_option($this->prefix.'color_predicted_date', $this->default_color_predicted_date);
		add_option($this->prefix.'color_predicted_user', $this->default_color_predicted_user);
		add_option($this->prefix.'color_event_date', $this->default_color_event_date);
		add_option($this->prefix.'font_chart', $this->default_font_chart);
		add_option($this->prefix.'font_size_chart', $this->default_font_size_chart);
		add_option($this->prefix.'font_color_chart', $this->default_font_color_chart);
		add_option($this->prefix.'hide_grid_chart', $this->default_hide_grid_chart);

		add_option($this->prefix.'approve_subject', $this->default_approve_subject);
		add_option($this->prefix.'approve_body', $this->default_approve_body);
		add_option($this->prefix.'reject_subject', $this->default_reject_subject);
		add_option($this->prefix.'reject_body', $this->default_reject_body);
		
		
		add_option($this->prefix.'sharing', 1);
		add_option($this->prefix.'embed', 0);
		
		// Yes I know add_option keys for existance - but publish may return false if error
		// and we don't want to store false.
		$api_key = get_option($this->prefix.'api_key');
		if (empty($api_key)) {
			$api_key = $this->publish('api_key');
			if ($api_key !== false) {
				update_option($this->prefix.'api_key', $api_key);
			}
		}
		
		/*
		 * Clear any transients and old options
		 */
		delete_option($this->prefix.'color_predicted_confidence');
		$sql = "SELECT question_id FROM {$wpdb->prefix}{$this->prefix}question";
		$results = $wpdb->get_results($sql);
		foreach ($results as $row) {
			delete_transient($this->prefix.'data'.$row->question_id);
			delete_transient($this->prefix.'dates'.$row->question_id);
		}
		
	}
	
	/**
	 * Deactivate plugin
	 */
	function deactivate() {
		//error_log("deactivate");
	}
	
	/**
	 * Uninstall plugin, delete files, database tables, options etc.
	 */
	static function uninstall() {
		//error_log("uninstall");
	}
	
	/**
	 * Create the Admin menu
	 */
	function admin_menu() {
		
		$this->admin_pages['top'] = add_object_page(__('Predict When Menu', PW_TD), __('Predict When', PW_TD), 'edit_posts', $this->prefix.'menu', array($this, 'menu'), plugin_dir_url($this->file) . 'images/icon-grey.png');
		$this->admin_pages['questions'] = add_submenu_page($this->prefix.'menu' ,__('All Questions', PW_TD), __('All Questions', PW_TD), 'edit_posts', $this->prefix.'menu' , array($this, 'menu'));
		$this->admin_pages['add-question'] = add_submenu_page($this->prefix.'menu' ,__('Add New Question', PW_TD), __('Add New Question', PW_TD), 'edit_posts', $this->prefix.'add_question' , array($this, 'add_question'));
		$this->admin_pages['predictions'] = add_submenu_page($this->prefix.'menu' ,__('Predictions', PW_TD), __('Predictions', PW_TD), 'edit_posts', $this->prefix.'prediction' , array($this, 'prediction'));
		
		$this->admin_pages['options'] = add_options_page( __( 'Predict When Settings', PW_TD), __( 'Predict When', PW_TD), 'edit_posts', $this->prefix.'options', array( &$this, 'options' ) );
		
		// No help in preview iframe
		if (!isset( $_GET['iframe'])) {
			foreach ($this->admin_pages as $admin_page) {
				if ($admin_page) {
					add_action( 'load-' . $admin_page, array($this, 'add_help') );
				}
			}
		}
		
	}
	
	/**
	 * Load up CSS and Javascript
	 */
	function admin_enqueue_scripts() {
		
		if (isset($_GET['iframe'])) {
			wp_enqueue_style($this->prefix.'css', plugins_url('css/style.css', dirname(__FILE__)));
		}
		wp_enqueue_script($this->prefix.'admin_js', plugins_url('/js/admin.js', dirname(__FILE__)), array( 'jquery', 'jquery-ui-datepicker', 'farbtastic', 'jquery-ui-dialog', 'jquery-ui-tabs'));
		wp_enqueue_script($this->prefix.'monthpicker_js', plugins_url('/js/jquery.ui.monthpicker.js', dirname(__FILE__)), array( 'jquery', 'jquery-ui-datepicker', 'farbtastic', 'jquery-ui-dialog', 'jquery-ui-tabs'));
		wp_enqueue_style($this->prefix.'admin-style', plugins_url('/css/admin-style.css', dirname(__FILE__)));
		wp_enqueue_style($this->prefix.'jquery-ui-css', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/themes/smoothness/jquery-ui.css');
		add_thickbox();
		wp_enqueue_style('thickbox');
		wp_admin_css( 'css/farbtastic' );
	}
	
	/**
	* Init admin functions
	*
	* Register settings
	* Add button to post editor
	*
	*/
	function admin_init() {
		global $current_user;
	
		register_setting( $this->prefix.'option-group', $this->prefix.'color_chart_background');
		register_setting( $this->prefix.'option-group', $this->prefix.'color_chart_bars');
		register_setting( $this->prefix.'option-group', $this->prefix.'color_predicted_date');
		register_setting( $this->prefix.'option-group', $this->prefix.'color_predicted_user');
		register_setting( $this->prefix.'option-group', $this->prefix.'color_event_date');
		register_setting( $this->prefix.'option-group', $this->prefix.'font_chart');
		register_setting( $this->prefix.'option-group', $this->prefix.'font_size_chart');
		register_setting( $this->prefix.'option-group', $this->prefix.'font_color_chart');
		register_setting( $this->prefix.'option-group', $this->prefix.'hide_grid_chart');
		
		register_setting( $this->prefix.'option-group', $this->prefix.'sharing');
		register_setting( $this->prefix.'option-group', $this->prefix.'embed');
		
		register_setting( $this->prefix.'option-group', $this->prefix.'approve_subject');
		register_setting( $this->prefix.'option-group', $this->prefix.'approve_body');
		register_setting( $this->prefix.'option-group', $this->prefix.'reject_subject');
		register_setting( $this->prefix.'option-group', $this->prefix.'reject_body');
		
		if ($current_user && $current_user->rich_editing) {
			// add JS to add button to editor on admin pages that might have an editor
			$pages_with_editor_button = array( 'post.php', 'post-new.php', 'page.php', 'page-new.php' );
			foreach ( $pages_with_editor_button as $page ) {
				add_action( 'load-' . $page, array( &$this, 'add_editor_button' ) );
			}
		}
	
		if (isset($_GET['generate'])) {
			require_once(dirname(__FILE__).'/questions.php');
			require_once(dirname(__FILE__).'/predictions.php');
			require_once(dirname(__FILE__).'/generate.php');
			$g = new PredictWhenGenerate();
			$g->generate_data($_GET['generate']);
		}
	}
	
	/**
	 * Hide admin menus in Thickbox IFrame
	 * 
	 * @param unknown_type $class
	 * @return string
	 */
	function admin_body_class( $class ) {
		if ( isset( $_GET['iframe'] ) ) {
			$class .= $this->prefix.'question-preview-iframe ';
		}
		return $class;
	}
	
	/**
	 * Display the Predict When admin menu
	 */
	function menu() {
		
		require_once(dirname(__FILE__).'/questions.php');
		
		$question = new PredictWhenQuestion();
		
		$question->question();
		
	}
	
	/**
	 * Shortcut to create a new question
	 */
	function add_question() {
		require_once(dirname(__FILE__).'/questions.php');
		
		$question = new PredictWhenQuestion();
		
		$question->question(true);
	}

	/**
	* Display the predictions admin menu
	*/
	function prediction() {
	
		require_once(dirname(__FILE__).'/questions.php');
		require_once(dirname(__FILE__).'/predictions.php');
	
		$prediction = new PredictWhenPrediction();
	
		$prediction->prediction();
	
	}
	
	/**
	 * Add help pages for each PredictWhen menu option
	 */
	function add_help() {
		$screen = get_current_screen();
		
		if ($screen->id == $this->admin_pages['top'] || $screen->id == $this->admin_pages['questions'] ||
			$screen->id == $this->admin_pages['add-question'] || $screen->id == $this->admin_pages['predictions']) {
					
			$screen->add_help_tab( array(
					        'id'      => 'pw-questions-help',
					        'title'   => __('Questions', PW_TD),
					        'content' => 
									'<p>' . __('This screen provides access to all of your questions. Click on each of the headings to sort, and use the filter to restrict the list.', PW_TD) . '</p>' .
								    '<p>' . __('The status of a question may be one of Open, Closed or Pending', PW_TD) . '</p>' .
								    '<ul>' .
								    '<li>' . __('Open - The question is available for users to make a prediction.', PW_TD) . '</li>' .
								    '<li>' . __('Closed - A closed question is no longer available for predictions. When closing a question an event date can be entered to indicate when the event occurred. This date is shown below the status when hovering over the question.', PW_TD) . '</li>' .
									'<li>' . __('Pending - The question has been submitted by a user and must be approved or rejected. Hover over the question and click Approve or Reject.', PW_TD) . '</li>' .
									'</ul>' .
								    '<p>' . __('Questions that are included in the PredictWhen.com directory will have a tick in the Publish column.', PW_TD) . '</p>' .
									'<p>' . __('If there are enough predictions for the plugin to calculate when the event is likely to happen this date is shown in the Predicted Date column.', PW_TD) . '</p>'
								) );
			$screen->add_help_tab( array(
					        'id'      => 'pw-add-question-help',
					        'title'   => __('Add Question', PW_TD),
					        'content' => 
									'<p>' . __('Create a new question by clicking Add New in the list of questions or the Add New Question option.', PW_TD) . '</p>' .
								    '<p>' . __('Enter the title of the question.  This title is shown above the chart.', PW_TD) . '</p>' .
								    '<p>' . __('All the other fields are optional.', PW_TD) . '</p>' .
								    '<p>' . __('We would recommend that you include your question in the PredictWhen directory to give your question more exposure and a link back to your blog.', PW_TD) . '</p>' .
								    '<p>' . __('Once saved, hover over the row in the list of questions and click Embed to create a new draft post to display the question and chart. Alternatively edit an existing page or post and add the shortcode to display a chart. See Displaying Charts for more information.', PW_TD) . '</p>'
			
								) );
			$screen->add_help_tab( array(
					        'id'      => 'pw-predictions-help',
					        'title'   => __('Predictions', PW_TD),
					        'content' => 
									'<p>' . __('This screen provides access to all the predictions. Select a question from the drop down list to view all the predictions.', PW_TD) . '</p>' .
								    '<p>' . __('Questions requiring registration will display in addition to the predicted date and the date the prediction was made, the user who made the prediction and an accuracy score once the question is closed.', PW_TD) . '</p>' .
									'<p>' . __('Predictions are usually made from a blog post, but you may create a new prediction, modify an existing prediction or even delete a prediction.', PW_TD) . '</p>'
								) );
			$screen->add_help_tab( array(
					        'id'      => 'pw-shortcode-help',
					        'title'   => __('Displaying charts', PW_TD),
					        'content' => 
									'<p>' . __('To invite predictions for a question and display a chart, use the following shortcode in a post or page.', PW_TD) . '</p>' .
								    '<p>' . __('<code>[predictwhen id=x]</code> where <code>x</code> is the ID of the question shown in the list.', PW_TD) . '</p>' .
									'<p>' . __('By using the shortcode attribute <code>hide_chart=1</code> you can elect to hide the chart and only display the question and invite predictions. For example <code>[predictwhen id=x hide_chart=1]</code>', PW_TD) . '</p>'
								) );
			$screen->add_help_tab( array(
					        'id'      => 'pw-embeding-help',
					        'title'   => __('Advanced', PW_TD),
					        'content' =>
									'<p>' . __('By using the following shortcodes you may invite registered users to submit a question for approval, or display a table for a closed question listing the scores awarded to users for the accuracy of their predictions. Note, scoring is only enabled for questions requiring registration.', PW_TD) . '</p>' .
								    '<p>' . __('<code>[predictwhen user_question=1]</code> - This shortcode when placed in a page or post will present a form allowing a registered blog user to submit a question.  The administrator must approve the question before it is displayed.', PW_TD) . '</p>' .
									'<p>' . __('<code>[predictwhen id=x scoring=1]</code> - Displays a ranking table of users scores for question where <code>x</code> is the ID of the question shown in the list. You may also use the following shortcode attributes to control the output of the table:', PW_TD) . '</p>' .
								    '<ul>' .
								    '<li>' . __('show_prediction - Display the predicted date.', PW_TD) . '</li>' .
								    '<li>' . __('show_when - Display the date the prediction was made.', PW_TD) . '</li>' .
									'<li>' . __('limit - Maximum number of rows to display.', PW_TD) . '</li>' .
									'</ul>' .
									'<p>' . __('For example, <code>[predictwhen id=x scoring=1 show_prediction=1 limit=10]</code> will display the names of the top 10 scorers and their prediction.', PW_TD) . '</p>'
			) );
		
			if ($screen->id == $this->admin_pages['predictions']) {
				add_screen_option( 'per_page', array('label' => __('Per page', PW_TD), 'default' => 20, 'option' => $this->prefix.'predictions_per_page') );
			} else {
				add_screen_option( 'per_page', array('label' => __('Per page', PW_TD), 'default' => 20, 'option' => $this->prefix.'questions_per_page') );
			}
		}
		
		if ($screen->id == $this->admin_pages['options']) {
			$screen->add_help_tab( array(
								        'id'      => 'pw-option-help',
								        'title'   => __('Customize Chart', PW_TD),
								        'content' => 
								        	'<p>' . __('Using the options below you can customize the appearance of charts displayed on your blog.', PW_TD) . '</p>' .
								        	'<p>' . __('Click on each of the color boxes and use the color wheel to set the required colors.', PW_TD) . '</p>'
										) );
			$screen->add_help_tab( array(
								        'id'      => 'pw-option-approve-help',
								        'title'   => __('Approval Email', PW_TD),
								        'content' => 
								        	'<p>' . __('When a registered user submits their own question it must be approved by an administrator.', PW_TD) . '</p>' .
								        	'<p>' . __('Modify the email template below to customize the email sent to the user when a question is approved.', PW_TD) . '</p>' .
											'<p>' . __('You may use the substitution codes shown below in the subject or email body. HTML is allowed.', PW_TD) . '</p>'
										) );
			$screen->add_help_tab( array(
								        'id'      => 'pw-option-reject-help',
								        'title'   => __('Rejection Email', PW_TD),
								        'content' => 
								        	'<p>' . __('When a registered user submits their own question it must be approved by an administrator.', PW_TD) . '</p>' .
								        	'<p>' . __('Modify the email template below to customize the email sent to the user when a question is rejected.', PW_TD) . '</p>' .
								        	'<p>' . __('As part of the rejection process you may enter a reason for rejecting the question.', PW_TD) . '</p>' .
											'<p>' . __('You may use the substitution codes shown below in the subject or email body. HTML is allowed.', PW_TD) . '</p>'
										) );
			$screen->add_help_tab( array(
								        'id'      => 'pw-option-sharing-help',
								        'title'   => __('Social Sharing', PW_TD),
								        'content' => 
											'<p>' . __('If checked a link is presented beneath the chart to share the post.', PW_TD) . '</p>' .
											'<p>' . __('Sharing is provided by the <a target="_blank" href="http://addtoany.com">Add To Any</a> service.', PW_TD) . '</p>'
										) );
		}
		
		
		$side = '<p><strong>' . __('For more information:', PW_TD) . 
					'</strong></p><p><a href="http://predictwhen.com/plugin-documentation" target="_blank">' .
					__('Plugin Documentation', PW_TD) . '</a></p>' .
					'<p><a href="http://predictwhen.com/support/" target="_blank">' .
					__('Support Forums', PW_TD) . '</a></p>';
		
		$screen->set_help_sidebar( $side );
		
	}
	
	/**
	 * Save our known screen options
	 * 
	 * @param unknown_type $status
	 * @param unknown_type $option
	 * @param unknown_type $value
	 * @return unknown|boolean
	 */
	function set_screen_option($status, $option, $value) {
		
		if ( $this->prefix . 'questions_per_page' == $option ) return $value;
		if ( $this->prefix . 'predictions_per_page' == $option ) return $value;
		
		return false;
	}
		
	
	/**
	 * Add button "Chart" to HTML editor on "Edit Post" and "Edit Pages" pages of WordPress
	 */
	function add_editor_button() {
		add_thickbox(); // we need thickbox to show the list
	
		$params = array(
	            'action' => $this->prefix . 'ajax',
	        	'page' => $this->prefix . 'page'
		);
		$ajax_url = add_query_arg( $params, admin_url( 'admin-ajax.php' ) );
		$ajax_url = wp_nonce_url( $ajax_url);
	
	
		// HTML editor integration
		wp_enqueue_script( $this->prefix . 'editor_js', plugin_dir_url($this->file) . 'js/editor.js', array( 'jquery', 'thickbox', 'media-upload' ));
		wp_localize_script( $this->prefix. 'editor_js', $this->prefix . 'editor_AJAX', array(
		  	    'caption' => __( 'Predict When', PW_TD ),
		  	    'title' => __( 'Insert a Predict When shortcode', PW_TD ),
	        	'url' => admin_url( 'admin-ajax.php' ), // Should be $ajax_url but wp_localize_script is buggy and encodes & to &amp;
	        	'prefix' => $this->prefix,
	        	'image' => plugin_dir_url($this->file) .'images/icon-grey.png',
	        	'defaults' => json_encode($this->shortcode_defaults)
		) );
	
		// TinyMCE integration
		if ( user_can_richedit() ) {
			add_filter( 'mce_external_plugins', array( &$this, 'add_tinymce_plugin' ) );
			add_filter( 'mce_buttons', array( &$this, 'add_tinymce_button' ) );
		}
	}
	
	/**
	* Add "Chart" button and separator to the TinyMCE toolbar
	*
	* @param array $buttons Current set of buttons in the TinyMCE toolbar
	* @return array Current set of buttons in the TinyMCE toolbar, including "Predict When" button
	*/
	function add_tinymce_button( $buttons ) {
		$buttons[] = '|';
		$buttons[] = $this->prefix.'button';
		return $buttons;
	}
	
	/**
	 * Register "Chart" button plugin to TinyMCE
	 *
	 * @param array $plugins Current set of registered TinyMCE plugins
	 * @return array Current set of registered TinyMCE plugins, including "Predict When" button plugin
	 */
	function add_tinymce_plugin( $plugins ) {
		$jsfile = "js/editor.js";
		$plugins[$this->prefix.'editor'] = plugin_dir_url($this->file) . 'js/editor.js';
		return $plugins;
	}
	
	
	/**
	 * Handle admin editor AJAX requests
	 */
	function ajax_editor() {
		$editor = 0;
		
		extract($_GET, EXTR_IF_EXISTS);
		
		if ($editor) {
				
			$output = $this->tinymce();
				
			die($output);
		}
	}
	
	/**
	 * Create the dialog for Thickbox to allow selection
	 * of shortcode parameters.
	 *
	 * Used by editor.js to build shortcode and insert
	 * into the editor.
	 *
	 */
	function tinymce() {
		
		require_once(dirname(__FILE__).'/questions.php');
		$question = new PredictWhenQuestion();
		
		
		$id = -1;
		extract($this->shortcode_defaults, EXTR_IF_EXISTS);
		
		$output = '';
		
		$output .=  '<div class="wrap" id="TB_predictwhen">';
		
		$output .= '<h4>';
		$output .= __('Select a question to add a chart or ranking table to a post/page');
		$output .= '</h4>';
		
		$output .= '<table>';
		
		$output .= '<tr><td>';
		$output .= __('Question:', PW_TD);
		$output .= '</td><td>' . $question->select_question('id', true, $id);
		$output .= '</td></tr>';
		
		$output .= '<tr><td>';
		$output .= __('Hide chart', PW_TD) . '<br />' . __('Only display the prediction form', PW_TD);
		$output .= '</td><td><input class="predictwhen_input" type="checkbox" value="1" name="hide_chart" id="hide_chart" />';
		$output .= '</td></tr>';
		
		$output .= '</table>';
		
		$output .= '<h4>' . __('Ranking', PW_TD) . '</h4>';
		
		$output .= '<table>';
		$output .= '<tr><td colspan="2">';
		$output .= __("Check 'Display scores' to show a ranking table of users' predictions for a closed question.", PW_TD);
		$output .= '</td></tr>';
		
		$output .= '<tr><td>';
		$output .= __('Display scores', PW_TD);
		$output .= '</td><td><input class="predictwhen_input" type="checkbox" value="1" name="scoring" id="scoring" />';
		$output .= '</td></tr>';
		
		$output .= '<tr><td>';
		$output .= __("Display users' predicted date", PW_TD);
		$output .= '</td><td><input class="predictwhen_input" type="checkbox" value="1" name="show_prediction" id="show_prediction" />';
		$output .= '</td></tr>';
		
		$output .= '<tr><td>';
		$output .= __("Display when users' prediction was made", PW_TD);
		$output .= '</td><td><input class="predictwhen_input" type="checkbox" value="1" name="show_when" id="show_when" />';
		$output .= '</td></tr>';
		
		$output .= '<tr><td>';
		$output .= __('Maximum scoring rows:', PW_TD);
		$output .= '</td><td><input class="predictwhen_input" type="text" value="" name="limit" id="limit" />';
		$output .= '</td></tr>';
		
		$output .= '<tr><td>';
		$output .= '<button id="'.$this->prefix.'insert">'.__('Insert shortcode', PW_TD).'</button>';
		$output .= '</td><td></td></tr>';
		
		$output .= '</table>';
		
		$output .= '</div>';
		
		return $output;
	}
	
	/**
	 * Action hook for save_post
	 * 
	 * @param unknown_type $post_id
	 */
	function save_post($post_id) {
		global $wpdb;
		
		if (isset($_GET[$this->prefix.'question_id']) && $post_id && !wp_is_post_revision( $post_id ) ) {
			
			$question_id = (int)$_GET[$this->prefix.'question_id'];
			if ($question_id) {
				$sql = "UPDATE {$wpdb->prefix}{$this->prefix}question
							SET post_id = %d
							WHERE question_id = %d";
				$wpdb->query($wpdb->prepare($sql, $post_id, $question_id));
				if ($this->is_published($question_id)) {
					$this->publish('update', $question_id, get_permalink($post_id));
				}
				//$this->debug(get_permalink($post_id));
			}
		}
	}
	
	/**
	* Present a selection box.
	*
	* @param $options - Array of key/value pairs or OBJECT_K output from $wpdb->get_results with member ->name
	* @param $selected - Key value of item to be pre-selected
	* @param $empty - Key and name of empty selection, , e.g. array(-1 => 'Select a thing')
	* @param $name - Name of select
	* @param $id - Id of select
	* @param $title - Tooltip
	*/
	function get_selection($options, $selected, $empty, $name, $id = '', $title = '', $class = '') {
	
		if (empty($id)) {
			$id = $name;
		}
	
		$output = '<select ' . ($title ? 'title="'.$title.'"' : '') . ($class ? 'class="'.$class.'"' : 'class="'. $this->prefix . 'input"') . ' name="'.$name.'" id="'.$id.'">';
		if (is_array($empty)) {
			foreach ($empty as $key=>$e) {
				$output .= '<option '.((string)$key == (string)$selected ? ' selected ' : '').' value = "'.$key.'">'.$e.'</option>';
			}
		}
	
		foreach ($options as $key=>$e) {
			$output .= "<option ";
			if ((string)$selected == (string)$key) {
				$output .= " selected ";
			}
				
			if (isset($e->description) && !empty($e->description)) {
				$output .= "title=\"{$e->description}\" ";
			}
				
			$output .= "value=\"$key\">";
				
			if (is_object($e)) {
				$output .= $this->unclean($e->name);
			} else {
				$output .= $this->unclean($e);
			}
			$output .= "</option>";
		}
		$output .= "</select>";
	
		return $output;
			
	}
	
	/**
	 * Plugin settings
	 */
	function options() {
		
		$style = '';
		$this->load_google_chart_api();
		
		$date_format = get_option('date_format');
		
		$preview_data = array("cols" => array(
		array("id"=>"", "label"=>__("Date", PW_TD), "pattern"=>"", "type"=>"date"),
		array("id"=>"", "label"=>__("Predictions", PW_TD), "pattern"=>"", "type"=>"number"),
		array("id"=>"", "label"=>__("Crowd", PW_TD), "pattern"=>"", "type"=>"number"),
		array("id"=>"", "label"=>__("Users", PW_TD), "pattern"=>"", "type"=>"number"),
		array("id"=>"", "label"=>__("Event", PW_TD), "pattern"=>"", "type"=>"number")
		));
		
		$temp = array();
			
		$predictions = array(0,2,0,0,3,4,2,5,7,6,10,12,11,13,16,14,11,8,5,2,0,0,1,0,5,0,2,0,0,0);
		$i = 0;
		$event_dt = '2012-06-26';
		$mean_predicted_date = '2012-06-25';
		$user_dt = '2012-06-14';
		
		for ($point = strtotime('2012-06-10'); $point <= strtotime('2012-07-08'); $point += 86400) {
		
			$str_point = strftime('%F', $point);
			
			if ($event_dt == $str_point) {
				// Bar for actual event date - always include users prediction and predicted date bars
				$temp[] = array("c" => array(
									array("v" => $this->js_date($point)), //date_i18n($date_format, $point)),
									array("v" => 0),  // Predictions
									array("v" => 0),  // Crowd prediction
									array("v" => 0),  // User prediction
									array("v" => $predictions[$i])  // Actual date
								)
				);
					
			} elseif ($str_point == $user_dt) {
				// Bar for users prediction
				$temp[] = array("c" => array(
									array("v" => $this->js_date($point)), //date_i18n($date_format, $point)),
									array("v" => 0),
									array("v" => 0),
									array("v" => $predictions[$i]),
									array("v" => 0)
								)
				);
			} elseif ($str_point == $mean_predicted_date) {
				// Bar for mean predicted date range based on predictions
				$temp[] = array("c" => array(
									array("v" => $this->js_date($point)), //date_i18n($date_format, $point)),
									array("v" => 0),
									array("v" => $predictions[$i]),
									array("v" => 0),
									array("v" => 0)
								)
				);
			} else {
				// Users predictions
				$temp[] = array("c" => array(
									array("v" => $this->js_date($point)), //date_i18n($date_format, $point)), 
									array("v" => $predictions[$i]),
									array("v" => 0),
									array("v" => 0),
									array("v" => 0)
								)
							);
			}
			$i++;
		}
		
		$preview_data['rows'] = $temp;
		$preview_data = json_encode($preview_data);
	
		$font_name = get_option($this->prefix.'font_chart');
		$font_size = get_option($this->prefix.'font_size_chart');
		$font_color = get_option($this->prefix.'font_color_chart');
		
		if (!empty($font_name)) {
			$style .= 'font-family:'.$font_name.';';
		}
		if (!empty($font_size)) {
			$style .= 'font-size:'.$font_size.'px;';
		}
		if (!empty($font_color)) {
			$style .= 'color:'.$font_color.';';
		}
		
		?>
		<div class="wrap">
		
		<div id="icon-options-general" class="icon32"><br/></div>
		<h2><?php _e('Settings', PW_TD); ?></h2>
				
		<div class="<?php echo $this->prefix; ?>input">

		<form  class="form-table" method="post" action="options.php">
		
		<?php settings_fields( $this->prefix.'option-group' );?>
		
		
		<div id="<?php echo $this->prefix; ?>tabs">
		
		
		<ul>
			<li><a href="#<?php echo $this->prefix; ?>tabs-1"><?php _e('Customize Chart', PW_TD) ?></a></li>
			<li><a href="#<?php echo $this->prefix; ?>tabs-2"><?php _e('Approval Email Template', PW_TD) ?></a></li>
			<li><a href="#<?php echo $this->prefix; ?>tabs-3"><?php _e('Rejection Email Template', PW_TD) ?></a></li>
			<li><a href="#<?php echo $this->prefix; ?>tabs-4"><?php _e('Social Sharing', PW_TD) ?></a></li>
		</ul>
		
		<div id="<?php echo $this->prefix; ?>tabs-1">
		
		<input type="hidden" value='<?php echo $preview_data; ?>' id="<?php echo $this->prefix; ?>preview_data" />
		
		<div id="<?php echo $this->prefix; ?>colorpicker"></div>

		<div style="width:500px;clear:both;float:right">
		<h3 style="display:inline;"><?php _e('Preview', PW_TD); ?></h3>
		<div style="width:500px;">
			<h2 style="<?php echo $style; ?>" class="<?php echo $this->prefix.'chart_title'; ?>"><?php _e('Chart title preview', PW_TD); ?></h2>
			<div style="width:500px" id="<?php echo $this->prefix; ?>chart_preview"></div>
		</div>
		</div>

		<h3><?php _e('Customize the chart colors', PW_TD) ?></h3>

		<table>
		
		<tr valign="top">
		<th scope="row">
			<label for="<?php echo $this->prefix; ?>color_chart_bars"><?php _e('Background color', PW_TD); ?></label>
		</th>
		<td><input type="text" class="<?php echo $this->prefix; ?>colorwell"
					id="<?php echo $this->prefix; ?>color_chart_background"
					name="<?php echo $this->prefix; ?>color_chart_background" 
					value="<?php echo get_option($this->prefix.'color_chart_background'); ?>" 
					title="<?php _e('Background color for the chart.', PW_TD); ?>" /></td>
		</tr>
		
		
		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>color_chart_bars"><?php _e('All prediction columns', PW_TD); ?></label></th>
		<td><input type="text" class="<?php echo $this->prefix; ?>colorwell"
					id="<?php echo $this->prefix; ?>color_chart_bars"
					name="<?php echo $this->prefix; ?>color_chart_bars" 
					value="<?php echo get_option($this->prefix.'color_chart_bars'); ?>" 
					title="<?php _e('Display each of the chart bars in this color', PW_TD); ?>" /></td>
		</tr>
		
		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>color_predicted_date"><?php _e('Crowd prediction column', PW_TD); ?></label></th>
		<td><input type="text" class="<?php echo $this->prefix; ?>colorwell" id="<?php echo $this->prefix; ?>color_predicted_date"
					name="<?php echo $this->prefix; ?>color_predicted_date" value="<?php echo get_option($this->prefix.'color_predicted_date'); ?>"
					title="<?php _e('Display the crowds predicted date of the event in this color', PW_TD); ?>" /></td>
		</tr>
		
		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>color_predicted_user"><?php _e('Users prediction', PW_TD); ?></label></th>
		<td><input type="text" class="<?php echo $this->prefix; ?>colorwell" id="<?php echo $this->prefix; ?>color_predicted_user"
					name="<?php echo $this->prefix; ?>color_predicted_user" value="<?php echo get_option($this->prefix.'color_predicted_user'); ?>"
					title="<?php _e('Display the users prediction in this color', PW_TD); ?>" /></td>
		</tr>
		
		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>color_event_date"><?php _e('Event date color', PW_TD); ?></label></th>
		<td><input type="text" class="<?php echo $this->prefix; ?>colorwell" id="<?php echo $this->prefix; ?>color_event_date"
					name="<?php echo $this->prefix; ?>color_event_date" value="<?php echo get_option($this->prefix.'color_event_date'); ?>"
					title="<?php _e('Display the actual event date in this color', PW_TD); ?>" /></td>
		</tr>
		
		</table>
		
		<h3><?php _e('Customize the chart title', PW_TD) ?></h3>

		<table>
		
		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>font_chart"><?php _e('Chart title font', PW_TD); ?></label></th>
		<td><input type="text" size="30" id="<?php echo $this->prefix; ?>font_chart" name="<?php echo $this->prefix; ?>font_chart"
					value="<?php echo get_option($this->prefix.'font_chart'); ?>" 
					title="<?php _e('Enter a font name for the chart title. Leave blank to use the Wordpress theme default.', PW_TD); ?>" /></td>
		</tr>
		
		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>font_size_chart"><?php _e('Chart title font size', PW_TD); ?></label></th>
		<td><input type="text" size="3" id="<?php echo $this->prefix; ?>font_size_chart" name="<?php echo $this->prefix; ?>font_size_chart"
					value="<?php echo get_option($this->prefix.'font_size_chart'); ?>" 
					title="<?php _e('Enter a font size in px for the chart title. Leave blank to use the Wordpress theme default.', PW_TD); ?>" /></td>
		</tr>
		
		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>font_color_chart"><?php _e('Chart title color', PW_TD); ?></label></th>
		<td><input type="text" class="<?php echo $this->prefix; ?>colorwell" id="<?php echo $this->prefix; ?>font_color_chart"
					name="<?php echo $this->prefix; ?>font_color_chart" value="<?php echo get_option($this->prefix.'font_color_chart'); ?>"
					title="<?php _e('Display the chart title in this color. Leave blank to use the Wordpress theme default.', PW_TD); ?>" /></td>
		</tr>
		
		</table>
		
		
		<h3><?php _e('Customize the chart grid', PW_TD) ?></h3>

		<table>

		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>hide_grid_chart"><?php _e('Hide chart grid lines', PW_TD); ?></label></th>
		<td><input type="checkbox" id="<?php echo $this->prefix; ?>hide_grid_chart" name="<?php echo $this->prefix; ?>hide_grid_chart"
					value="1" <?php echo (get_option($this->prefix.'hide_grid_chart') ? ' checked=checked' : ''); ?>" 
					title="<?php _e('Check to hide the grid lines on the chart.', PW_TD); ?>" /></td>
		</tr>

		</table>
		
		</div>
		
		<div id="<?php echo $this->prefix; ?>tabs-2">
		
		<h3><?php _e('Approval Email Template', PW_TD) ?></h3>

		<p><?php _e('When a user submitted question is approved the following email is sent.', PW_TD); ?></p>
		<p><?php _e('You can use HTML and the following substitution codes in the email subject or body.', PW_TD); ?></p>

		<ul>
		<li><samp><strong>%%sitetitle%%</strong></samp> - <?php _e('The site title of your blog', PW_TD); ?></li>
		<li><samp><strong>%%siteurl%%</strong></samp> - <?php _e('The URL of your blog', PW_TD); ?></li>
		<li><samp><strong>%%user%%</strong></samp> - <?php _e('The display name of the user who submitted the question', PW_TD); ?></li>
		<li><samp><strong>%%question%%</strong></samp> - <?php _e('The text of the submitted question', PW_TD); ?></li>
		<li><samp><strong>%%questionurl%%</strong></samp> - <?php _e('A link to the post for this question', PW_TD); ?></li>
		<li><samp><strong>%%embed%%</strong></samp> - <?php _e('A web widget for a user to embed the chart on their own site', PW_TD); ?></li>
		</ul>

		<table>

		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>approve_subject"><?php _e('Subject', PW_TD); ?></label></th>
		<td><input size="80" type="text" id="<?php echo $this->prefix; ?>approve_subject" name="<?php echo $this->prefix; ?>approve_subject"
					value="<?php echo get_option($this->prefix.'approve_subject'); ?>"
					title="<?php _e('Customize the acknowledgment email subject.', PW_TD); ?>" /></td>
		</tr>

		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>approve_body"><?php _e('Body', PW_TD); ?></label></th>
		<td><textarea cols="80" rows="20" id="<?php echo $this->prefix; ?>approve_body" name="<?php echo $this->prefix; ?>approve_body"
					title="<?php _e('Customize the approve email body.', PW_TD); ?>"
					><?php echo get_option($this->prefix.'approve_body'); ?></textarea>
		</td>
		</tr>

		</table>

		</div>

		<div id="<?php echo $this->prefix; ?>tabs-3">
		
		<h3><?php _e('Rejection Email Template', PW_TD) ?></h3>

		<p><?php _e('When a user submitted question is rejected the following email is sent.', PW_TD); ?></p>
		<p><?php _e('You can use HTML and the following substitution codes in the email subject or body.', PW_TD); ?></p>
		<ul>
		<li><samp><strong>%%sitetitle%%</strong></samp> - <?php _e('The site title of your blog', PW_TD); ?></li>
		<li><samp><strong>%%siteurl%%</strong></samp> - <?php _e('The URL of your blog', PW_TD); ?></li>
		<li><samp><strong>%%user%%</strong></samp> - <?php _e('The display name of the user who submitted the question', PW_TD); ?></li>
		<li><samp><strong>%%question%%</strong></samp> - <?php _e('The text of the submitted question', PW_TD); ?></li>
		<li><samp><strong>%%questionurl%%</strong></samp> - <?php _e('A link to the post for this question', PW_TD); ?></li>
		<li><samp><strong>%%rejectreason%%</strong></samp> - <?php _e('The rejection reason entered during the rejection process', PW_TD); ?></li>
		</ul>

		<table>

		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>reject_subject"><?php _e('Subject', PW_TD); ?></label></th>
		<td><input size="80" type="text" id="<?php echo $this->prefix; ?>reject_subject" name="<?php echo $this->prefix; ?>reject_subject"
					value="<?php echo get_option($this->prefix.'reject_subject'); ?>"
					title="<?php _e('Customize the acknowledgment email subject.', PW_TD); ?>" /></td>
		</tr>

		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>reject_body"><?php _e('Body', PW_TD); ?></label></th>
		<td><textarea cols="80" rows="20" id="<?php echo $this->prefix; ?>reject_body" name="<?php echo $this->prefix; ?>reject_body"
					title="<?php _e('Customize the rejection email body.', PW_TD); ?>"
					><?php echo get_option($this->prefix.'reject_body'); ?></textarea>
		</td>
		</tr>

		</table>

		</div>

		<div id="<?php echo $this->prefix; ?>tabs-4">
		
		<h3><?php _e('Sharing', PW_TD) ?></h3>

		<table>

		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>sharing"><?php _e('Enable social sharing link', PW_TD); ?></label></th>
		<td><input type="checkbox" id="<?php echo $this->prefix; ?>sharing" name="<?php echo $this->prefix; ?>sharing"
					value="1" <?php echo (get_option($this->prefix.'sharing') ? ' checked=checked' : ''); ?>" 
					title="<?php _e('Check to allow user to share prediction.', PW_TD); ?>" /></td>
		</tr>

		<tr valign="top">
		<th scope="row"><label for="<?php echo $this->prefix; ?>embed"><?php _e('Enable embed code link', PW_TD); ?></label></th>
		<td><input type="checkbox" id="<?php echo $this->prefix; ?>embed" name="<?php echo $this->prefix; ?>embed"
					value="1" <?php echo (get_option($this->prefix.'embed') ? ' checked=checked' : ''); ?>" 
					title="<?php _e('Check to allow users to copy an web snippet to embed this chart on another site.', PW_TD); ?>" /></td>
		</tr>

		</table>

		</div>

		</div>

		<input type="hidden" id="<?php echo $this->prefix; ?>preview_title" value="<?php _e('Chart preview title', PW_TD); ?>" />
		
		<p class="submit">
		<input class="button-primary" type="submit" class="button" value="<?php _e('Save Changes', PW_TD) ?>" />
		</p>
		</form>
		
		</div>
		
		
		</div>
				
		<?php 
	}
	
	/**
	* Build a SELECT dropdown of users
	*
	* @param $name - Form name
	* @param $empty - If true add extra 'Select...' option
	* @param $state - If specified preselect matching option
	*/
	function select_user($name, $empty = false, $user_id = '') {
		global $wpdb;
		
		$sql = "SELECT ID, CONCAT(display_name, ' - ', user_email) AS name FROM {$wpdb->users} ORDER BY display_name";
		$results = $wpdb->get_results($sql, OBJECT_K);
		
		if ($empty) {
			$empty = array(-1 => __('Choose a user...', PW_TD));
		}
	
		return $this->get_selection($results, $user_id, $empty, $name, $name);
	}
	
	function tick() {
		return '<img src="'. plugin_dir_url($this->file) . 'images/tick.png" />';
	}
	
	/**
	 * Add settings link to plugin admin page
	 * 
	 * @param array $links - plugin links
	 * @return array of links
	 */
	function plugin_action_links($links) {
		$settings_link = '<a href="/wp-admin/options-general.php?page=predictwhen_options">'.__('Settings', PW_TD).'</a>';
		$links[] = $settings_link;
		return $links;
	}
	
}
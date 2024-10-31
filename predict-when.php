<?php
/*
 Plugin Name: Predict When
 Plugin URI: http://wordpress.org/extend/plugins/predict-when/
 Description: Allow users to predict when an event will occur. Displays a chart of those predictions and calculates a date when the event is likely to occur based on the aggregate of predictions received.
 Version: 1.3
 Text Domain: predictwhen
 Domain Path: /lang/
 SVN Version: $Id$
 Author: Ian Haycox
 Author URI: http://www.ianhaycox.com
 License: GPL2
 
 Copyright Ian Haycox, 2012 (email : ian.haycox@gmail.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details. 

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * @package PredictWhen
 * @version $Id$
 * @author ian
 * Copyright Ian Haycox, 2012
 *
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

define('PW_TD', 'predictwhen');  // Text domain

class PredictWhen {

	/**
	 * @public string plugin prefix to uniquely identify
	 */
	public $prefix = 'predictwhen_';
	
	/**
	 * @public string plugin version
	 */
	public $version = '1.3';
	
	/**
	 * @public string Database schema version
	 */
	public $db_version = '101';
	
	public $file = __FILE__;

	public $shortcode_defaults = array('id' => 0,
										'hide_chart' => 0,
										'scoring' => 0,
										'show_prediction' => 0,
										'show_when' => 0,
										'limit' => 0,
										'user_question' => 0);
	
	public $default_color_chart_background = '#ffffff';
	public $default_color_chart_bars = '#fae69e';
	public $default_color_predicted_date = '#4a8756';
	public $default_color_predicted_user = '#1517d5';
	public $default_color_event_date = '#cd3c2d';
	public $default_font_chart = '';
	public $default_font_size_chart = '';
	public $default_font_color_chart = '';
	public $default_hide_grid_chart = 0;
	
	public $minimum_predictions = 10;  // Less than this and we can't calcuate an average
	public $accurate_prediction = 30;  // More than this and the calculated event date is 'accurate'
	
	public $publish_server = 'http://predictwhen.com/index.php';
	//public $publish_server = 'http://pws/index.php';
	
	private $share_js = '<script type="text/javascript" src="http://static.addtoany.com/menu/page.js"></script>';
	
	/**
	 * error handling
	 *
	 * @var boolean
	 */
	var $error = false;
	var $error_id = 0;  // Question ID that triggered an error
	
	/**
	 * message
	 *
	 * @var string
	 */
	var $message = null;
	
	/**
	 * Constructor
	 * 
	 * Setup our filters and actions
	 */
	function __construct() {

//		if ( defined('DOING_AJAX') && DOING_AJAX ) {
			
//		} else {
			add_action('init', array($this, 'init'));
			add_action('wp_enqueue_scripts', array(&$this, 'enqueue_scripts'));
			add_shortcode(PW_TD, array($this, 'shortcode'));
			add_action('wp_ajax_predictwhen_ajax', array(&$this,'ajax'));
			add_action('wp_ajax_nopriv_predictwhen_ajax', array(&$this,'ajax'));
//		}
	}
	
	/**
	 * Init action
	 * 
	 * Setup various items
	 */
	function init() {
		global $wpdb;
		
		// I18N
		load_plugin_textdomain(PW_TD, false, dirname(plugin_basename(__FILE__)) . '/lang');
		
		if (isset($_POST[$this->prefix.'submit_prediction'])) {
				
			$ret = $this->save_prediction();
				
			if ($ret) {
				
				$anchor = '';
				
				if (isset($_POST[$this->prefix.'question_id'])) {
					// Reposition page/post to top of chart
					$anchor .= '#predicted_' . $_POST[$this->prefix.'question_id'];
				}
				wp_redirect($_SERVER['HTTP_REFERER'] . $anchor);
				exit();
			}
		}
			
		if (is_user_logged_in() && isset($_POST[$this->prefix . 'addUserQuestion'])) {
			
			$predictwhen_name = '';
			$predictwhen_notes = '';
			$predictwhen_start_dt = '';
			$predictwhen_end_dt = '';
			$predictwhen_m_start_dt = '';
			$predictwhen_m_end_dt = '';
			$predictwhen_never = 0;
			$predictwhen_date_interval = 'days';
			$error = 0;
			
			extract($_POST, EXTR_IF_EXISTS);
			
			$predictwhen_name = $this->clean($predictwhen_name);
			$predictwhen_notes = $this->clean($predictwhen_notes);
			$predictwhen_start_dt = $this->clean($predictwhen_start_dt);
			$predictwhen_end_dt = $this->clean($predictwhen_end_dt);
			$predictwhen_m_start_dt = $this->clean($predictwhen_m_start_dt);
			$predictwhen_m_end_dt = $this->clean($predictwhen_m_end_dt);
			$predictwhen_never = $this->clean($predictwhen_never);
			$predictwhen_date_interval = $this->clean($predictwhen_date_interval);
				
			if (empty($predictwhen_name)) {
				//$this->setMessage(__("Question can not be empty", PW_TD), true);
				$error = 1;
			} else {
				
				if (!$this->is_YYYYMMDD($predictwhen_start_dt) || !$this->is_YYYYMMDD($predictwhen_end_dt)) {
					//$this->setMessage(__("Dates must be YYYY-MM-DD format or blank", PW_TD), true);
					$error = 2;
				}
				
				/*
				 * Check end_dt > start_dt
				 */
				if (!$error && !empty($predictwhen_start_dt) && !empty($predictwhen_end_dt)) {
					$s = strtotime($predictwhen_start_dt);
					$e = strtotime($predictwhen_end_dt);
					if ($e < $s) {
						//$this->setMessage(__("Start date must be earlier than End date", PW_TD), true);
						$error = 3;
					}
				}
				
				if ($predictwhen_date_interval == 'months') {
					if ((!empty($predictwhen_m_start_dt) && substr($predictwhen_m_start_dt, -2) != '01') ||
						(!empty($predictwhen_m_end_dt) && substr($predictwhen_m_end_dt, -2) != '01')) {
						//$this->setMessage(__("Monthly dates must be YYYY-MM-01 format", PW_TD), true);
						$error = 5;
					}
				}
			}
			
			if (!$error) {
				
				// Save 
				global $wpdb;
				
				// Shitty NULL handling - WP doesn't !
				$start_dt = 'NULL';
				$end_dt = 'NULL';
				if ($predictwhen_date_interval == 'months') {
					if (!empty($predictwhen_m_start_dt)) {
						$start_dt = "'$predictwhen_m_start_dt'";
					}
					if (!empty($predictwhen_m_end_dt)) {
						$end_dt = "'$predictwhen_m_end_dt'";
					}
				} else {
					if (!empty($predictwhen_start_dt)) {
						$start_dt = "'$predictwhen_start_dt'";
					}
					if (!empty($predictwhen_end_dt)) {
						$end_dt = "'$predictwhen_end_dt'";
					}
				}
				
				$sql = "INSERT INTO {$wpdb->prefix}{$this->prefix}question
								(name, notes, created, start_dt, end_dt, never, publish, registration_required, limit_multiple, status, date_interval)
								VALUES (%s, %s, NOW(), $start_dt, $end_dt, %d, %d, %d, %d, %s, %s)";
				
				$ret = $wpdb->query( $wpdb->prepare( $sql, $predictwhen_name, $predictwhen_notes, $predictwhen_never, 1, 1, 0, 'pending', $predictwhen_date_interval) );
				
				if ($ret == 1) {
						
					$id = $wpdb->insert_id;
						
					// Don't publish until approved
					//$this->publish('insert', $id);
					
					$this->notify_admin_approve($id, $predictwhen_name);
					
					global $current_user;
					get_currentuserinfo();
					add_user_meta($current_user->ID, $this->prefix.'question_' . $id, 'pending');
					
				} else {
					$error = 4;  // Guess at duplicate - users don't need error messages :-)
				}
			}
			
			if (!$error) {
				$url = $_SERVER['HTTP_REFERER'];
				$url = add_query_arg(array($this->prefix . 'success' => 1), $url);
				wp_redirect($url);
				exit();
			} else {
				$_POST[$this->prefix.'error'] = $error;
			}
		}
		
		// User closing question
		if (is_user_logged_in() && isset($_POST[$this->prefix . 'close_question'])) {
			$this->debug($_POST);
			$question_id = $this->clean($_POST[$this->prefix.'question_id']);
			$user = wp_get_current_user();
			$status = get_user_meta($user->ID, $this->prefix . 'question_' . $question_id, true);
			if ($status == 'accepted') {
				$event_dt = $this->clean($_POST[$this->prefix.'event_dt']);
				$event_tm = $this->clean($_POST[$this->prefix.'event_tm']);
				
				if (!empty($event_dt) && $this->is_YYYYMMDD($event_dt) && $this->is_HHMM($event_tm)) {
					
					require_once(plugin_dir_path(__FILE__) . 'admin/admin.php' );
					require_once(plugin_dir_path(__FILE__) . 'admin/questions.php' );
					$q = new PredictWhenQuestion();
					$q->close($question_id, $event_dt, $event_tm);
					$url = $_SERVER['HTTP_REFERER'];
					wp_redirect($url);
					exit();
				}
			}
		}
		
		// Handle embed code.
		if (isset($_GET['embed'])) {
			return $this->embed();
		}
	}
	
	
	/**
	* Process the embed code to display
	* a chart via JOSNP on the remote client site
	*
	*/
	function embed() {
		global $wpdb;
		
		$height = 400;
		$width = 600;
		
		header('Content-type: application/x-javascript');
	
		$id = $_GET['embed'];
		if (strval(intval($id)) != strval($id)) {
			exit();
		}
		
		// Get the chart data
		
		$sql = "SELECT question_id, post_id, name, status, event_dt,  COALESCE(start_dt, '') AS min_date, COALESCE(end_dt, '') AS max_date, date_interval
								FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		$question = $wpdb->get_row($wpdb->prepare($sql, $id));
		
		/*
		 * Bad ID so indicate to chart nothing to show
		*/
		if (!$question) exit();
			
		if (isset($_GET['height'])) {
			if (strval(intval($_GET['height'])) == strval($_GET['height'])) {
				$height = $_GET['height'];
			}
		}
		if (isset($_GET['width'])) {
			if (strval(intval($_GET['width'])) == strval($_GET['width'])) {
				$width = $_GET['width'];
			}
		}
		
		$data = $this->get_chart_data($question, false);
		unset($data['user_idx']);
		$json = json_encode($data);
		$uniq = uniqid();
		$title = $this->unclean($question->name);
		$powered_by = $this->powered_by($id, 'raw');
		
//		$page = get_bloginfo('url');
//		if (isset($_GET['url'])) {
//			$page = esc_url($_GET['url']);
//		}

		$page = get_permalink($question->post_id);
		$page = add_query_arg(array('predictwhenserver_predict' => 1), $page);
		$page .= '#predict_' . $id;
		$link = '';
		
		$ca_height = '60%';
		$ca_width = '90%';
		$display_tooltips = 'hover';
		$haxis_position = 'out';
		
		if ($this->question_open($id)) {
			// Hide legends for open questions
			$haxis_position = 'none';
			$display_tooltips = 'none';  // If hAxis title hidden disable tooltips
			$ca_height = '90%';			// No haxis labels for remove white space
			if (!empty($page)) {
				$url = parse_url($page);
				$target_site = $url['scheme'] . '://' . $url['host'];
				$link = sprintf(__('<a href="%1$s"><button type="button">Make your prediction</button></a> at <a href="%1$s">%2$s</a>', PW_TD), $page, $target_site);
			}
		}
		
		$c1 = get_option($this->prefix.'color_chart_bars', $this->default_color_chart_bars);
		$c2 = get_option($this->prefix.'color_predicted_date', $this->default_color_predicted_date);
		$c3 = get_option($this->prefix.'color_event_date', $this->default_color_event_date);
		
//		var_dump($uniq);
		
$scripts = <<< EOT
	
document.write('<h3>$title</h3>');
document.write('<div id="predictwhen{$uniq}"></div>');
document.write('<script type="text/javascript" src="https://www.google.com/jsapi"></script>');
document.write('<script type="text/javascript">');
document.write("google.load('visualization', '1.0', {'packages':['corechart']});");
document.write("google.setOnLoadCallback(predictWhenDrawChart);");
document.write('var json = \'{$json}\';');
document.write('function predictWhenDrawChart() {var data = new google.visualization.DataTable(json);');
document.write('var options = {height:$height,width:$width,chartArea: {width: "$ca_width", height: "$ca_height", top:10},hAxis: {title: null, textPosition: "$haxis_position"},tooltip: {trigger: "$display_tooltips"},colors: ["$c1", "$c2", "$c3"],isStacked:true,legend: {position:"bottom"},vAxis: {format: "#", maxValue:3, minValue:1 },bar: {groupWidth:"90%"}};');
document.write('var chart = new google.visualization.ColumnChart(document.getElementById("predictwhen{$uniq}")); chart.draw(data, options); } ');
document.write('</script>');
document.write('<p>$link');
document.write('<span style="float:right;font-size:small;clear:both">$powered_by</span></p>');

EOT;

		echo $scripts;

		exit();
	}
	
	
	/**
	 * Load up necessary scripts and styles
	 * 
	 * JS for Google charts is loaded on-the-fly via shortcode
	 */
	function enqueue_scripts() {
		wp_enqueue_style($this->prefix.'css', plugins_url('css/style.css', __FILE__));
	}
	
	
	function get_wisdom($total_predictions, $question) {
		
		if ($question->status == 'closed') {
			$wisdom  = _n('The collective wisdom of %s person predicted this would happen:',
								 'The collective wisdom of %s people predicted this would happen:', 
			$total_predictions, PW_TD);
		} else {
			$wisdom  = _n('The collective wisdom of %s person predict this will happen:',
											 'The collective wisdom of %s people predict this will happen:', 
			$total_predictions, PW_TD);
		
		}
		$wisdom = '<p class="'.$this->prefix.'wisdom">' . sprintf($wisdom, number_format_i18n($total_predictions)) . '</p>';
		
		return $wisdom;
	}
	
	/**
	 * Display a confirmation message for this saved prediction
	 * 
	 * @param unknown_type $id
	 * @param unknown_type $question
	 * @param unknown_type $already_predicted
	 * @param unknown_type $total_predictions
	 * @return string
	 */
	function confirmation($id, $question, $already_predicted, $total_predictions) {
		$output = '';
		
		$output .= '<a name="predict_'.$id.'"></a>';
		$output .= $this->get_wisdom($total_predictions, $question);
		$output .= '<p class="'.$this->prefix.'date">' . $this->show_predicted_dates($id, true, $question->date_interval)  . '</p>';
		if ($question->never) {
			$output .= '<p class="'.$this->prefix.'never">' . sprintf(__('%d%% predict this will never happen', PW_TD), $this->never_percent($id)) . '</p>';
		}

		/*
		 * Confirm prediction to make user feel warm and fuzzy
		 */
		if (!$question->out_of_range) {
			
			// Display 'Accuracy will improve...' only if further predictions allowed due to date range.
			
			$output .= '<div class="predictwhen">';
				
			if (!isset($_GET['iframe']) && $already_predicted !== false) {
				$output .= '<p class="'.$this->prefix.'confirmation">' . sprintf(__('Your prediction of "%s" has been registered.', PW_TD),
					$this->nice_date($already_predicted, '', $question->date_interval)) . '</p>';
				if ($question->registration_required && $this->can_modify($id)) {
					$page = get_permalink();
					$page = add_query_arg(array($this->prefix.'modify' => 1), $page);
					$page .= '#predict_' . $id;
					$output .= '<p class="'.$this->prefix.'confirmation">' . sprintf(__('To change your prediction <a href="%s">click here</a>, this will overwrite your original prediction', PW_TD), $page) . '</p>';
					
					//$output .= '<button id="'.$this->prefix.'submit_'.$id.'" class="'.$this->prefix.'submit" onClick="'.$this->prefix.'make_prediction('.$id.')">' . __('Modify Your Prediction', PW_TD) . '</button></p>';
				}
			}
		
			if ($total_predictions < $this->minimum_predictions) {
				if (get_option($this->prefix.'sharing')) {
					$output .= '<p class="'.$this->prefix.'confirmation">' . sprintf(__('The wisdom of crowds requires a crowd, <a class="a2a_dd" href="%s">so tell your friends</a>.', PW_TD), "http://www.addtoany.com/share_save") . '</p>';
					$output .= $this->share_js;
				} else {
					$output .= '<p class="'.$this->prefix.'confirmation">' . __('The wisdom of crowds requires a crowd, so tell your friends.', PW_TD) . '</p>';
				}
			} else {
//			if ($total_predictions >= $this->minimum_predictions && $total_predictions < $this->accurate_prediction) {
				if (get_option($this->prefix.'sharing')) {
					$output .= '<p class="'.$this->prefix.'confirmation">' . sprintf(__('Accuracy will improve the more predictions we receive so <a class="a2a_dd" href="%s">tell your friends!</a>', PW_TD), "http://www.addtoany.com/share_save") . '</p>';
					$output .= $this->share_js;
				} else {
					$output .= '<p class="'.$this->prefix.'confirmation">' . __('Accuracy will improve the more predictions we receive so tell your friends!', PW_TD) . '</p>';
				}
			}
			$output .= '</div>';
		} else {
			if (!isset($_GET['iframe']) && $already_predicted !== false) {
				$output .= '<div class="predictwhen">';
				$output .= '<p class="'.$this->prefix.'confirmation">' . sprintf(__('Your prediction of "%s" has been registered', PW_TD),
				$this->nice_date($already_predicted, '', $question->date_interval)) . '</p>';
				$output .= '</div>';
			}
		}
		
		$output .= $this->get_embedcode($id);
		
		$output .= $this->powered_by($id);
		
		return $output;
	}
	
	/**
	 * Determine if a registered user can predict again.
	 * 
	 * If the previous prediction is more than 24 hours old
	 * then OK.
	 * 
	 * @param unknown_type $id
	 */
	function can_modify($id) {
		global $wpdb;
		
		$user = wp_get_current_user();
		
		if (empty($user->ID)) {
			return false;
		}
			
		$sql = "SELECT DATEDIFF(wwhen, NOW()) FROM {$wpdb->prefix}{$this->prefix}prediction WHERE question_id = %d AND user_id = %d";
		$var = $wpdb->get_var( $wpdb->prepare($sql, $id, $user->ID));
		
		return ($var != null && $var < 0);
	}
	
	/**
	* Handle shortcode
	*
	* @param $attr - attributes
	* @return unknown_type
	*/
	function shortcode($atts) {
		global $wpdb;
		
		$ret = true;
		$output = '';
		
		extract(shortcode_atts($this->shortcode_defaults, $atts));
			
		/*
		 * Note: our shortcodes are of the form:
		*
		* [predictwhen param1=x param2=y param3=z]
		*
		*/
		if ($user_question) {
			return $this->user_question_form();
		}
		
		if (!$id) return ''; // Nothing to do
		
		// Get question
		$sql = "SELECT question_id, name, created, start_dt, end_dt, never, publish, registration_required,
						date_interval, limit_multiple, status, event_dt, IF(end_dt < NOW(), 1, 0) AS out_of_range
					FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		$question = $wpdb->get_row($wpdb->prepare($sql, $id));
		if (!$question) {
			return '';
		}
		
		/*
		 * Display ranking table
		 */
		if ($scoring) {
			return $this->display_scores($question, $show_prediction, $show_when, $limit);
		}
		
		$this->load_google_chart_api();
				
		/*
		 * Depending on settings and the state of the question (open/closed) we need
		 * to display different options.
		 */
		
		$total_predictions = $this->total_predictions($id);
		$wisdom = $this->get_wisdom($total_predictions, $question);
		
		$font_name = get_option($this->prefix.'font_chart');
		$font_size = get_option($this->prefix.'font_size_chart');
		$font_color = get_option($this->prefix.'font_color_chart');
		
		$style = '';
		if (!empty($font_name)) {
			$style .= 'font-family:'.$font_name.';';
		}
		if (!empty($font_size)) {
			$style .= 'font-size:'.$font_size.'px;';
		}
		if (!empty($font_color)) {
			$style .= 'color:'.$font_color.';';
		}
		
		$output .= '<a name="predicted_'.$id.'"></a>';
		$output .= '<h2 class="'.$this->prefix.'chart_title" style="'.$style.'">'.$this->unclean($question->name).'</h2>';
		
		if (!$hide_chart) {
			$output .= '<div style="position:relative;height:400px;align:center;" class="'.$this->prefix.'chart" '.$this->prefix.'id="'.$id.'" '.$this->prefix.'date_interval="'.$question->date_interval.'" id="'.$this->prefix.'chart_'.$id.'">
						<p style="position:absolute;top:50%;left:50%;margin-top:-33px;margin-left:-33px;"><img src="'.plugin_dir_url($this->file) . 'images/ajax-loader.gif" /></p>
						</div>';
		}
		
		if ($question->status == 'closed') {
			// Display date event occurred
			$output .= '<p class="'.$this->prefix.'wisdom">' . __('This event occurred on:') . '</p>';
			$output .= '<p class="'.$this->prefix.'date">' . $this->nice_date($question->event_dt) . '</p>';
			$output .= $wisdom;
			$output .= '<p class="'.$this->prefix.'date">' . $this->show_predicted_dates($id, true, $question->date_interval)  . '</p>';
			if ($question->never) {
				$output .= '<p class="'.$this->prefix.'never">' . sprintf(__('%d%% predicted that it would never happen', PW_TD), $this->never_percent($id)) . '</p>';
			}
			
			if (get_option($this->prefix.'sharing')) {
				$output .= '<p class="'.$this->prefix.'confirmation">' . sprintf(__('<a class="a2a_dd" href="%s">Share this question with your friends</a>', PW_TD), "http://www.addtoany.com/share_save") . '</p>';
				$output .= $this->share_js;
			}

			$output .= $this->get_embedcode($id);
				
			$output .= $this->powered_by($id);
			return $output;
		}
		
		/*
		 * Question available for voting if end date < now().
		 */
		
		/*
		 * Display on the hAxis the collective predicted date
		 * 
		 * if they have predicted or the end date range < today just show chart
		 */
		$already_predicted = $this->already_predicted($id);
		if (!isset($_GET[$this->prefix.'modify']) && (isset($_GET['iframe']) || $already_predicted !== false || $question->out_of_range)) {  // iframe is previewing
			
			return $output . $this->confirmation($id, $question, $already_predicted, $total_predictions);
			
		}
		
		/*
		 * Not yet predicted so allow an entry form hiding the collective predicted date
		 */
		$output .= '<div id="'.$this->prefix.'form_container_'.$id.'">';
		$output .= $wisdom;
		$output .= '<p class="'.$this->prefix.'date"><span class="'.$this->prefix.'obfuscated">&nbsp;</span></p>';
		if ($question->never) {
			$output .= '<p class="'.$this->prefix.'never"><span class="'.$this->prefix.'obfuscated"></span>'.__('% predict this will never happen', PW_TD) . '</p>';
		}
		
		$output .= '<a name="predict_'.$id.'"></a>';
		$output .= '<div class="predictwhen">';  // For jQuery scope
		$output .= '<p class="'.$this->prefix.'invitation">' . __('Make your prediction to reveal date', PW_TD);
		
		$display = ' style="display:none"';  // Hide entry form
		if (($this->error && $this->error_id == $id) || isset($_GET[$this->prefix.'logged_in']) || isset($_GET['predictwhenserver_predict'])) {
			
			$display = '';  // Show entry form
		} else {
			// Display 'Make prediction' button except when re-entering data
			$output .= '<button type="button" id="'.$this->prefix.'submit_'.$id.'" class="'.$this->prefix.'submit" onClick="'.$this->prefix.'make_prediction('.$id.')">' .
			__('Make Your Prediction', PW_TD) . '</button></p>';
		}
		
		if (!$question->registration_required || is_user_logged_in()) {
			// Need to hide <form> when using wp_login_form()
			$output .= '<form method="POST" action="" class="'.$this->prefix.'submit_prediction">';
		}
		$output .= '<table id="'.$this->prefix.'entry_form_'.$id.'" class="'.$this->prefix.'entry_form" '.$display.'><tr><td class="'.$this->prefix.'column">';
		$output .= '<div class="datepicker_'.$id.'"></div></td><td style="vertical-align:middle">';
		
		if ($this->error && $this->error_id == $id) {
			$output .= $this->printMessage(false);
		}
		
		$disabled = '';
		if ($question->registration_required && !is_user_logged_in()) {
				
				$redirect = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
				$redirect = add_query_arg(array($this->prefix.'logged_in' => 1), $redirect);
				$redirect .= '#predicted_' . $id;
				
				$reg_url = wp_register('', '', false);
				if (empty($reg_url)) {
					$output .= '<p>' . __('Please login to predict', PW_TD);
				} else {
					
					$reg_url = site_url('wp-login.php?action=register', 'login');
					$reg_url = add_query_arg(array('redirect_to' => $redirect), $reg_url);
					$output .= '<p>' . sprintf(__('Please login or %s to predict', PW_TD), '<a href="' . $reg_url . '">' . __('Register') . '</a>');
				}
			
				$output .= wp_login_form(array('echo' => false, 'redirect' => $redirect));
				
		} else {
			$output .= '<div id="'.$this->prefix.'hide'.$id.'">';
			$output .= '<p id="'.$this->prefix.'date_msg'.$id.'" class="'.$this->prefix.'confirmation">' . __("Select a date", PW_TD) . '</p>';
			$output .= '<p style="display:none" id="'.$this->prefix.'date_msg_selected'.$id.'" class="'.$this->prefix.'confirmation">' . __("You have selected", PW_TD) . '</p>';
			$output .= '<p id="'.$this->prefix.'date'.$id.'" class="'.$this->prefix.'confirmation '.$this->prefix.'date"></p>';
			$output .= '</div>';
			if ($question->never) {
				$output .= '<p id="'.$this->prefix.'show'.$id.'" class="'.$this->prefix.'confirmation">' . __("You have predicted this will never happen", PW_TD) . '</p>';
			}
		}
		
		$output .= '</td></tr><tr><td>';
		if ($question->never) {
			$output .= '<label for="'.$this->prefix.'never'.$id.'">'. __("or predict 'never'", PW_TD) . '</label>';
			$output .= '<input type="checkbox" name="'.$this->prefix.'never" id="'.$this->prefix.'never'.$id.'" value="1"
							title="'.__('Check to indicate that the event is never going to happen', PW_TD). '" />';				
		}
		$output .= '</td><td>';
		if (!$question->registration_required || is_user_logged_in()) {
			$output .= '<p class="'.$this->prefix.'submit"><input title="'.__('Select a date first', PW_TD).'" disabled="disabled" type="submit" value="'.__('Submit', PW_TD).'" name="'.$this->prefix.'submit_prediction" id="'.$this->prefix.'submit_prediction'.$id.'" /></p>';
		} else {
			$output .= '<p class="'.$this->prefix.'submit"><button title="'.__('Please register or log in to make your prediction', PW_TD).'" disabled="disabled" type="button">'.__('Submit', PW_TD).'</button></p>';
		}
		
		$output .= '</td></tr></table>';
		
		if (!$question->registration_required || is_user_logged_in()) {
			global $post;
			if ($post) {
				$output .= '<input type="hidden" name="'.$this->prefix.'post_id" value="'.$post->ID.'" />';
			}
			$output .= '<input type="hidden" name="'.$this->prefix.'permalink" value="'.get_permalink().'" />';
			$output .= '<input type="hidden" name="'.$this->prefix.'question_id" value="'.$id.'" />';
			$output .= '<input type="hidden" id="'.$this->prefix.'predicted_date_'.$id.'" name="'.$this->prefix.'predicted_date" value="'.date('Y-m-d').'" />';
			$output .= '</form>';
		}
		
		/*
		 * If a user created this question then allow them to close it.
		 */
		if ($question->registration_required && is_user_logged_in()) {
			$user = wp_get_current_user();
			$status = get_user_meta($user->ID, $this->prefix . 'question_' . $id, true);
			if ($status == 'accepted') {
				$output .= '<form method="POST" action="" class="'.$this->prefix.'close_question">';
				$output .= '<input type="hidden" name="'.$this->prefix.'question_id" value="'.$id.'" />';
				
				$output .= '<p class="'.$this->prefix.'invitation">' . __('Has this event happened?', PW_TD);
				$output .= '<input type="text" size="11" placeholder="YYYY-MM-DD" name="'.$this->prefix.'event_dt" value="" class="'.$this->prefix.'close_datepicker" />';
				$output .= '<input type="text" size="6" placeholder="HH:MM" name="'.$this->prefix.'event_tm" value="" />';
				$output .= '<input type="submit" name="'.$this->prefix.'close_question" value="'.__('Close', PW_TD).'" />';
				
				$output .= '</p>';
				$output .= '</form>';
				
			}
		}
		
		$output .= '</div>';  // End jQuery scope
		
		if (get_option($this->prefix.'sharing')) {
			$output .= '<p class="'.$this->prefix.'confirmation">' . sprintf(__('Accuracy will improve the more predictions we receive so <a class="a2a_dd" href="%s">tell your friends!</a>', PW_TD), "http://www.addtoany.com/share_save") . '</p>';
			$output .= $this->share_js;
		} else {
			$output .= '<p class="'.$this->prefix.'confirmation">' . __('Accuracy will improve the more predictions we receive so tell your friends!', PW_TD) . '</p>';
		}
		
		$output .= $this->get_embedcode($question->question_id);
				
		$output .= $this->powered_by($id);
		
		$output .= '</div>';  // form container n
		
		
		return $output;
	}
	
	/**
	 * Return the embed code
	 * 
	 * @param unknown_type $question_id
	 */
	function get_embedcode($question_id) {
		$output = '';
		
		if (get_option($this->prefix.'embed')) {
			wp_enqueue_script($this->prefix.'user_js', plugins_url('/js/user.js', __FILE__), array( 'jquery', 'jquery-ui-datepicker'));
		
			$script = add_query_arg(array('embed' => $question_id), get_bloginfo('wpurl'));
			$output .= '<p class="'.$this->prefix.'confirmation">' . sprintf(__('Want to feature this prediction on your site? Copy this <a href="#" id="%d" class="%s">embed code</a>', PW_TD), $question_id, $this->prefix.'embed_link') . '</p>';
			$output .= '<div class="'.PW_TD.' '.$this->prefix.'embed_code" id="'.$this->prefix.'embed_'.$question_id.'">';
			$output .= '<p class="'.$this->prefix.'confirmation">' . __('To embed this chart on your site, cut and paste the following code snippet.', PW_TD) . '</p><pre>';
			$output .= htmlentities('<script type="text/javascript" src="'.esc_url($script).'"></script>');
			$output .= '</pre></div>';
		}
		
		return $output;
	}
	
	/**
	 * Include the required Javascript files for charting
	 */
	function load_google_chart_api() {
		/*
		 * Load Google Chart API
		 *
		 * An AJAX call will populate the chart.
		 */
		static $localized = false;  // Prevent multiple 
		
		$iso639 = 'en';
		$lang = get_bloginfo('language');
		if (!empty($lang)) {
			if (strlen($lang) == 2) {
				$iso639 = $lang;
			} else {
				list($iso639, $locale) = split('-', $lang);
			}
		}
		
		wp_enqueue_script($this->prefix.'monthpicker_js', plugins_url('/js/jquery.ui.monthpicker.js', __FILE__), array('jquery', 'jquery-ui-datepicker'));
		wp_enqueue_script($this->prefix.'google-chart-api', 'https://www.google.com/jsapi', array('jquery', 'jquery-ui-datepicker'));
		wp_enqueue_script($this->prefix.'chart_js', plugins_url('/js/chart.js', __FILE__), array( $this->prefix.'google-chart-api'));
		if (!$localized) {
			wp_localize_script($this->prefix.'chart_js', 'PredictWhenAjax',
					array(  'url' => plugins_url('', dirname(__FILE__)),
									'blogUrl' => admin_url( 'admin-ajax.php' ),
									'prefix' => $this->prefix,
									'ISO639' => $iso639,				// For Google charts I18N
									'user_label' => __('Your prediction', PW_TD),
									'selected' => __('You have selected', PW_TD)) );
			$localized = true;
		}
	}
	
	/**
	 * Display a form for registered blog users to create
	 * their own question
	 * 
	 */
	function user_question_form() {
		
		$predictwhen_name = '';
		$predictwhen_notes = '';
		$predictwhen_start_dt = '';
		$predictwhen_end_dt = '';
		$predictwhen_m_start_dt = '';
		$predictwhen_m_end_dt = '';
		$predictwhen_never = 0;
		$predictwhen_error = 0;
		$predictwhen_date_interval = 'days';
		$disabled = '';
		
		extract($_POST, EXTR_IF_EXISTS);
		
		$predictwhen_name = $this->clean($predictwhen_name);
		$predictwhen_notes = $this->clean($predictwhen_notes);
		$predictwhen_start_dt = $this->clean($predictwhen_start_dt);
		$predictwhen_end_dt = $this->clean($predictwhen_end_dt);
		$predictwhen_m_start_dt = $this->clean($predictwhen_m_start_dt);
		$predictwhen_m_end_dt = $this->clean($predictwhen_m_end_dt);
		$predictwhen_never = $this->clean($predictwhen_never);
		$predictwhen_error = $this->clean($predictwhen_error);
		$predictwhen_date_interval = $this->clean($predictwhen_date_interval);
		
		$output = '';
		
		$output .= '<div class="'.$this->prefix.'user_question ' . PW_TD . '">';
		
		if (empty($_POST) && isset($_GET[$this->prefix.'success'])) {
			$output .= '<p>'.__('You question has been submitted and is waiting for approval.', PW_TD).'</p>';
			$output .= '<p>'.__('You will receive an email when the question is approved or rejected.', PW_TD).'</p>';
			$output .= '</div>';
				
			return $output;
		}
		
		if (!is_user_logged_in()) {
			$output .= '<div class="'.$this->prefix.'not_logged_in"><p>' . sprintf(__('Please <a href="%s">login</a> or <a href="%s">register</a> to create a question', PW_TD), wp_login_url( get_permalink() ), site_url('/wp-login.php?action=register&redirect_to=' . get_permalink())) . '</p></div>';
			$disabled = 'disabled="disabled"';
		} else {
			if ($predictwhen_error) {
				
				$error = $predictwhen_error;
				
				if ($error) {
					
					$output .= '<div id="'.$this->prefix.'message" class="error"><p>';
					switch ($error) {
						case 1: $output .= __("Question can not be empty", PW_TD); break;
						case 2: $output .= __("Dates must be YYYY-MM-DD format or blank", PW_TD); break;
						case 3: $output .= __("Start date must be earlier than End date", PW_TD); break;
						case 4: $output .= __("This question already exists", PW_TD); break;
						case 5: $output .= __("Dates must be YYYY-MM-01 format or blank", PW_TD); break;
					}
					$output .= "</p></div>";
				}
			}
		}
		
		wp_enqueue_script($this->prefix.'monthpicker_js', plugins_url('/js/jquery.ui.monthpicker.js', __FILE__), array( 'jquery', 'jquery-ui-datepicker', $this->prefix.'user_js'));
		wp_enqueue_script($this->prefix.'user_js', plugins_url('/js/user.js', __FILE__), array( 'jquery', 'jquery-ui-datepicker'));
		
		$output .= '<form name="user_question" action="" method="post">';
		$output .= wp_nonce_field( $this->prefix . 'user_question-form', '_wpnonce', true, false );
		$output .= '<input type="hidden" name="'.$this->prefix.'error" value="'.$predictwhen_error.'" />';
		$output .= '<table id="' . $this->prefix . 'user_question">';
		$output .= '<tbody>';
		$output .= '<tr valign="top">';
		$output .= '<th title="' . __('For example - When will man walk on Mars?', PW_TD) . '" scope="row"><label class="user_question" for="form-name">' . __( 'Question', PW_TD ) . $this->required(false) . '</label></th>';
		$output .= '<td colspan="2"><input '.$disabled.' class="user_question" type="text" id="form-name" name="'.$this->prefix.'name" value="' . $predictwhen_name . '" size="40" 
								placeholder="' . __('e.g. When will Man walk on Mars?', PW_TD) . '"
								title="' . __('e.g. When will Man walk on Mars?', PW_TD) . '" />
							<br />' . __('Keep your question short & simple. If you need to further qualify the question do so in the notes below', PW_TD) .
						'</td>
					</tr>
					<tr valign="top">
						<th title="' . __('Explanatory notes', PW_TD) . '" scope="row"><label class="notes" for="form-notes">' . __( 'Notes', PW_TD ) . '</label></th>
						<td colspan="2"><textarea '.$disabled.' class="notes" id="form-notes" name="'.$this->prefix.'notes" 
								placeholder="' . __('Optional descriptive notes for this question', PW_TD) . '"
								title="' . __('Description', PW_TD) . '" cols="40" rows="4">' . $predictwhen_notes . '</textarea>
						</td>
					</tr>
					<tr valign="top">
						<th title="' . __('', PW_TD) . '">
							<label for="never">' . __("User selects a month only", PW_TD) . '</label>
						</th>
						<td><input '.$disabled.' type="checkbox" name="'.$this->prefix.'date_interval" ' . ($predictwhen_date_interval == 'months' ? 'checked' : '') . ' id="predictwhen_date_interval" value="months"
								title="' . __('If checked users can only select a month instead of a date', PW_TD) . '" />
						</td>
						<td>
						' . __('Allows users to predict by month instead of one date. Useful for long range predictions', PW_TD) . '
						</td>
					</tr>
					<tr class="predictwhen_datehide" valign="top">
						<th title="' . __('Prevent dates earlier than those specified, e.g. 2011-08-13 for 13th August 2011. Leave blank for no limit', PW_TD) . '" scope="row">
							<label for="start_dt">' . __( 'Limit date range for predictions from', PW_TD ) . '</label>
						</th>
						<td><input '.$disabled.' class="' . $this->prefix . 'user_datepicker" type="text" name="'.$this->prefix.'start_dt" value="' . $predictwhen_start_dt . '" size="10"
							title="' . __('Prevent dates earlier than those specified, e.g. 2011-08-13 for 13th August 2011. Leave blank for no limit', PW_TD) . '" />
						</td>
						<td>
						' . __('Only allow predictions after a nominated date. Leave blank for no limit', PW_TD) . '
						</td>
					</tr>
					<tr class="predictwhen_datehide" valign="top">
						<th title="' . __('Prevent dates later than those specified, e.g. 2011-08-19 Leave blank for no limit', PW_TD) . '" scope="row">
							<label for="end_dt">' . __( 'Limit date range for predictions to', PW_TD ) . '</label>
						</th>
						<td><input '.$disabled.' class="' . $this->prefix . 'user_datepicker" type="text" name="'.$this->prefix.'end_dt" value="' . $predictwhen_end_dt . '" size="10"
							title="' . __('Prevent dates later than those specified, e.g. 2011-08-19 Leave blank for no limit', PW_TD) . '" />
						</td>
						<td>
						' . __('Only allow predictions before a nominated date. Leave blank for no limit', PW_TD) . '
						</td>
					</tr>
					<tr class="predictwhen_dateshow" valign="top">
						<th title="' . __('Prevent dates earlier than those specified, e.g. 2011-08-01 for 1st August 2011. Leave blank for no limit', PW_TD) . '" scope="row">
							<label for="m_start_dt">' . __( 'Limit date range for predictions from', PW_TD ) . '</label>
						</th>
						<td><input '.$disabled.' class="' . $this->prefix . 'user_monthpicker" type="text" name="'.$this->prefix.'m_start_dt" value="' . $predictwhen_m_start_dt . '" size="10"
							title="' . __('Prevent dates earlier than those specified, e.g. 2011-08-01 for 1st August 2011. Leave blank for no limit', PW_TD) . '" />
						</td>
						<td>
						' . __('Only allow predictions after a nominated date. Leave blank for no limit', PW_TD) . '
						</td>
					</tr>
					<tr class="predictwhen_dateshow" valign="top">
						<th title="' . __('Prevent dates later than those specified, e.g. 2011-08-01 Leave blank for no limit', PW_TD) . '" scope="row">
							<label for="m_end_dt">' . __( 'Limit date range for predictions to', PW_TD ) . '</label>
						</th>
						<td><input '.$disabled.' class="' . $this->prefix . 'user_monthpicker" type="text" name="'.$this->prefix.'m_end_dt" value="' . $predictwhen_m_end_dt . '" size="10"
							title="' . __('Prevent dates later than those specified, e.g. 2011-08-01 Leave blank for no limit', PW_TD) . '" />
						</td>
						<td>
						' . __('Only allow predictions before a nominated date. Leave blank for no limit', PW_TD) . '
						</td>
					</tr>
					<tr valign="top">
						<th title="' . __('Add an option to indicate that the event is never going to happen', PW_TD) . '">
							<label for="never">' . __("Include 'Never' option", PW_TD) . '</label>
						</th>
						<td><input '.$disabled.' type="checkbox" name="'.$this->prefix.'never" ' . ($predictwhen_never ? 'checked' : '') . ' id="never" value="1"
								title="' . __('Add an option to indicate that the event is never going to happen', PW_TD) . '" />
						</td>
						<td>
						' . __('Allows users to predict an event will never happen', PW_TD) . '
						</td>
					</tr>
					
					</tbody>
					</table>';
		if (is_user_logged_in()) {
			$output .= '<p class="submit" style="padding:0.5em 0;"><input type="submit" name="' .  $this->prefix . 'addUserQuestion" value="' . __( 'Add Question', PW_TD ) . '" class="button-primary" /></p>';
		}
		$output .= '</form>	</div>';
		
		return $output;
	}

	/**
	 * Notify the blog administrator that a new user
	 * question has been created and is waiting for approval.
	 * 
	 * @param unknown_type $question_id - Local question ID
	 */
	function notify_admin_approve($question_id, $name) {
		
		$admin_email = get_option("admin_email");
		
		$url = get_bloginfo('wpurl') . '/wp-admin/?page=predictwhen_menu&action=edit&question_id=' . $question_id;
		
		// The blogname option is escaped with esc_html on the way into the database in sanitize_option
		// we want to reverse this for the plain text arena of emails.
		$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
		
		
		$body = <<<EOT

A new user submitted question "$name" has been registered.

Edit the question <a href="$url">$name</a> to approve or reject. 

EOT;
		
		
		$headers = "From: " . $admin_email . "\r\n";
		$headers .= "MIME-Version: 1.0\r\n";
		$headers .= "Content-Type: text/html; charset=utf-8\r\n";
		
		wp_mail($admin_email, sprintf(__('[%s] Approve new question "%s"'), $blogname, $name), $body, $headers);
		
	}
	
	/**
	 * Return the percentage of never predictions
	 * 
	 * @param unknown_type $question_id
	 * @return number
	 */
	function never_percent($question_id) {
		global $wpdb;
		
		$votes = $this->total_predictions($question_id);
		if ($votes == 0) return 0;
		
		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}{$this->prefix}prediction WHERE question_id = %d AND event_date IS NULL";
		$nevers = $wpdb->get_var( $wpdb->prepare($sql, $question_id));
		if ($nevers == 0) return 0;
		
		return round(($nevers / $votes) * 100, 1);
	}
	
	/**
	 * Display scoring table for this question
	 * 
	 * @param unknown_type $question_id
	 * @return string
	 */
	function display_scores($question, $show_prediction, $show_when, $limit) {
		global $wpdb;
		
		$output = '';
		
		if (!$limit || !is_numeric($limit)) {
			$limit = 9999999;
		}
		// Quit if scoring not enabled for this question
		if (!$question->registration_required || $question->status != 'closed') return '';
		
		$sql = "SET @seq = 0, @rank = 0, @prev = 999999999";
		$wpdb->query($sql);
		
		/*
		 * Note scoring can only be enabled for registered users, so
		 * the prediction.user_id must exist in wp_user.ID 
		 */
		$sql = "SELECT rank, user, score, ID, event_date, wwhen
						FROM
							(SELECT  @seq := @seq + 1 AS seq,
								@rank := IF(@prev = score, @rank, @seq) AS rank,
								@prev := score as prev,
								user,
								score,
								ID,
								event_date,
								wwhen
							FROM
								(SELECT score, u.display_name AS user, u.ID, p.event_date, wwhen
									FROM
										{$wpdb->prefix}{$this->prefix}prediction p
									JOIN
										{$wpdb->users} u
									ON
										u.ID = p.user_id
									WHERE
										p.question_id = %d
									ORDER BY
										score DESC) x
							) y
						ORDER BY rank asc
						LIMIT $limit";
		$results = $wpdb->get_results($wpdb->prepare($sql, $question->question_id));
		
		$output .= '<table class="">';
		$output .= '<caption>' . $this->unclean($question->name) . '<br />' . $this->event_occurred($question) . '</caption>';
		$output .= '<thead>';
		$output .= '<tr>';
		$output .= '<th>' . __('Rank', PW_TD) . '</th>';
		$output .= '<th>' . __('User', PW_TD) . '</th>';
		if ($show_prediction) {
			$output .= '<th>' . __('Predicted Date', PW_TD) . '</th>';
		}
		if ($show_when) {
			$output .= '<th>' . __('Prediction Made', PW_TD) . '</th>';
		}
		$output .= '<th>' . __('Score', PW_TD) . '</th>';
		$output .= '</tr>';
		$output .= '</thead>';
		
		$output .= '<tbody>';
		
		foreach ($results as $row) {
			$output .= '<tr>';
			$output .= '<td>' . $row->rank . '</td>';
			$output .= '<td>' . $row->user . '</td>';
			if ($show_prediction) {
				$output .= '<td>' . $this->nice_date($row->event_date, '', $question->date_interval) . '</td>';
			}
			if ($show_when) {
				$output .= '<td>' . $this->nice_date($row->wwhen) . '</td>';
			}
			$output .= '<td>' . $row->score . '</td>';
			$output .= '</tr>';
		}
		$output .= '</tbody>';
		$output .= '</table>';
		
		$output .= $this->powered_by($question->question_id);
		
		return $output;
	}
	
	/**
	 * Save a submitted prediction
	 */
	function save_prediction() {
		global $wpdb;
		
		
		$predictwhen_question_id = 0;
		$predictwhen_predicted_date = '';
		$predictwhen_never = 0;
		$predictwhen_permalink = '';
		$predictwhen_post_id = 0;
		
		$score = 0;  // TODO

		extract($_POST, EXTR_IF_EXISTS);
		
		//$this->debug($_POST);
		
		if (!$predictwhen_question_id) {
			return false;
		}
		
		// Get question
		$sql = "SELECT question_id, name, created, start_dt, end_dt, never, publish, registration_required,
						limit_multiple, status, event_dt, date_interval
					FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		$question = $wpdb->get_row($wpdb->prepare($sql, $predictwhen_question_id));
		if (!$question) {
			return false;
		}
		
		// No predictions for closed questions
		if ($question->status == 'closed') {
			return false;
		}
		
		$predictwhen_predicted_date = $this->clean($predictwhen_predicted_date);
		$predictwhen_never = $this->clean($predictwhen_never);
		
		/*
		 * Validate combination of Never and Date + range if applicable
		 */
		if ($question->never && $predictwhen_never) {
			
			$predictwhen_predicted_date = 'NULL';
			$predictwhen_never = 1;
			
		} else {
			
			if (empty($predictwhen_predicted_date)) {
				$this->setMessage(__("Please select a date for this prediction", PW_TD), true, $predictwhen_question_id);
				return false;
			}
			
			if (!$this->is_YYYYMMDD($predictwhen_predicted_date)) {
				$this->setMessage(__("Invalid date format. Must be YYYY-MM-DD", PW_TD), true, $predictwhen_question_id);
				return false;
			}
			
			/*
			 * Is the event date within range
			*/
			
			if (!$this->in_date_range($question->start_dt, $question->end_dt, $predictwhen_predicted_date)) {
				$this->setMessage(sprintf(__("Date must be between %s and %s", PW_TD), 
					empty($question->start_dt) ? __('the epoch', PW_TD) : $this->nice_date($question->start_dt, '', $question->date_interval),
					empty($question->end_dt) ? __('the end of time', PW_TD) : $this->nice_date($question->end_dt, '', $question->date_interval)), true, $predictwhen_question_id);
				return false;
			}
			
			$predictwhen_predicted_date = "'$predictwhen_predicted_date'";  // Escape for SQL cos WP can't handle NULL's !!!!
			
		}
		
		/*
		 * Save prediction
		 */
		if ($question->registration_required) {
			
			$user = wp_get_current_user();
	
			if (empty($user->ID)) {
				return false;
			}
			
			$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}{$this->prefix}prediction WHERE question_id = %d AND user_id = %d";
			$count = $wpdb->get_var( $wpdb->prepare($sql, $predictwhen_question_id, $user->ID));
			if ($count) {
				$sql = "UPDATE {$wpdb->prefix}{$this->prefix}prediction
							SET event_date = $predictwhen_predicted_date ,
								ipaddress = %s,
								score = %d
							WHERE
								question_id = %d AND user_id = %d";
				$ret = $wpdb->query( $wpdb->prepare( $sql, $_SERVER['REMOTE_ADDR'], $score, $predictwhen_question_id, $user->ID,
						$_SERVER['REMOTE_ADDR'], $score ) );
				if ($ret === 0) {  // zero rows modified is OK
					$ret = true;
				}
			} else {
				$sql = "INSERT INTO {$wpdb->prefix}{$this->prefix}prediction
							(question_id, user_id, event_date, ipaddress, score)
										VALUES (%d, %d, $predictwhen_predicted_date, %s, %d)";
				$ret = $wpdb->query( $wpdb->prepare( $sql, $predictwhen_question_id, $user->ID,
						$_SERVER['REMOTE_ADDR'], $score ) );
			}
		} else {
			
			
			$sql = "INSERT INTO {$wpdb->prefix}{$this->prefix}prediction 
						(question_id, event_date, ipaddress, score)
							VALUES (%d, $predictwhen_predicted_date, %s, %d)";
			$ret = $wpdb->query( $wpdb->prepare( $sql, $predictwhen_question_id, $_SERVER['REMOTE_ADDR'], $score ) );
			
			/*
			 * Create a COOKIE to indicate has predicted
			 */
			
			if ($ret) {
				
				// Note - the cookie is not visible until the page is reloaded - so redirect later
				
				$lifetime = $question->limit_multiple;
				$ret = setcookie($this->prefix.$predictwhen_question_id, str_replace("'", '', $predictwhen_predicted_date), time()+$lifetime, '/');
			}
			
		}
		
		// Update post ID so we know the post this question is on.
		if ($ret && $predictwhen_post_id) {
			$sql = "UPDATE {$wpdb->prefix}{$this->prefix}question SET post_id = %d WHERE question_id = %d";
			
			$wpdb->query($wpdb->prepare($sql, $predictwhen_post_id, $predictwhen_question_id));
		}
		
		// Clear cached Chart data
		delete_transient($this->prefix.'data'.$predictwhen_question_id);
		delete_transient($this->prefix.'dates'.$predictwhen_question_id);
		
		if ($ret && $question->publish) {
			$this->publish('prediction_data', $predictwhen_question_id, $predictwhen_permalink);
		}
		
		return $ret;
	}
	
	/*
	 * Get row by id.
	 */
	public function get_question($question_id, $type = ARRAY_A) {
		global $wpdb;
		
		$sql = "SELECT question_id, name, created, start_dt, end_dt, never, publish, registration_required, limit_multiple, status, event_dt, event_tm, COALESCE(predicted_mean,'') AS predicted_mean, post_id, date_interval
				FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		$row = $wpdb->get_row( $wpdb->prepare($sql, $question_id) , $type);
		
		if (!is_null($row) && is_array($row)) {
			foreach ($row as $key=>$r) {
				$row[$key] = $this->unclean($r);
			}
		}
		
		return ($row ? $row : array());
	}
	
	/**
	 * Publish to remote server
	 * 
	 * @param string $action api_key, insert, update, delete, close, prediction
	 * @param unknown_type $question_id
	 */
	function publish($action, $question_id = 0, $permalink = '') {
		
		$data = array();
		$api_key = '';
		$locale = '';
		if (defined('WPLANG')) {
			$locale = WPLANG;
		}
		
		if (empty($locale)) {
			$locale = 'en_US';
		}
		
		// If we haven't got an API Key - get one.
		if ($action != 'api_key') {
			$api_key = get_option($this->prefix.'api_key');
			if (empty($api_key)) {
				$api_key = $this->publish('api_key');
				if ($api_key !== false) {
					update_option($this->prefix.'api_key', $api_key);
				}
			}
		}
		
		
		switch ($action) {
			
			case 'api_key' : $data = array('blog_url' => get_bloginfo('url'),
								'blog_description' => get_bloginfo('name'),
								'wp_lang' => $locale);
							break;
			
			case 'insert' : $data = $this->get_question($question_id);
							break;
							
			case 'close'  : // Fall through
			case 'update' : $data = $this->get_question($question_id);
							$data['num_predictions'] = $this->total_predictions($question_id);
							$data['num_never_predictions'] = $this->total_never_predictions($question_id);
							$data['url'] = $permalink;
							break;
							
			case 'delete' : $data['question_id'] = $question_id;
							break;
							
			case 'prediction_data' : $question = $this->get_question($question_id, OBJECT);
				 					if ($question) {
										$data['url'] = $permalink;
										$data['predicted_mean'] = $this->get_predicted_dates($question_id);
										$data['num_predictions'] = $this->total_predictions($question_id);
										$data['num_never_predictions'] = $this->total_never_predictions($question_id);
				 						$data['question_id'] = $question_id;
				 						$cd = $this->get_chart_data($question, false);
				 						unset($cd['user_idx']);
										$data['prediction_data'] = json_encode($cd);
				 					}
		}

		
		$data['version'] = $this->db_version;
		$data['api_key'] = $api_key;
		
		$request = array('timeout' => 20, 'body' => array($this->prefix.'action' =>$action, $this->prefix.'data' => $data));
		
		$response = wp_remote_post( $this->publish_server, $request);
		
		if( is_wp_error( $response ) ) {
			$this->setMessage(__('Failed to contact PredictWhen.com server', PW_TD), true);
			$err = $response->get_error_message();
			error_log('Failed to contact PredictWhen.com server' . print_r($err, true));
		} else {
			
			$resp = maybe_unserialize(wp_remote_retrieve_body($response));
			
			if (isset($resp['api_key'])) {
				return $resp['api_key'];
			}
			
			return true;
		}		
		
		return false;
	}
	
	function js_date($unixtime, $date_interval = 'days') {
		
		if ($date_interval == 'months') {
			return $this->nice_date(strftime('%F', $unixtime), '', $date_interval);
		}
		
		$x = date('Y,m,d', $unixtime);
		list($y,$m,$d) = explode(',', $x);
		
		$m--;
		return "Date($y,$m,$d)";
	}
	
	/**
	 * Get the data to draw this chart
	 * 
	 * @param unknown_type $question
	 * @param unknown_type $already_predicted
	 */
	function get_chart_data($question, $already_predicted) {
		global $wpdb;
		
		$id = $question->question_id;
		
		// Cached chart data ?
		$data = false; //get_transient($this->prefix.'data'.$id);
		if ($data !== false) {
			return $data;
		}
		
		$date_format = get_option('date_format');
		$total_predictions = $this->total_predictions($id);
		
		/*
		 * 
		 *  Create our data in this format
		 *  
		 *  cols Date is the predicted date, Predictions is the bar for num predictions
		 *  
		 *  rows - Pairs of data points
		 *  
		 *  	$data2 = array(
				    "cols" => array(
						array("id"=>"", "label"=>"Date", "pattern"=>"", "type"=>"string"),
						array("id"=>"", "label"=>"Predictions", "pattern"=>"", "type"=>"number")
						),
				    "rows" => array(
						array("c" => array(array('v' => '2012-04-28'), array('v' => 2))),
						array("c" => array(array('v' => '2012-05-01'), array('v' => 6))),
						array("c" => array(array('v' => '2012-05-02'), array('v' => 1)))
					)
				);
		 */
		
		
/*		$data2 = array(
						    "cols" => array(
		array("id"=>"", "label"=>"Date", "pattern"=>"", "type"=>"date"),
		array("id"=>"", "label"=>"Predictions", "pattern"=>"", "type"=>"number")
		),
						    "rows" => array(
		array("c" => array(array('v' => 'Date(2012,4,28)'), array('v' => 0))),
		array("c" => array(array('v' => 'Date(2012,5,1)'), array('v' => 6))),
		array("c" => array(array('v' => 'Date(2013,5,2)'), array('v' => 1))),
		array("c" => array(array('v' => 'Date(2013,5,2)'), array('v' => 1))),
		array("c" => array(array('v' => 'Date(2014,5,2)'), array('v' => 0)))
		)
		);
		
		return $data2;
*/		
		
		// Good to go. Get predictions
		//
		// Each voting bar (Predictions, You, Question) are stacked, so we only show
		// one of the bars and zero the predictions for the other two.
		//
		
		$date_interval = $question->date_interval;  // days - continuous axis, months - discrete axis
		
		$data = array("cols" => array(
			array("id"=>"dt", "label"=>__("Date", PW_TD), "pattern"=>"", "type"=> ($date_interval == 'days' ? "date" : "string")),
			array("id"=>"pr", "label"=>__("Predictions", PW_TD), "pattern"=>"", "type"=>"number"),
		));
		
		if (true || $question->status == 'closed' || $total_predictions >= $this->minimum_predictions) {
			$data['cols'][] = array("id"=>"cp", "label"=>__("Crowd prediction", PW_TD), "pattern"=>"", "type"=>"number");
		}
		
		if ($question->status == 'closed') {
			// Event date when closed
			$data['cols'][] = array("id"=>"ev", "label"=>__("Event", PW_TD), "pattern"=>"", "type"=>"number");
		}
		
		$sql = "SELECT event_date, COUNT(*) AS predictions FROM
					{$wpdb->prefix}{$this->prefix}prediction
				WHERE
					question_id = %d AND event_date IS NOT NULL
				GROUP BY
					event_date
				ORDER BY
					event_date";
		$results = $wpdb->get_results($wpdb->prepare($sql, $id), OBJECT_K);
		$temp = array();
		
		$predicted_mean = $this->get_predicted_dates($id);
		$user_idx = -1;
		
		//var_dump($predicted_dates);
		
		if ($results) {
		
			/*
			 * Work out the range of dates.
			 * 
			 * For closed questions we use the max/min predicted dates
			 * For open questions use the max/min allowed (if specified) or max/min predicted dates
			 */
			$sql = "SELECT MIN(event_date) AS min_prediction_date, MAX(event_date) AS max_prediction_date FROM
						{$wpdb->prefix}{$this->prefix}prediction
					WHERE
						question_id = %d AND event_date IS NOT NULL";
			$range = $wpdb->get_row($wpdb->prepare($sql, $id));
			
			if ($question->status == 'closed') {
				// Work out if the actual event date falls within range of predictions and if not adjust accordingly.
				if ($question->event_dt) {
					if ($question->event_dt < $range->min_prediction_date) {
						$range->min_prediction_date = $question->event_dt;
					}
					if ($question->event_dt > $range->max_prediction_date) {
						$range->max_prediction_date = $question->event_dt;
					}
				}
			} else {
				
				// If this question has min/max limits use them
				if (!empty($question->min_date) && $question->min_date < $range->min_prediction_date) {
					$range->min_prediction_date = $question->min_date;
				}
				if (!empty($question->max_date) && $question->max_date > $range->max_prediction_date) {
					$range->max_prediction_date = $question->max_date;
				}
			}
			
			
			
			// Iterate for each day within the range
			// NOTE - we muck about adding 86400 because MySQL returns dates via UNIX_TIMESTAMP() in local
			// time and if the sql server is in a different timezone setting to either Apache or PHP
			// then hours get added/removed so no match.  Hence the ugly conversions !
			
			if ($date_interval == 'days') {
				$lower = strtotime($range->min_prediction_date)-86400; // Day added to upper/lower to avoid half-bars on chart at extremities.
				$upper = strtotime($range->max_prediction_date)+86400;
				
				// If we have a limited number of predictions or range then the bars on the chart are
				// very wide - i.e. 1 bar takes up 80% of the chart width.
				// So - extend the range if necessary.
	
				if ($upper - $lower < 86400 * 30) {
					// Less than 1 months range - append 1/2 month each end
					$lower = $lower - (86400 * 15);
					$upper = $upper + (86400 * 15);
				}
			} else {
				$question->event_dt = substr_replace($question->event_dt, '01', -2);  // Force 1st of month
				$lower = strtotime($range->min_prediction_date); // No need to add days for discrete axis
				$upper = strtotime($range->max_prediction_date);
			}
			
			for ($point = $lower; $point <= $upper; $point += 86400) {
			
				$predictions = 0;
				$str_point = strftime('%F', $point);
				if (isset($results[$str_point])) {
					$predictions = $results[$str_point]->predictions;
				}
				
				if ($already_predicted == $str_point) {
					$user_idx = count($temp);
				}
				
				if ($question->status == 'closed') {
					
					if ($question->event_dt == $str_point) {
						// Bar for actual event date - always include users prediction and predicted date bars
						$temp[] = array("c" => array(
											array("v" => $this->js_date($point, $date_interval)), //date_i18n($date_format, $point)),
											array("v" => 0),  // Users prediction
											array("v" => 0),  // Crowd Predicted date
											array("v" => max((int)$predictions, 1))  // Actual date
										)
						);
							
					} elseif (!empty($predicted_mean) && $str_point == $predicted_mean) {
						// Bar for mean predicted date based on predictions
						$temp[] = array("c" => array(
											array("v" => $this->js_date($point, $date_interval)), //date_i18n($date_format, $point)),
											array("v" => 0),
											array("v" => max((int)$predictions, 1)),
											array("v" => 0)
										)
						);
					
					} else {
						if (($date_interval == 'months' && substr($str_point, -2) == '01') || $predictions || $point == $lower || $point == $upper) {
							// Users predictions
							$temp[] = array("c" => array(
												array("v" => $this->js_date($point, $date_interval)), //date_i18n($date_format, $point)), 
												array("v" => (int)$predictions),
												array("v" => 0),
												array("v" => 0)
											)
										);
						}
					}
				} else {
					
					if (true || $total_predictions >= $this->minimum_predictions) {
						
						if (!empty($predicted_mean) && $str_point == $predicted_mean) {
							// Bar for mean predicted date based on predictions
							$temp[] = array("c" => array(
											array("v" => $this->js_date($point, $date_interval)), //date_i18n($date_format, $point)),
											array("v" => 0),
											array("v" => max((int)$predictions, 1))
										)
									);
								
						} else {
							// Users predictions
							if (($date_interval == 'months' && substr($str_point, -2) == '01') || $predictions || $point == $lower || $point == $upper) {
								$temp[] = array("c" => array(
											array("v" => $this->js_date($point, $date_interval)), //date_i18n($date_format, $point)), 
											array("v" => (int)$predictions),
											array("v" => 0)
										)
									);
							}
						}
					} else {
						// Users predictions
						$temp[] = array("c" => array(
										array("v" => $this->js_date($point, $date_interval)),
										array("v" => (int)$predictions)
									)
								);
					}
				}
			}
		} else {
			// $results == null, No predictions at all - but may be closed so show event date
			
			if ($question->status == 'closed') {
					
					// Bar for actual event date - always include users prediction and predicted date bars
					$temp[] = array("c" => array(
									array("v" => $this->js_date(strtotime($question->event_dt), $date_interval)), //date_i18n($date_format, strtotime($question->event_dt))),
									array("v" => 0),  // Users prediction
									array("v" => 0),  // Crowd Predicted date
									array("v" => 1)  // Actual date
								)
							);
			}
		}
		
		if (!empty($temp)) {
			$data['rows'] = $temp;
		}
		$data['user_idx'] = $user_idx;
		
		//$this->debug($data);
		set_transient($this->prefix.'data'.$id, $data, 60*60*12);
		
		return $data;
	}
	
	/**
	 * Handle AJAX calls to get chart data
	 * 
	 */
	function ajax() {
		global $wpdb;
		
		
		if (isset($_POST[$this->prefix.'question_id'])) {
			$id = $_POST[$this->prefix.'question_id'];
			$date = $_POST[$this->prefix.'predicted_date'];
			
			// Get question
			$sql = "SELECT question_id, name, created, start_dt, end_dt, never, publish, registration_required,
							date_interval, limit_multiple, status, event_dt, IF(end_dt < NOW(), 1, 0) AS out_of_range
						FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
			$question = $wpdb->get_row($wpdb->prepare($sql, $id));
			if (!$question) {
				return false;
			}
			
			$ret = $this->save_prediction();
				
			$confirm = '';
			$total_predictions = $this->total_predictions($id);
			$already_predicted = $date;
			if ($ret) {
				$confirm = $this->confirmation($id, $question, $already_predicted, $total_predictions);
			} else {
				$confirm = $this->printMessage(false);
			}
			
			die(json_encode(array($this->prefix.'id' => $id, 
									$this->prefix.'date_interval' => $question->date_interval,
									$this->prefix.'msg' => $confirm,
									$this->prefix.'date' => $date, 
									$this->prefix.'ret' => $ret)));
		}
		
		if (isset($_GET['date'])) {
			
			if (isset($_GET['month'])) {
				die($this->nice_date($_GET['date'],'','months'));   // Months only
			} else {
				die($this->nice_date($_GET['date']));
			}
		}
		
		if (isset($_GET['id'])) {
			
			$id = (int)$_GET['id'];
			
			$sql = "SELECT question_id, name, status, event_dt,  COALESCE(start_dt, '') AS min_date, COALESCE(end_dt, '') AS max_date, date_interval
						FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";

			$question = $wpdb->get_row($wpdb->prepare($sql, $id));
			$already_predicted = $this->already_predicted($id);
			$total_predictions = $this->total_predictions($id);
				
			/*
			 * Bad ID so indicate to chart nothing to show
			 */
			if (!$question) die(json_encode(array('id' => 0)));
			
			$data = $this->get_chart_data($question, $already_predicted);
//			$this->debug($data);
			$user_idx = $data['user_idx'];
			unset($data['user_idx']);
			
			$hide_axis = 1;  // Hide xAxis label by default
			$title = $this->unclean($question->name);
			if ($question->status == 'closed') {
				$title .= ' ' . __('(Voting closed)', PW_TD);
				$hide_axis = 0;
			}
			
			$already_predicted_str = null;
			if ($already_predicted) {
				$hide_axis = 0; // Show axis after prediction
				
				if (!empty($already_predicted)) {
					$already_predicted_str = $already_predicted;
				}
			}
			
			$c1 = get_option($this->prefix.'color_chart_bars', $this->default_color_chart_bars);
			$c2 = get_option($this->prefix.'color_predicted_date', $this->default_color_predicted_date);
			$c3 = get_option($this->prefix.'color_event_date', $this->default_color_event_date);
			$c4 = get_option($this->prefix.'color_chart_background', $this->default_color_chart_background);
			$c6 = get_option($this->prefix.'color_predicted_user', $this->default_color_predicted_user);
			$hg = get_option($this->prefix.'hide_grid_chart', $this->default_hide_grid_chart);
				
			if ($question->status == 'open') {
				$c3 = $c6;  // Fudge event date color same as user prediction due to order of series in chart.js
			}
				
			$ret = array('id' => $id, 'title' => $title, 'color_chart_bars' => $c1,
						'color_predicted_date' => $c2,
						'color_predicted_user' => $c6,
						'color_event_date' => $c3,
						'color_chart_background' => $c4,
						'data' => $data,
						'hide_axis' => $hide_axis,
						'min_date' => $question->min_date,
						'max_date' => $question->max_date,
						'hide_grid' => $hg,
						'already_predicted' => $already_predicted_str,
						'user_idx' => $user_idx,
						'num_predictions' => (int)$total_predictions);
			
			die(json_encode($ret));
		}
		
	}
	
	/**
	 * Has the user already predicted ?
	 * 
	 * Enter description here ...
	 */
	function already_predicted($question_id) {
		global $wpdb;
		
		if ($this->registration_required($question_id)) {
			
			$user = wp_get_current_user();
			
			if (empty($user->ID)) {
				return false;
			}
			
			$sql = "SELECT COALESCE(event_date, '') FROM {$wpdb->prefix}{$this->prefix}prediction WHERE question_id = %d AND user_id = %d";
			$var = $wpdb->get_var( $wpdb->prepare($sql, $question_id, $user->ID));
			return ($var != null ? $var : false);
			
		} else {
			// Check cookie lifetime to see if unregistered user already saved an entry
			
			if (isset($_COOKIE[$this->prefix.$question_id])) {
				$dt = $_COOKIE[$this->prefix.$question_id];
				if ($dt == 'NULL') return '';
				return $dt;
			}			
		}
		
		return false;
	}
	
	/**
	 * Return total predictions for this question
	 * 
	 * @param unknown_type $question_id
	 */
	function total_predictions($question_id) {
		global $wpdb;
		
		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}{$this->prefix}prediction WHERE question_id = %d";
		
		return $wpdb->get_var( $wpdb->prepare($sql, $question_id));
	}
	
	/**
	 * Return total predictions Never for this question
	 * 
	 * @param unknown_type $question_id
	 */
	function total_never_predictions($question_id) {
		global $wpdb;
		
		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}{$this->prefix}prediction WHERE question_id = %d AND event_date IS NULL";
		
		return $wpdb->get_var( $wpdb->prepare($sql, $question_id));
	}
	
	/**
	 * Return when this event actually occurred
	 * 
	 * @param unknown_type $question - question DB row
	 */
	function event_occurred($question) {
		$wisdom = sprintf(__('This event occurred on: %s', PW_TD),
				$this->nice_date($question->event_dt));
		return $wisdom;
	}
	

	/**
	 * Based on the predictions cast, work out the collective
	 * wisdom of the predictors to calculate a guess for
	 * the event date
	 * 
	 * @param unknown_type $question_id
	 * @return string array YYYY-MM-DD format date
	 */
	function get_predicted_dates($question_id) {
		global $wpdb;
		
		$cache = get_transient($this->prefix.'dates'.$question_id);
		if ($cache !== false) {
			return $cache;
		}
		$sql = "SELECT COUNT(*) AS sum
				FROM
					{$wpdb->prefix}{$this->prefix}prediction
				WHERE
					question_id = %d AND event_date IS NOT NULL";
		$total = $wpdb->get_var($wpdb->prepare($sql, $question_id));
		
		if ($total < $this->minimum_predictions) return '';
		

		$sql = "SELECT date_interval FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		$date_interval = $wpdb->get_var($wpdb->prepare($sql, $question_id));
		
		// Geometric mean
		//
		// See http://timothychenallen.blogspot.fr/2006/03/sql-calculating-geometric-mean-geomean.html
		$sql = "SELECT FROM_DAYS(EXP(AVG(LN(TO_DAYS(event_date))))) FROM wp_predictwhen_prediction WHERE question_id = %d";
		$mean_date = $wpdb->get_var($wpdb->prepare($sql, $question_id));
		
		if (empty($mean_date)) return '';
		
		if ($date_interval == 'months') {
			$mean_date = substr_replace($mean_date, '01', -2);  // Force 1st of month
		}
		
		$sql = "UPDATE {$wpdb->prefix}{$this->prefix}question SET predicted_mean = %s WHERE question_id = %d";
		$wpdb->query($wpdb->prepare($sql, $mean_date, $question_id));
		
		set_transient($this->prefix.'dates'.$question_id, $mean_date, 60*60*12);
		
		return $mean_date;
	}
	
	function show_predicted_dates($question_id, $show_mean = true, $date_interval = 'days') {
		$predicted_mean = $this->get_predicted_dates($question_id);
		$doing_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;
		if (empty($predicted_mean)) {
			return (is_admin() && !$doing_ajax && !isset($_GET['iframe'])) ? '' : __('We need just a few more predictions to return a meaningful average', PW_TD);
		}
		
		if (!empty($predicted_mean)) {
			return $this->nice_date($predicted_mean, '', $date_interval);
		}
		
		return '';
	}
	
	/**
	 * Is this question published ?
	 * 
	 * @param unknown_type $question_id
	 * 
	 * Return True if so
	 */
	function is_published($question_id) {
		global $wpdb;
		
		$sql = "SELECT publish FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		return $wpdb->get_var( $wpdb->prepare($sql, $question_id));
	}
	
	/**
	 * Return 'Powered by PredictWhen.com'
	 * 
	 * Hyperlink if publishing to predictwhen.com directory
	 */
	function powered_by($question_id, $format='') {
		
		$publish = $this->is_published($question_id);
		
		
		$before = '<p class="'.$this->prefix.'powered_by">';
		$after = '</p>';
		
		if ($format == 'raw') {
			$before = '';
			$after = '';
		}
		
		
		$link = $link_end = '';
		if ($publish) {
			$link = '<a target="_blank" href="http://www.predictwhen.com">';
			$link_end = '</a>';
			/* Translators - the %s surrounding PredictWhen.com are used to hyperlink if specified in the options */
			return $before . sprintf(__('Powered by %sPredictWhen.com%s', PW_TD),$link, $link_end) . $after;
		}
		
		return $before . __('Plugin from PredictWhen.com', PW_TD) . $after;
	}
	
	/**
	 * Return true if registration required, else false
	 * 
	 * @param unknown_type $question_id
	 */
	function registration_required($question_id) {
		global $wpdb;
		
		$sql = "SELECT registration_required FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		return $wpdb->get_var( $wpdb->prepare($sql, $question_id));
	}
	
	/**
	 * Return true if question open, else false
	 * 
	 * @param unknown_type $question_id
	 */
	function question_open($question_id) {
		global $wpdb;
		
		$sql = "SELECT status FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		$status = $wpdb->get_var( $wpdb->prepare($sql, $question_id));
		
		return ($status == 'open');
	}
	
	/**
	 * Return true if question pending, else false
	 * 
	 * @param unknown_type $question_id
	 */
	function question_pending($question_id) {
		global $wpdb;
		
		$sql = "SELECT status FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		$status = $wpdb->get_var( $wpdb->prepare($sql, $question_id));
		
		return ($status == 'pending');
	}
	
	/**
	 * Return event date if question close, else false
	 * 
	 * @param unknown_type $question_id
	 */
	function question_closed($question_id) {
		global $wpdb;
		
		$sql = "SELECT status, event_dt FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		$row = $wpdb->get_row( $wpdb->prepare($sql, $question_id));
		if ($row->status != 'Closed') return false;
		return $row->event_dt;
	}
	
	/**
	 * Return true if scoring enabled, else false
	 * 
	 * @param unknown_type $question_id
	 */
	function scoring_enabled($question_id) {
		return $this->registration_required($question_id);
	}
	
	/**
	 * Return true if the 'Never' option is enabled, else false
	 * 
	 * @param unknown_type $question_id
	 */
	function never_enabled($question_id) {
		global $wpdb;
		
		$sql = "SELECT never FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		return $wpdb->get_var( $wpdb->prepare($sql, $question_id));
	}
	
	/**
	 * Check blank or YYYY-MM-DD format
	 * @param string $d
	 */
	function is_YYYYMMDD($d) {
		if (empty($d)) return true;
		if (ereg ("([0-9]{4})-([0-9]{2})-([0-9]{2})", $d)) {
			return !(strtotime($d) === false);
		}
		
		return false;
	}
	
	/**
	 * Indicator for required field.
	 */
	function required($echo = true) {
		$str = '<span class="description">' . ' ' . __('(required)', PW_TD) . '</span>';
		if ($echo) {
			echo $str;
		} else {
			return $str;
		}
	}
	
	/**
	 * Check blank or HH:MM format
	 * @param string $t
	 */
	function is_HHMM($t) {
		if (empty($t)) return true;
		if (ereg ("([0-2]{1})([0-9]{1}):([0-5]{1})([0-9]{1})", $t)) {
			return !(strtotime($t) === false);
		}
		
		return false;
	}
	
	/**
	 * Check if date within range
	 * 
	 * Note all three dates (YYYY-MM-DD) could be empty indicating no restriction
	 * 
	 * @param unknown_type $from - min date
	 * @param unknown_type $to - max date
	 * @param unknown_type $d - date to check
	 * @return boolean
	 */
	function in_date_range($from, $to, $d) {
		
		if (empty($d)) {
			return true;
		}
		
		if (empty($from) && empty($to)) {
			return true;
		}

		$dt = strtotime($d);
		if (!empty($from)) {
			$f = strtotime($from);
			if ($dt < $f) {
				return false;
			}
		}
		
		if (!empty($to)) {
			$t = strtotime($to);
			if ($dt > $t) {
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Return an i18n blog formated date/time
	 * 
	 * @param unknown_type $d - YYYY-MM-DD date or blank
	 * @param unknown_type $t - HH:MM time or blank
	 */
	function nice_date($d, $t = '', $date_interval = 'days') {
		
		
		if (empty($d) || $d == 'NULL') {
			$doing_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;
			if (is_admin() && !$doing_ajax) {
				return __('Unknown', PW_TD);
			}
			return __('Never', PW_TD);
		}
		$df = get_option('date_format');
		if ($date_interval == 'months') {
			// Remove day/week formats
			$df = str_replace(array(',', 'D', 'd', 'j', 'l', 'N', 'S', 'w', 'z', 'W'), '', $df);
		}
		if (!empty($t)) {
			$df .= ' ' . get_option('time_format');
			$d .= ' ' . $t;
		}
		
		return date_i18n($df, strtotime($d));
	}
	
	
	/**
	* Clean the input string of dangerous input.
	*
	* @param $str input string
	* @return cleaned string.
	*/
	function clean($str) {
		return sanitize_text_field($str);
	}
	
	/**
	 * Reverse clean() after getting from DB
	 *
	 * @param $str input string
	 * @return cleaned string.
	 */
	function unclean($str) {
		return esc_attr(stripslashes($str));
	}
	
	/**
	 * set message
	 *
	 * @param string $message
	 * @param boolean $error triggers error message if true
	 * @return none
	 */
	function setMessage( $message, $error = false, $error_id = 0 )
	{
		$type = 'success';
		if ( $error ) {
			$this->error = true;
			$this->error_id = $error_id;
			$type = 'error';
		}
		$this->message[$type] = $message;
	}
	
	
	/**
	 * return message
	 *
	 * @param none
	 * @return string
	 */
	function getMessage()
	{
		if (is_null($this->message) || (empty($this->message))) return false;
	
		if ( $this->error )
			return $this->message['error'];
		else
			return $this->message['success'];
	}
	
	
	/**
	 * print formatted message
	 *
	 * @param none
	 * @return string
	 */
	function printMessage($echo = true)
	{
		$output = '';
	
		if ($this->getMessage() === false)  return;
	
		if ( $this->error )
			$output = "<div id='{$this->prefix}message' class='error'><p>".$this->getMessage()."</p></div>";
		else
			$output = "<div id='{$this->prefix}message' class='updated fade'><p><strong>".$this->getMessage()."</strong></p></div>";
			
		if ($echo) {
			echo $output;
		}
		$this->message = null;
		return $output;
	}
	
	
	/**
	 * Debug info
	 *
	 * @param any $var variable to display
	 * @param string $str optinal output string before $var
	 */
	function debug($var, $str = '') {
		if (defined('PW_DEBUG')) {
			echo "<pre>";
			if (strstr($str, 'SELECT')) {
				$str = str_replace("\t", ' ', $str);
			}
			if ($str) echo $str . ':';
			print_r($var);
			echo "</pre>";
		}
	}
	
	
}

/*
 * Let's get predicting...
 */
$predictwhen = new PredictWhen();

if (is_admin()) {
	
	require_once(plugin_dir_path(__FILE__) . 'admin/admin.php' );
	$predictwhen_admin = new PredictWhenAdmin();
}
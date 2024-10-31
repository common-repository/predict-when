<?php
/**
 * @package PredictWhen
 * @version $Id$
 * @author ian
 * Copyright Ian Haycox, 2012
 *
 * Prediction Administration functions for the PredictWhen plugin.
 *
 */

class PredictWhenPrediction extends PredictWhenAdmin {

	var $key = 'prediction_id';	// Primary key name
	
	/*
	 * Constructor
	 */
	function __construct() {
		parent::__construct();
	}
	
	/*
	 * Display and manage predictions
	 */
	function prediction() {
		global $wpdb;
		
		$prediction_id = -1;
		$question_id = 0;
		$user_id = -1;
		$event_date = '';
		$ipaddress = $_SERVER['REMOTE_ADDR'];
		$score = 0;
		$date_interval = 'days';
		$table = new PredictWhenPrediction_Table($this);
		$editing = false;
		
		extract($_SESSION, EXTR_IF_EXISTS);
		
		//$this->debug($_POST);
		
		if (isset($_POST[$this->prefix.'modifyPredictionCancel'])) {
			check_admin_referer($this->prefix . 'prediction-form');
			$editing = false;
		}

		if (isset($_GET['action']) && $_GET['action'] == 'view') {
			if (isset($_GET['question_id'])) {
				$question_id = $_GET['question_id'];
				$_SESSION['question_id'] = $question_id;
			}
		}
		if (isset($_POST[$this->prefix.'selectQuestion'])) {
			check_admin_referer($this->prefix . 'select-question');
			extract($_POST, EXTR_IF_EXISTS);
			$_SESSION['question_id'] = $question_id;
		}
		
		if (isset($_POST[$this->prefix.'addPrediction'])) {
			check_admin_referer($this->prefix . 'prediction-form');
			
			extract($_POST, EXTR_IF_EXISTS);
			
			// Save to database
			if ($this->insert($question_id, $user_id, $event_date, $ipaddress, $score, $date_interval) !== false) {
				$user_id = null;
				$event_date = '';
				$ipaddress = $_SERVER['REMOTE_ADDR'];
				$score = 0;
				$date_interval = 'days';
			} else {
				$editing = true; // continue editing
			}
		}

		/*
		 * Actually modify the result.
		 */
		if (isset($_POST[$this->prefix.'modifyPrediction'])) {
			check_admin_referer($this->prefix . 'prediction-form');
			
			extract($_POST, EXTR_IF_EXISTS);
			
			if ($this->update($prediction_id, $question_id, $user_id, $event_date, $ipaddress, $score, $date_interval) !== false) {
				$user_id = null;
				$event_date = '';
				$ipaddress = $_SERVER['REMOTE_ADDR'];
				$score = 0;
				$date_interval = 'days';
				$prediction_id = -1;
			} else {
				$editing = true; // continue editing
			}
		}
		
		$doaction = $table->current_action();
		
		if ($doaction) {

			if ($doaction == 'add') {
				$editing = true;
			}			
			
			/*
			 * Process GET request to retreive the prediction details and pre-fill
			 * the form.
			 */
			if ($doaction == 'edit') {
				$prediction_id = $_GET['prediction_id'];
				$row = $this->get($prediction_id);
				if (empty($row)) $prediction_id = -1;	// Didn't find row. Prevent modification
				extract($row, EXTR_IF_EXISTS);
				$editing = true;
			}
			
			/* Single delete */
			if ($doaction == 'delete') {
				check_admin_referer($this->prefix . 'delete');
				if (isset($_GET['prediction_id'])) {
					$ret = $this->delete($_GET['prediction_id']);
					if ($ret) {
						delete_transient($this->prefix.'data'.$question_id);
						delete_transient($this->prefix.'dates'.$question_id);
						
						if ($this->is_published($question_id)) {
							$this->publish('prediction_data', $question_id);
						}
					}
				}
				$prediction_id = -1;
			}
			
			/* Bulk delete */
			if ($doaction == 'bulkdelete') {
				check_admin_referer('bulk-predictions');
				if (isset($_POST['prediction_id'])) {
					foreach ($_POST['prediction_id'] as $id) {
						$this->delete($id);
					}
					unset($_POST['prediction_id']);
					delete_transient($this->prefix.'data'.$question_id);
					delete_transient($this->prefix.'dates'.$question_id);
					
					if ($this->is_published($question_id)) {
						$this->publish('prediction_data', $question_id);
					}
				}
				$prediction_id = -1;
			}
		}
		
?>
		<div class="wrap">
		
		<h2 id="<?php echo $this->prefix; ?>h2-icon"><?php _e('Predictions', PW_TD); ?>
		<?php if (!$editing && $question_id != 0) { ?>
		 	<a href="?page=<?php echo $_REQUEST['page']; ?>&amp;action=add" class="add-new-h2"><?php _e('Add New', PW_TD); ?></a>
		<?php } ?>
		</h2>

		<p><?php _e('View and manage the predictions for each question', PW_TD); ?> </p>
				
		<?php $this->printMessage(); ?>
		
		<div class="<?php echo $this->prefix; ?>input" style="padding:1em;">
		
		<table>
		<tr><td>
		<form action="?page=<?php echo $_REQUEST['page']; ?>" method="post">
		<?php wp_nonce_field($this->prefix . 'select-question' ) ?>
			<?php
				$questions = new PredictWhenQuestion(); 
				echo $questions->select_question('question_id', true, $question_id, __('Select Question', PW_TD), 'question');
			?>
			<input type="hidden" name="editing" value="<?php echo $editing; ?>" />
			<input type="hidden" name="<?php echo $this->prefix;?>selectQuestion" value="1"  />
		</form>
		</td>
		<?php 
		
		if ($question_id == 0) {
			echo "</tr></table></div></div>";
			return;
		}
		
		?>		
		
		</tr>
		</table>
		
<?php 
		$q = new PredictWhenQuestion();
		$question = $q->get($question_id);

		if (!$editing) {
?>
<?php 			
			/*
			 * Show some info about the selected question
			 */

			$status = '';
			if ($question['status'] == 'open') {
				$status = __('Open', PW_TD);
			}
			if ($question['status'] == 'pending') {
				$status = __('Pending', PW_TD);
			}
			if ($question['status'] == 'closed') {
				$status = sprintf(__('Closed %s', PW_TD), $this->nice_date($question['event_dt']));
			}

?>

			<h3><?php echo $question['name'] . ' (' . $status . ')'; ?></h3>
			
			<table>
			<tr>
			<th style="text-align:left"><?php _e('Predicted Date:', PW_TD); ?>
			</th>
			<td><?php echo $this->show_predicted_dates($question_id, true, $question['date_interval']); ?>
			</td>
			</tr>
			<tr>
			<th style="text-align:left"><?php _e('Date Interval:', PW_TD); ?>
			</th>
			<td>
				<?php 
					if ($question['date_interval'] == 'months') {
						_e('Months', PW_TD);
					} else {
						_e('Days', PW_TD);
					}
			 	?>
			</td>
			</tr>
			<tr>
			<th style="text-align:left"><?php _e("Date range:", PW_TD); ?></th>
			<td><?php echo sprintf(__("Between %s and %s", PW_TD), 
				empty($question['start_dt']) ? __('the epoch', PW_TD) : $this->nice_date($question['start_dt'], '', $question['date_interval']),
				empty($question['end_dt']) ? __('the end of time', PW_TD) : $this->nice_date($question['end_dt'], '', $question['date_interval']));
				echo ' - ' . ($question['never'] ? __('Never option enabled', PW_TD) : __('Never option disabled', PW_TD));
				?>
			</td>
			</tr>

			<tr>
			<th style="text-align:left"><?php _e('PredictWhen.com:', PW_TD); ?>
			</th>
			<td><?php $question['publish'] ? _e('Included', PW_TD) : _e('Not included', PW_TD); ?>
			</td>
			</tr>

			<tr>
			<?php if ($question['registration_required']) { ?>
			
			<th style="text-align:left"><?php _e('Registration required:', PW_TD); ?></th>
			<td><?php _e('Yes', PW_TD); ?>
			</td>
			<?php } else { ?>
			
			<th><?php _e('Visitors can predict:', PW_TD); ?></th>
			<td><?php echo sprintf(__('Limit by cookie & IP - %s', PW_TD), $this->cookie_expirations[$question['limit_multiple']]); ?></td>
			<?php } ?>
			</tr>
			</table>
			<br />
<?php
			
		}

		if ($editing) {
	
		?>

		<p><?php _e('Select, or enter user details and the prediction for date.', PW_TD); ?></p>
		
		<form name="prediction" action="?page=<?php echo $_REQUEST['page']; ?>" method="post">
		
			<?php wp_nonce_field( $this->prefix . 'prediction-form' ); ?>
			
			<table class="form-table <?php echo $this->prefix; ?>form">
			
			<?php if ($this->registration_required($question_id)) { ?>
				
			<tr valign="top">
				<th title="<?php _e('Select a user from the list for this prediction', PW_TD); ?>" scope="row">
					<label for="user_id"><?php _e( 'User', PW_TD );  $this->required(); ?></label>
				</th>
				<td><?php echo $this->select_user('user_id', true, $user_id); ?></td>
			</tr>
						
			<?php } ?>
			
			<?php if ($question['date_interval'] == 'months') { ?>
			<tr valign="top">
				<th title="<?php _e('Enter the predicted event date, e.g. 2011-08-01 for 1st August 2011. Leave blank if this event is never going to happen.', PW_TD); ?>" scope="row">
					<label for="event_date"><?php _e( 'Predicted date<br /><small>YYYY-MM-01 format</small>', PW_TD ); 
						if (!$this->never_enabled($question_id)) { $this->required(); } ?></label>
				</th>
				<td><input class="<?php echo $this->prefix; ?>monthpicker" type="text" name="event_date" value="<?php echo $event_date;?>" size="11"
					title="<?php _e('Enter the predicted event date, e.g. 2011-08-01 for 1st August 2011. Leave blank if this event is never going to happen.', PW_TD); ?>" />
				</td>
			</tr>
			<?php } else { ?>
			<tr valign="top">
				<th title="<?php _e('Enter the predicted event date, e.g. 2011-08-13 for 13th August 2011. Leave blank if this event is never going to happen.', PW_TD); ?>" scope="row">
					<label for="event_date"><?php _e( 'Predicted date<br /><small>YYYY-MM-DD format</small>', PW_TD ); 
						if (!$this->never_enabled($question_id)) { $this->required(); } ?></label>
				</th>
				<td><input class="<?php echo $this->prefix; ?>datepicker" type="text" name="event_date" value="<?php echo $event_date;?>" size="11"
					title="<?php _e('Enter the predicted event date, e.g. 2011-08-13 for 13th August 2011. Leave blank if this event is never going to happen.', PW_TD); ?>" />
				</td>
			</tr>
			<?php } ?>
			
			<?php if ($this->scoring_enabled($question_id)) { ?>
			<tr title="<?php _e("Score for this user", PW_TD); ?>" valign="top">
				<th scope="row">
					<label for="score"><?php _e( 'Score', PW_TD );  ?></label>
				</th>
				<td><input type="text" id="score" name="score" value="<?php echo $score;?>" size="10" 
					 title="<?php _e("Score for this user", PW_TD); ?>" />
				</td>
			</tr>
			<?php } ?>

			<tr valign="top">
				<th title="<?php _e("Users' IP Address", PW_TD); ?>" scope="row">
					<label for="ipaddress"><?php _e( 'IP Address', PW_TD );  ?></label>
				</th>
				<td><input type="text" id="ipaddress" name="ipaddress" value="<?php echo $ipaddress;?>" size="45"
						title="<?php _e("Users' IP Address", PW_TD); ?>" />
				</td>
			</tr>
			

			</table>
			
			<input type="hidden" id="predictwhen_min_date" value="<? echo $question['start_dt']; ?>" />
			<input type="hidden" id="predictwhen_max_date" value="<? echo $question['end_dt']; ?>" />
			<input type="hidden" name="date_interval" value="<?php echo $question['date_interval']; ?>" />
			<input type="hidden" name="question_id" value="<?php echo $question_id; ?>" />
				
<?php 
			if  ($prediction_id != -1) {
?>
			<input type="hidden" value="<?php echo $prediction_id; ?>" name="prediction_id" />
			<p class="submit" style="padding:0.5em 0;"><input type="submit" name="<?php echo $this->prefix;?>modifyPrediction" value="<?php _e( 'Modify Prediction', PW_TD ); ?>" class="button-primary" />
			<input type="submit" name="<?php echo $this->prefix;?>modifyPredictionCancel" value="<?php _e( 'Cancel', PW_TD ); ?>" class="button" /></p>
<?php 
			} else {
?>
			<p class="submit" style="padding:0.5em 0;">
				<input type="submit" name="<?php echo $this->prefix;?>addPrediction" value="<?php _e( 'Add Prediction', PW_TD ); ?>" class="button-primary" />
				<input type="submit" name="<?php echo $this->prefix;?>modifyPredictionCancel" value="<?php _e( 'Cancel', PW_TD ); ?>" class="button" />
			</p>
<?php 
			}
?>
		</form>
		
<?php 
	} /* editing */
	
?>
		</div>
		<br />
		
<?php 
	
	
		//Fetch, prepare, sort, and filter our data...
		$table->prepare_items($question_id, $question['date_interval']);
?>
        <form id="list-predictions" method="post">
        	<?php $table->search_box(__('Search predictions', PW_TD), 'prediction'); ?>
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
            <?php $table->display() ?>
        </form>

		</div>
<?php
	}
		
	/**
	 * Build a SELECT dropdown of available predictions
	 * 
	 * @param $name - Form name
	 * @param $empty - If true add extra 'Select...' option
	 * @param $prediction_id - If specified preselect matching option
	 */
	function select_prediction($name, $empty = false, $prediction_id = -1) {
		global $wpdb;
		
		$sql = "SELECT prediction_id, name FROM {$wpdb->prefix}{$this->prefix}prediction ORDER BY name";
		$result = $wpdb->get_results($sql, OBJECT_K);
		
		if ($empty) {
			$empty = array(-1 => __('Choose an prediction range ...', PW_TD));
		}
		
		return $this->get_selection($result, $prediction_id, $empty, $name, 'form-'.$name);
	}
	
	/*
	 * Check valid input
	 */
	private function valid($question_id, $user_id, $event_date, $ipaddress, $score, $date_interval) {
		global $wpdb;
		
		if (!$question_id) {
			$this->setMessage(__("A question question must be selected for this prediction", PW_TD), true);
			return false;
		}
		
		/*
		 * If a registered user has not been selected then we require email/display name
		 */
		if ($this->registration_required($question_id)) {
			if ($user_id == -1) {
				$this->setMessage(__("A user must be specified for this prediction", PW_TD), true);
				return false;
			}
		}
		
		if (!$this->never_enabled($question_id)) {
			if (empty($event_date)) {
				$this->setMessage(__("An event date is required because the 'Never' option is disabled for this question", PW_TD), true);
				return false;
			}
		}
		
		
		if (!$this->is_YYYYMMDD($event_date)) {
			$this->setMessage(__("Date must be YYYY-MM-DD format or blank", PW_TD), true);
			return false;
		}
		
		if ($date_interval == 'months') {
			if ((!empty($event_date) && substr($event_date, -2) != '01')) {
				$this->setMessage(__("Monthly dates must be YYYY-MM-01 format", PW_TD), true);
				return false;
			}
		}
		
		/*
		 * Is the event date within range
		 */
		$sql = "SELECT start_dt, end_dt FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		$dates = $wpdb->get_row( $wpdb->prepare($sql, $question_id));
				
		if (!$this->in_date_range($dates->start_dt, $dates->end_dt, $event_date)) {
			$this->setMessage(sprintf(__("Date must be blank or between %s and %s", PW_TD), 
				empty($dates->start_dt) ? __('the epoch', PW_TD) : $this->nice_date($dates->start_dt, '', $date_interval),
				empty($dates->end_dt) ? __('the end of time', PW_TD) : $this->nice_date($dates->end_dt, '', $date_interval)), true);
			return false;
		}
		
		
		if (!is_numeric($score)) {
			$this->setMessage(__("Score must be numeric", PW_TD), true);
			return false;
		}
		
		
		return true;
	}
	
	/*
	 * Insert row
	 */
	protected function insert($question_id, $user_id, $event_date, $ipaddress, $score, $date_interval) {
		global $wpdb;

		$user_id = $this->clean($user_id);
		$event_date = $this->clean($event_date);
		$ipaddress = $this->clean($ipaddress);
		$score = $this->clean($score);
		$date_interval = $this->clean($date_interval);
		
		if (!$this->valid($question_id, $user_id, $event_date, $ipaddress, $score, $date_interval)) {
			return false;
		}
		
		$this->setMessage(__('Changes saved', PW_TD));
		
		if (empty($event_date)) {
			$event_date = 'NULL';
		} else {
			$event_date = "'$event_date'";
		}
		
		/*
		 * Depending on registration leave user_id as NULL
		 */
		if ($this->registration_required($question_id)) {
			$sql = "INSERT INTO {$wpdb->prefix}{$this->prefix}prediction (question_id, user_id, event_date, ipaddress, score)
					VALUES (%d, %d, $event_date, %s, %d)";
			$ret = $wpdb->query( $wpdb->prepare( $sql, $question_id, $user_id, $ipaddress, $score ) );
		} else {
			$sql = "INSERT INTO {$wpdb->prefix}{$this->prefix}prediction (question_id, event_date, ipaddress, score)
					VALUES (%d, $event_date, %s, %d)";
			$ret = $wpdb->query( $wpdb->prepare( $sql, $question_id, $ipaddress, $score ) );
		}
				
		if ($ret == 1) {
			
			$id = $wpdb->insert_id;
			
			delete_transient($this->prefix.'data'.$question_id);
			delete_transient($this->prefix.'dates'.$question_id);
				
			if ($this->is_published($question_id)) {
				$this->publish('prediction_data', $question_id);
			}
			
			return $id;
		} else {
			$this->setMessage(__('Error inserting data', PW_TD) . $wpdb->last_error, true);
			return false;
		}
	}
	
	/*
	 * Update row
	 */
	private function update($prediction_id, $question_id, $user_id, $event_date, $ipaddress, $score, $date_interval) {
		global $wpdb;
		
		$user_id = $this->clean($user_id);
		$event_date = $this->clean($event_date);
		$ipaddress = $this->clean($ipaddress);
		$score = $this->clean($score);
		$date_interval = $this->clean($date_interval);
		
		if (!$this->valid($question_id, $user_id, $event_date, $ipaddress, $score, $date_interval)) {
			return false;
		}
		
		$this->setMessage(__('Changes saved', PW_TD));
		
		if (empty($event_date)) {
			$event_date = 'NULL';
		} else {
			$event_date = "'$event_date'";
		}
				
		if ($this->registration_required($question_id)) {
			$sql = "UPDATE {$wpdb->prefix}{$this->prefix}prediction
					SET
						user_id = %d,
						event_date = $event_date,
						ipaddress = %s,
						score = %d
					WHERE prediction_id = %d";
			
			$ret = $wpdb->query( $wpdb->prepare( $sql, $user_id, $ipaddress, $score, $prediction_id ) );
		} else {
			$sql = "UPDATE {$wpdb->prefix}{$this->prefix}prediction
					SET
						user_id = NULL,
						event_date = $event_date,
						ipaddress = %s,
						score = %d
					WHERE prediction_id = %d";
			
			$ret = $wpdb->query( $wpdb->prepare( $sql, $ipaddress, $score, $prediction_id ) );
		}
		if ($ret === false) {
			$this->setMessage(__('Error updating data', PW_TD) . $wpdb->last_error, true);
			return false;
		}
		
		delete_transient($this->prefix.'data'.$question_id);
		delete_transient($this->prefix.'dates'.$question_id);
		
		if ($this->is_published($question_id)) {
			$this->publish('prediction_data', $question_id);
		}
		
		return true;
	}
	
	/*
	 * Get row by id.
	 */
	private function get($prediction_id) {
		global $wpdb;
		
		$sql = "SELECT question_id, user_id, event_date, ipaddress, score
				FROM {$wpdb->prefix}{$this->prefix}prediction WHERE prediction_id = %d";
		
		$row = $wpdb->get_row( $wpdb->prepare($sql, $prediction_id) , ARRAY_A );
		
		if (!is_null($row)) {
			foreach ($row as $key=>$r) {
				$row[$key] = $this->unclean($r);
			}
		}
		
		return ($row ? $row : array());
	}
	
	/*
	 * Delete row
	 */
	private function delete($prediction_id) {
		global $wpdb;
		
		$this->setMessage(__('Changes saved', PW_TD));
		
		$sql = "DELETE FROM {$wpdb->prefix}{$this->prefix}prediction WHERE prediction_id = %d";
		
		$ret = $wpdb->query( $wpdb->prepare( $sql, $prediction_id ) );
		if ($ret === false) {
			$this->setMessage(__('Error deleting data', PW_TD) . $wpdb->last_error);
			return false;
		}
		
		return true;
	}
	
}

/*
 * Extend WP_List_Table to manage predictions
 * 
 * See http://wordpress.org/extend/plugins/custom-list-table-example/
 * 
 */
class PredictWhenPrediction_Table extends WP_List_Table {
    
        
    var $prefix; // Plugin prefix
        
    var $key;	// Table primary key
    
    var $page_size = 10;
    
    var $caller;  // Object of caller
    
    var $question_id = -1;
    
    var $date_interval = 'days';
    
    function __construct($caller){
        global $status, $page;
                
        $this->prefix = $caller->prefix;
        
        $this->key = $caller->key;
        
        $this->page_size = $caller->page_size;
        
        $user = get_current_user_id();
        $screen = get_current_screen();
        $option = $screen->get_option('per_page', 'option');
        
        $this->page_size = get_user_meta($user, $option, true);
        
        if ( empty ( $this->page_size) || $this->page_size < 1 ) {
        
        	$this->page_size = $screen->get_option( 'per_page', 'default' );
        
        }
        
        $this->caller = $caller;
        
        //Set parent defaults
        parent::__construct( array(
            'singular'  => 'prediction',     //singular name of the listed records
            'plural'    => 'predictions',    //plural name of the listed records
            'ajax'      => false        //does this table support ajax?
        ) );
        
    }
    
    
    /** 
     * For more detailed insight into how columns are handled, take a look at 
     * WP_List_Table::single_row_columns()
     * 
     * @param array $item A singular item (one full row's worth of data)
     * @param array $column_name The name/slug of the column to be processed
     * @return string Text or HTML to be placed inside the column <td>
     **************************************************************************/
    function column_default($item, $column_name){
        switch($column_name){
            case $this->key:
                return $item[$column_name];
            default:
                return print_r($item,true); //Show the whole array for troubleshooting purposes
        }
    }
    
        
    /**
     * @see WP_List_Table::::single_row_columns()
     * @param array $item A singular item (one full row's worth of data)
     * @return string Text to be placed inside the column <td>
     **************************************************************************/
    function column_event_date($item) {
        
    	$key_value = $item[$this->key];
    	if (empty($item['event_date'])) {
    		$name = __('Never', PW_TD);
    	} else {
	    	$name = $this->caller->nice_date($item['event_date'], '', $this->date_interval);
    	}
    	
    	$edit_url = sprintf('?page=%s&action=%s&%s=%s', $_REQUEST['page'],'edit', $this->key, $key_value);
    	$delete_url = wp_nonce_url(
    				sprintf('?page=%s&action=%s&%s=%s', $_REQUEST['page'],'delete', $this->key, $key_value),
    				$this->prefix.'delete');
    	
        //Build row actions
        $actions = array(
            'edit'      => sprintf('<a href="%s" title="%s">'.__('Edit', PW_TD).'</a>', 
        						$edit_url, sprintf(__('Edit &quot;%s&quot;', PW_TD), $name)),
            'delete'    => sprintf('<a href="%s" title="%s">'.__('Delete', PW_TD).'</a>',
        						$delete_url, sprintf(__('Delete &quot;%s&quot;', PW_TD), $name)),
        );
        
        //Return the name contents
        return sprintf('<strong><a href="%s" title="%s">%s</a></strong>%s',
        	$edit_url,
        	sprintf(__('Edit &quot;%s&quot;', PW_TD), $name),
        	$name,
            $this->row_actions($actions)
        );
    }
    
    function column_wwhen($item) {
    	return $this->caller->nice_date($item['wwhen']);
    }
    
    function column_display_name($item) {
    	return '<a href="/wp-admin/user-edit.php?user_id='.$item['ID'].'" title="'.$item['user_email'].'">' . $item['display_name'] . '</a>';
    }
    
    function column_score($item) {
    	return $item['score'];
    }
    
    /**
     * Checkbox column
     * 
     * @see WP_List_Table::::single_row_columns()
     * @param array $item A singular item (one full row's worth of data)
     * @return string Text to be placed inside the column <td> (movie title only)
     **************************************************************************/
    function column_cb($item){
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            /*$1%s*/ $this->key,  //Key name
            /*$2%s*/ $item[$this->key] //The value of the checkbox should be the record's id
        );
    }
    
    
    /**
     * The 'cb' column is treated differently than the rest. If including a checkbox
     * column in your table you must create a column_cb() method. If you don't need
     * bulk actions or checkboxes, simply leave the 'cb' entry out of your array.
     * 
     * @see WP_List_Table::::single_row_columns()
     * @return array An associative array containing column information: 'slugs'=>'Visible Titles'
     **************************************************************************/
    function get_columns(){
        $columns = array(
            'cb'			=> '<input type="checkbox" />', //Render a checkbox instead of text
        	$this->key		=> __('ID', PW_TD),
        	'event_date'    => __('Predicted Date', PW_TD),
        	'wwhen'    		=> __('Prediction Made', PW_TD)
        );
        
        if ($this->caller->registration_required($this->question_id)) {
        	$columns['display_name'] = __('User', PW_TD);
        }
        
        if ($this->caller->scoring_enabled($this->question_id)) {
        	$columns['score'] = __('Score', PW_TD);
        }
        
        return $columns;
    }
    
    /**
     * This method merely defines which columns should be sortable and makes them
     * clickable - it does not handle the actual sorting. You still need to detect
     * the ORDERBY and ORDER querystring variables within prepare_items() and sort
     * your data accordingly (usually by modifying your query).
     * 
     * @return array An associative array containing all the columns that should be sortable: 'slugs'=>array('data_values',bool)
     **************************************************************************/
    function get_sortable_columns() {
        $sortable_columns = array(
        	'event_date'   	=> array('event_date', true),
        	'wwhen'   		=> array('wwhen', false)
        );
        
        if ($this->caller->registration_required($this->question_id)) {
        	$sortable_columns['display_name'] = array('display_name', false);
        }
        
        if ($this->caller->scoring_enabled($this->question_id)) {
        	$sortable_columns['score'] = array('score', false);
        }
        
        return $sortable_columns;
    }
    
    
    /**
     * Setup bulk actions
     * 
     * @return array An associative array containing all the bulk actions: 'slugs'=>'Visible Titles'
     **************************************************************************/
    function get_bulk_actions() {
        $actions = array(
            'bulkdelete'    => __('Delete', PW_TD)
        );
        return $actions;
    }
    
    
    /**
     * @uses $this->_column_headers
     * @uses $this->items
     * @uses $this->get_columns()
     * @uses $this->get_sortable_columns()
     * @uses $this->get_pagenum()
     * @uses $this->set_pagination_args()
     **************************************************************************/
    function prepare_items($question_id = 1, $date_interval = 'days') {
    	global $wpdb;
        
    	$this->question_id = $question_id;
    	$this->date_interval = $date_interval;
    	
        /**
         * Now we need to define our column headers and sortable items.
         */
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        
        /**
         * Finally, we build an array to be used by the class for column 
         * headers. The $this->_column_headers property takes an array which contains
         * 3 other arrays. One for all columns, one for hidden columns, and one
         * for sortable columns.
         */
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        
        /*
		 * Get and sort the current prediction list
		 */
        
        $search = (!empty($_REQUEST['s'])) ? $_REQUEST['s'] : '';
        $search = str_replace('%', '', $search);  // Lose % as messes with $wpdb->prepare
        
        if (!empty($search)) {
        	$search = "AND (display_name LIKE '%%$search%%' OR user_email LIKE '%%$search%%')";
        }
        
        $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'event_date'; //If no sort, default to name
        $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'desc'; //If no order, default to asc
        
        /*
         * Depending on whether registration is required join with WP users table.
         */
		$sql = "(SELECT prediction_id, question_id, 0 AS user_id, '' AS user_email, '' AS display_name, 0 AS ID, event_date, score, wwhen
				FROM 
					{$wpdb->prefix}{$this->prefix}prediction
				WHERE
					user_id IS NULL AND question_id = %d
				)
				UNION
				(SELECT prediction_id, question_id, p.user_id, u.user_email, u.display_name, u.ID, event_date, score, wwhen
				FROM 
					{$wpdb->prefix}{$this->prefix}prediction p,
					{$wpdb->users} u
				WHERE
					user_id IS NOT NULL AND user_id = ID AND question_id = %d
					$search
				)
				ORDER BY $orderby $order";
					
		$data = $wpdb->get_results( $wpdb->prepare($sql, $question_id, $question_id) , ARRAY_A );
		//array_walk($data, array($this->caller, 'unclean'));
		
		/*
		 * Clean the input
		 */
		foreach ($data as $k=>$row) {
			foreach (array_keys($row) as $key) {
				$data[$k][$key] = $this->caller->unclean($row[$key]);
			}
		}
		
        /**
         * Let's figure out what page the user is currently 
         * looking at. We'll need this later.
         */
        $current_page = $this->get_pagenum();
        
        /**
         * Let's check how many items are in our data array. 
         */
        $total_items = count($data);
        
        
        /**
         * The WP_List_Table class does not handle pagination for us, so we need
         * to ensure that the data is trimmed to only the current page. We can use
         * array_slice() to achieve this.
         */
        $data = array_slice($data,(($current_page-1)*$this->page_size),$this->page_size);
        
        
        
        /**
         * Now we can add our *sorted* data to the items property, where 
         * it can be used by the rest of the class.
         */
        $this->items = $data;
        
        
        /**
         * We also have to register our pagination options & calculations.
         */
        $this->set_pagination_args( array(
            'total_items' => $total_items,                  //WE have to calculate the total number of items
            'per_page'    => $this->page_size,              //WE have to determine how many items to show on a page
            'total_pages' => ceil($total_items/$this->page_size)  //WE have to calculate the total number of pages
        ) );
    }
    
}
?>
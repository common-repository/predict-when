<?php
/**
 * @package PredictWhen
 * @version $Id$
 * @author ian
 * Copyright Ian Haycox, 2012
 *
 * Question Administration functions for the PredictWhenServer plugin.
 *
 */

class PredictWhenQuestion extends PredictWhenAdmin {

	var $key = 'question_id';	// Primary key name
	
	/*
	 * Constructor
	 */
	function __construct() {
		parent::__construct();
	}
	
	/*
	 * Display and manage questions
	 */
	function question($add_new = false) {
		global $wpdb;
		
		$question_id = -1;				// Unique ID, key
		$name = '';							// Question name
		$notes = '';
		$start_dt = $end_dt = '';			// Limit predictions to between start and end dates
		$m_start_dt = $m_end_dt = '';		// Limit predictions to between monthly start and end dates
		$never = 0;							// Include the 'never going to happen' option
		$publish = 0;						// Publish questions to predictwhen.com
		$registration_required = 0;			// Must be registered to prediction
		$limit_multiple = 0;				// Limit multiple predictions for non-registered by seconds between predictions (Cookie & IP)
		$status = 'open';					// Question is open for voting
		$event_dt = date('Y-m-d');			// Default close date
		$event_tm = '';						// Default optional close time
		$date_interval = 'days';			// Users select a day/month
		
		//$this->debug($_POST);
		
		$table = new PredictWhenQuestion_Table($this);
		$editing = $add_new;
		$closing = false;
		
		//echo '<pre>' . print_r($_POST, true) . '</pre>';
		
		
		if (isset($_POST[$this->prefix.'modifyQuestionCancel'])) {
			check_admin_referer($this->prefix . 'question-form');
			$editing = false;
		}
		
		if (isset($_POST[$this->prefix.'closeQuestionCancel'])) {
			check_admin_referer($this->prefix . 'closing-form');
			$editing = false;
		}
		
		if (isset($_POST[$this->prefix.'addQuestion'])) {
			check_admin_referer($this->prefix . 'question-form');
			
			extract($_POST, EXTR_IF_EXISTS);
			
			if ($date_interval == 'months') {
				$start_dt = $m_start_dt;
				$end_dt = $m_end_dt;
			}			
			
			// Save to database
			if ($this->insert($name, $notes, $start_dt, $end_dt, $never, $publish, $registration_required, $limit_multiple, $status, $date_interval) !== false) {
				$name = $notes = $start_dt = $end_dt = $created = $m_start_dt = $m_end_dt = '';
				$never = $publish = 0;
				$registration_required = 0;
				$limit_multiple = 0;
				$status = 'open';
				$date_interval = 'days';
			} else {
				$editing = true; // continue editing
			}
		}

		/*
		 * Actually modify the result.
		 */
		if (isset($_POST[$this->prefix.'modifyQuestion'])) {
			check_admin_referer($this->prefix . 'question-form');
			
			//$this->debug($_POST);
			
			extract($_POST, EXTR_IF_EXISTS);
			
			if ($date_interval == 'months') {
				$start_dt = $m_start_dt;
				$end_dt = $m_end_dt;
			}			
			
			if ($this->update($question_id, $name, $notes, $start_dt, $end_dt, $never, $publish, $registration_required, $limit_multiple, $status, $date_interval) !== false) {
				$name = $notes = $start_dt = $end_dt = '';
				$never = $publish = 0;
				$registration_required = 0;
				$limit_multiple = 0;
				$status = 'open';
				$date_interval = 'days';
				$question_id = -1;
			} else {
				$editing = true; // continue editing
			}
		}
		
		/*
		 * Close question with the event date
		 */
		if (isset($_POST[$this->prefix.'closeQuestion'])) {
				
			check_admin_referer($this->prefix . 'closing-form');
			
			extract($_POST, EXTR_IF_EXISTS);
			
			if (is_array($question_id)) {
				foreach ($question_id as $id) {
					$this->close($id, $event_dt, $event_tm);
				}
			} else {
				if ($this->close($question_id, $event_dt, $event_tm) !== false) {
					$event_dt = date('Y-m-d');
					$event_tm = '';
					$question_id = -1;
				} else {
					$closing = true; // continue closing
				}
			}
		}
		
		/*
		* Reject question with the event date
		*/
		if (isset($_POST[$this->prefix.'rejectQuestion'])) {
		
			check_admin_referer($this->prefix . 'reject-form');
				
			$reject_reason = '';
			extract($_POST, EXTR_IF_EXISTS);
				
			$this->delete($question_id, $reject_reason);
		}
		
		$doaction = $table->current_action();
		
		if ($doaction) {
			
			if ($doaction == 'preview') {
				echo do_shortcode('[predictwhen id='.$_GET['question_id'].']');
				return;
			}
			
			if ($doaction == 'add') {
				$editing = true;
			}			
			
			if ($doaction == 'close') {
				$question_id = $_GET['question_id'];
				$closing = true;
			}
			
			if ($doaction == 'approve') {
				check_admin_referer($this->prefix . 'approve');
				if (isset($_GET['question_id'])) {
					$this->approve($_GET['question_id']);
				}
				$question_id = -1;
			}
			
			/* Bulk approve */
			if ($doaction == 'bulkapprove') {
				check_admin_referer('bulk-questions');
				if (isset($_POST['question_id'])) {
					foreach ($_POST['question_id'] as $id) {
						$this->approve($id);
					}
					unset($_POST['question_id']);
				}
				$question_id = -1;
			}
			
			/*
			 * Process GET request to retreive the question details and pre-fill
			 * the form.
			 */
			if ($doaction == 'edit') {
				$question_id = $_GET['question_id'];
				$row = $this->get($question_id);
				if (empty($row)) $question_id = -1;	// Didn't find row. Prevent modification
				extract($row, EXTR_IF_EXISTS);
				if ($date_interval == 'months') {
					$m_start_dt = $start_dt;
					$start_dt = '';
					$m_end_dt = $end_dt;
					$end_dt = '';
				}			
				$editing = true;
			}
			
			/* Single delete */
			if ($doaction == 'delete') {
				check_admin_referer($this->prefix . 'delete');
				if (isset($_GET['question_id'])) {
					$this->delete($_GET['question_id']);
				}
				$question_id = -1;
			}
						
			/* Bulk delete */
			if ($doaction == 'bulkdelete') {
				check_admin_referer('bulk-questions');
				if (isset($_POST['question_id'])) {
					foreach ($_POST['question_id'] as $id) {
						$this->delete($id);
					}
					unset($_POST['question_id']);
				}
				$question_id = -1;
			}

			/* Bulk close */
			if ($doaction == 'bulkclose') {
				check_admin_referer('bulk-questions');
				if (isset($_POST['question_id'])) {
					$closing = true;
					$question_id = $_POST['question_id'];
				}
			}
		}
		
?>
		<div class="wrap">
		
		<h2 id="<?php echo $this->prefix; ?>h2-icon"><?php _e('Questions', PW_TD); ?>
		<?php if (!$editing) { ?>
		 	<a href="?page=<?php echo $_REQUEST['page']; ?>&amp;action=add" class="add-new-h2"><?php _e('Add New', PW_TD); ?></a>
		<?php } ?>
		</h2>
		
		<?php $this->printMessage();
		
		/* Single reject */
		if ($doaction == 'reject') {
			$rjq = $this->get($_GET['question_id']);
			?>
			<div class="<?php echo $this->prefix; ?>input">
			
			<h2><?php echo $this->unclean($rjq['name']); ?></h2>
			
			<form name="question" action="?page=<?php echo $_REQUEST['page']; ?>" method="post">
			
			<?php wp_nonce_field( $this->prefix . 'reject-form' ); ?>
			<table class="form-table <?php echo $this->prefix; ?>form">
			<tbody>
			<tr valign="top">
				<th scope="row"><label for="reject_reason"><?php _e( 'Rejection reason', PW_TD ); ?></label></th>
				<td><textarea cols="80" rows="5" id="reject_reason" name="reject_reason" placeholder="<?php _e('Enter the reason for rejection. This reason will be added to the rejection email.', PW_TD); ?>"
					title="<?php _e('Enter the reason for rejection. This reason will be added to the rejection email.', PW_TD); ?>" ></textarea>
				</td>
			</tr>
			</tbody>
			</table>
			<input type="hidden" value="<?php echo $rjq['question_id']; ?>" name="question_id" />
			<p class="submit" style="padding:0.5em 0;">
				<input type="submit" name="<?php echo $this->prefix;?>rejectQuestion" value="<?php _e( 'Reject Question', PW_TD ); ?>" class="button-primary" />
				<input type="submit" name="<?php echo $this->prefix;?>rejectQuestionCancel" value="<?php _e( 'Cancel', PW_TD ); ?>" class="button" />
			</p>
			</form>
			</div>
			<br />
<?php 	}
		
		if ($closing) { 
			
			?>
			<div class="<?php echo $this->prefix; ?>input">
			
			<?php if (!is_array($question_id)) { 
				$clq = $this->get($question_id); ?>
				<h2><?php echo $this->unclean($clq['name']); ?></h2>
			<?php } ?>
			
			<form name="question" action="?page=<?php echo $_REQUEST['page']; ?>" method="post">
			
			<?php wp_nonce_field( $this->prefix . 'closing-form' ); ?>
			<table class="form-table <?php echo $this->prefix; ?>form">
			<tbody>
			<tr valign="top">
				<th scope="row"><label for="event_dt"><?php _e( 'Event date<br /><small>YYYY-MM-DD format</small>', PW_TD ); 
				if (!is_array($question_id) && !$this->never_enabled($question_id)) {$this->required();} ?></label></th>
				<td><input class="<?php echo $this->prefix; ?>datepicker" type="text" name="event_dt" value="<?php echo $event_dt;?>" size="11"
					title="<?php _e('Enter the date this event occurred or leave blank if it never occurred', PW_TD); ?>" />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="event_tm"><?php _e( 'Optional event time<br /><small>HH:MM format</small>', PW_TD ); ?></label></th>
				<td><input type="text" name="event_tm" value="<?php echo $event_tm;?>" size="5"
					title="<?php _e('Optionally enter the time this event occurred, e.g. 14:00 for 2pm', PW_TD); ?>" />
				</td>
			</tr>
			</tbody>
			</table>
			
			
			<?php if (is_array($question_id)) {
					foreach ($question_id as $qid) {
						echo '<input type="hidden" value="'.$qid.'" name="question_id[]" />';
					}
				} else {
					if ($this->registration_required($question_id)) {
						echo '<p>';
						echo sprintf(__('Once this question has been closed, display Scoring via the shortcode <code>[predictwhen id=%d scoring=1]</code>', PW_TD), $question_id);
						echo '</p>';
						echo '<p>' . sprintf(__('Use the <code>limit=n</code> option to show a maximum of <code>n</code> lines. For example <code>[predictwhen id=%d scoring=1 limit=10]</code>', PW_TD), $question_id) . '</p>';
					}
						?>
						<input type="hidden" value="<?php echo $question_id; ?>" name="question_id" /> <?php
				} ?>
			<p class="submit" style="padding:0.5em 0;">
				<input type="submit" name="<?php echo $this->prefix;?>closeQuestion" value="<?php _e( 'Close Question', PW_TD ); ?>" class="button-primary" />
				<input type="submit" name="<?php echo $this->prefix;?>closeQuestionCancel" value="<?php _e( 'Cancel', PW_TD ); ?>" class="button" />
			</p>
			</form>
			</div>
			<br />
<?php 	}
		
		
		if ($editing) { ?>

		<div id="dialog-modal" title="<?php _e('Scoring', PW_TD); ?>">
			<p><?php _e('Scoring takes account of both accuracy and range.', PW_TD); ?></p>
			<p><?php _e('A prediction that is a week out but made a year in advance will score higher than a prediction that is a day out but made a month in advance.', PW_TD); ?></p>
			<p><?php printf(__('Further detail is provided on the <a href="%s">PredictWhen.com</a> website.', PW_TD), 'http://predictwhen.com'); ?></p>
		</div>

		<div class="<?php echo $this->prefix; ?>input">
		
		<p><?php _e('Complete the fields below to ask a question and invite predictions', PW_TD); ?></p>
		
		<form name="question" action="?page=<?php echo $_REQUEST['page']; ?>" method="post">
		
			<?php wp_nonce_field( $this->prefix . 'question-form' ); ?>
			
			<table class="form-table <?php echo $this->prefix; ?>form">
			<tbody>
			<tr valign="top">
				<th title="<?php _e('For example - When will man walk on Mars?', PW_TD); ?>" scope="row"><label class="question" for="form-name"><?php _e( 'Question', PW_TD ); $this->required(); ?></label></th>
				<td colspan="2"><input class="question" type="text" id="form-name" name="name" value="<?php echo $name;?>" size="60" 
						placeholder="<?php _e('e.g. When will Man walk on Mars?', PW_TD); ?>"
						title="<?php _e('e.g. When will Man walk on Mars?', PW_TD); ?>" />
					<br /><?php _e('Keep your question short & simple. If you need to further qualify the question do so in the main body of the post', PW_TD); ?>	
				</td>
			</tr>
			<tr valign="top">
				<th title="<?php _e('Explanatory notes', PW_TD); ?>" scope="row"><label class="notes" for="form-notes"><?php _e( 'Notes', PW_TD ); ?></label></th>
				<td colspan="2"><textarea class="notes" id="form-notes" name="notes" 
						placeholder="<?php _e('Optional descriptive notes for this question', PW_TD); ?>"
						title="<?php _e('Description', PW_TD); ?>" cols="80" ><?php echo $notes; ?></textarea>
				</td>
			</tr>
			<tr valign="top">
				<th title="<?php _e('User selects a month only', PW_TD); ?>">
					<label for="date_interval"><?php _e("If checked users can only select a month instead of a date", PW_TD); ?></label>
				</th>
				<td><input type="checkbox" <?php echo ($date_interval == 'months' ? 'checked' : ''); ?>
						name="date_interval" id="predictwhen_date_interval" value="months"
						title="<?php _e('If checked users can only select a month instead of a date', PW_TD); ?>" />
				</td>
				<td>
				<?php _e('Allows users to predict by month instead of one date. Useful for long range predictions', PW_TD); ?>
				</td>
			</tr>
			<tr class="predictwhen_dateshow" valign="top">
				<th title="<?php _e('Prevent dates earlier than those specified, e.g. 2011-08-01 for 1st August 2011. Leave blank for no limit', PW_TD); ?>" scope="row">
					<label for="start_dt"><?php _e( 'Limit date range for predictions from<br /><small>YYYY-MM-01 format</small>', PW_TD ); ?></label>
				</th>
				<td><input class="<?php echo $this->prefix; ?>monthpicker" type="text" name="m_start_dt" value="<?php echo $m_start_dt;?>" size="10"
					title="<?php _e('Prevent dates earlier than those specified, e.g. 2011-08-01 for 1st August 2011. Leave blank for no limit', PW_TD); ?>" />
				</td>
				<td>
				<?php _e('Only allow predictions after a nominated date. Leave blank for no limit', PW_TD); ?>
				</td>
			</tr>
			<tr class="predictwhen_datehide" valign="top">
				<th title="<?php _e('Prevent dates earlier than those specified, e.g. 2011-08-13 for 13th August 2011. Leave blank for no limit', PW_TD); ?>" scope="row">
					<label for="start_dt"><?php _e( 'Limit date range for predictions from<br /><small>YYYY-MM-DD format</small>', PW_TD ); ?></label>
				</th>
				<td><input class="<?php echo $this->prefix; ?>datepicker" type="text" name="start_dt" value="<?php echo $start_dt;?>" size="10"
					title="<?php _e('Prevent dates earlier than those specified, e.g. 2011-08-13 for 13th August 2011. Leave blank for no limit', PW_TD); ?>" />
				</td>
				<td>
				<?php _e('Only allow predictions after a nominated date. Leave blank for no limit', PW_TD); ?>
				</td>
			</tr>
			<tr class="predictwhen_dateshow" valign="top">
				<th title="<?php _e('Prevent dates later than those specified, e.g. 2013-08-01 Leave blank for no limit', PW_TD); ?>" scope="row">
					<label for="end_dt"><?php _e( 'Limit date range for predictions to<br /><small>YYYY-MM-01 format</small>', PW_TD ); ?></label>
				</th>
				<td><input class="<?php echo $this->prefix; ?>monthpicker" type="text" name="m_end_dt" value="<?php echo $m_end_dt;?>" size="10"
					title="<?php _e('Prevent dates later than those specified, e.g. 2013-08-01 Leave blank for no limit', PW_TD); ?>" />
				</td>
				<td>
				<?php _e('Only allow predictions before a nominated date. Leave blank for no limit', PW_TD); ?>
				</td>
			</tr>
			<tr class="predictwhen_datehide" valign="top">
				<th title="<?php _e('Prevent dates later than those specified, e.g. 2011-08-19 Leave blank for no limit', PW_TD); ?>" scope="row">
					<label for="end_dt"><?php _e( 'Limit date range for predictions to<br /><small>YYYY-MM-DD format</small>', PW_TD ); ?></label>
				</th>
				<td><input class="<?php echo $this->prefix; ?>datepicker" type="text" name="end_dt" value="<?php echo $end_dt;?>" size="10"
					title="<?php _e('Prevent dates later than those specified, e.g. 2011-08-19 Leave blank for no limit', PW_TD); ?>" />
				</td>
				<td>
				<?php _e('Only allow predictions before a nominated date. Leave blank for no limit', PW_TD); ?>
				</td>
			</tr>
			<tr valign="top">
				<th title="<?php _e('Add an option to indicate that the event is never going to happen', PW_TD); ?>">
					<label for="never"><?php _e("Include 'Never' option", PW_TD); ?></label>
				</th>
				<td><input type="checkbox" <?php echo ($never ? 'checked' : ''); ?>
						name="never" id="never" value="1"
						title="<?php _e('Add an option to indicate that the event is never going to happen', PW_TD); ?>" />
				</td>
				<td>
				<?php _e('Allows users to predict an event will never happen', PW_TD); ?>
				</td>
			</tr>
			<tr valign="top">
				<th title="<?php _e('Ticking this box will list your question on PredictWhen.com where we aim to collate all the questions powered by this plugin and give them more exposure', PW_TD); ?>">
					<label for="publish"><?php _e('Include in the <a href="http://www.predictwhen.com">PredictWhen</a> directory', PW_TD); ?></label>
				</th>
				<td><input type="checkbox" <?php echo ($publish ? 'checked' : ''); ?>
						name="publish" id="publish" value="1"
						title="<?php _e('Ticking this box will list your question on PredictWhen.com where we aim to collate all the questions powered by this plugin and give them more exposure', PW_TD); ?>" />
				</td>
				<td>
				<?php _e('We aim to collate all the questions powered by this free plugin. We list your question in our directory and link to the page on your blog where it is published. To make a prediction, users have to visit your blog.', PW_TD); ?>
				</td>
			</tr>
			<tr>
				<th title="<?php _e('Users must be logged in to predict', PW_TD); ?>">
					<label for="registration_required"><?php _e('Login or register to enter?<br /><small>(Required to enable scoring)</small>', PW_TD); ?></label>
				</th>
				<td><input type="checkbox" <?php echo ($registration_required ? 'checked' : ''); ?>
						name="registration_required" id="predictwhen_registration_required" value="1"
						title="<?php _e('Users must be logged in to predict', PW_TD); ?>" />
				</td>
				<td>
				<?php _e('Users must log in or register with your blog in order to make a prediction.', PW_TD);
					  echo sprintf(__('You must check this box and <a href="%s">enable blog registration</a> if you want to score the prediction and rank the most accurate predictions. Find out more about how scores are calculated <a id="scoring-dialog" href="%s">here</a>', PW_TD),
					  	'/wp-admin/options-general.php', '#'); ?>
				</td>
			</tr>
			
			<tr class="predictwhen_hide"  valign="top">
				<th title="<?php _e('Expiration time to limit multiple predictions', PW_TD); ?>"><label for="limit_multiple"><?php _e('Expiration time to limit multiple predictions', PW_TD); ?></label></th>
				<td>
				<?php 
					echo $this->get_selection($this->cookie_expirations,
						$limit_multiple, false, 'limit_multiple', 'limit_multiple', __('Select the time period that must elapse before an unregistered user can prediction again', PW_TD));
				?>
				</td>
				<td>
				<?php _e('Require a certain time to pass before a user can make another prediction. Prevents repeat submissions and deters users from changing their original submission having seen the collectively predicted date.', PW_TD); ?>
				</td>
			</tr>
			
			</tbody>
			</table>
			
<?php 
			if  ($question_id != -1) {
?>
			<input type="hidden" value="<?php echo $question_id; ?>" name="question_id" />
			<input type="hidden" value="<?php echo $status; ?>" name="status" />
			<p class="submit" style="padding:0.5em 0;"><input type="submit" name="<?php echo $this->prefix;?>modifyQuestion" value="<?php _e( 'Modify Question', PW_TD ); ?>" class="button-primary" />
			<input type="submit" name="<?php echo $this->prefix;?>modifyQuestionCancel" value="<?php _e( 'Cancel', PW_TD ); ?>" class="button" />
			</p>
<?php 
			} else {
?>
			<p class="submit" style="padding:0.5em 0;"><input type="submit" name="<?php echo $this->prefix;?>addQuestion" value="<?php _e( 'Add Question', PW_TD ); ?>" class="button-primary" />
			<input type="submit" name="<?php echo $this->prefix;?>modifyQuestionCancel" value="<?php _e( 'Cancel', PW_TD ); ?>" class="button" />
			</p>
<?php 		
			}
?>
		</form>
		</div>
		<br />
		
<?php 
	} /* editing */
	
		//Fetch, prepare, sort, and filter our data...
		$table->prepare_items();
		
?>
        <form id="list-questions" method="post">
        	<?php $table->search_box(__('Search', PW_TD), 'question'); ?>
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
            <input type="hidden" name="question_filter" value="<?php echo $_REQUEST['question_filter']; ?>" />
            <?php $table->display() ?>
        </form>

		</div>
<?php
	}

	/**
	 * Build a SELECT dropdown of available questions
	 * 
	 * @param $name - Form name
	 * @param $empty - If true add extra 'Select...' option
	 * @param $question_id - If specified preselect matching option
	 */
	function select_question($name, $empty = false, $question_id = -1, $title='', $class = '') {
		global $wpdb;
		
		$sql = "SELECT question_id, 
					CONCAT(name, '  (', status, ')') AS name
				FROM {$wpdb->prefix}{$this->prefix}question ORDER BY status DESC, name";
		$result = $wpdb->get_results($sql, OBJECT_K);
		
		if ($empty === true) {
			$empty = array(0 => __('Choose an question...', PW_TD));
		}
		
		return $this->get_selection($result, $question_id, $empty, $name, 'form-'.$name, $title, $class);
	}
	
	/*
	 * Check valid input
	 */
	private function valid($name, $start_dt, $end_dt, $never, $publish, $registration_required, $limit_multiple, $status, $date_interval) {
		
		if (empty($name)) {
			$this->setMessage(__("Question can not be empty", PW_TD), true);
			return false;
		}
		
		if (!$this->is_YYYYMMDD($start_dt) || !$this->is_YYYYMMDD($end_dt)) {
			$this->setMessage(__("Dates must be YYYY-MM-DD format or blank", PW_TD), true);
			return false;
		}
		
		/*
		 * Check end_dt > start_dt
		 */
		if (!empty($start_dt) && !empty($end_dt)) {
			$s = strtotime($start_dt);
			$e = strtotime($end_dt);
			if ($e < $s) {
				$this->setMessage(__("Start date must be earlier than End date", PW_TD), true);
				return false;
			}
		}
		
		if ($date_interval == 'months') {
			if ((!empty($start_dt) && substr($start_dt, -2) != '01') ||
				(!empty($end_dt) && substr($end_dt, -2) != '01')) {
				$this->setMessage(__("Monthly dates must be YYYY-MM-01 format", PW_TD), true);
				return false;
			}
		}
		
		return true;
	}
	
	/*
	 * Insert row
	 */
	private function insert($name, $notes, $start_dt, $end_dt, $never, $publish, $registration_required, $limit_multiple, $status, $date_interval) {
		global $wpdb;

		$name = $this->clean($name);
		$notes = $this->clean($notes);
		$start_dt = $this->clean($start_dt);
		$end_dt = $this->clean($end_dt);
		$never = $this->clean($never);
		$publish = $this->clean($publish);
		$registration_required = $this->clean($registration_required);
		$limit_multiple = $this->clean($limit_multiple);
		$status = $this->clean($status);
		$date_interval = $this->clean($date_interval);
		 	
		if (!$this->valid($name, $start_dt, $end_dt, $never, $publish, $registration_required, $limit_multiple, $status, $date_interval)) {
			return false;
		}
		
		$this->setMessage(__('Changes saved', PW_TD));
		
		// Shitty NULL handling - WP doesn't !
		if (empty($start_dt)) {
			$start_dt = 'NULL';
		} else {
			$start_dt = "'$start_dt'";
		}
		if (empty($end_dt)) {
			$end_dt = 'NULL';
		} else {
			$end_dt = "'$end_dt'";
		}
				
		/*
		 * Sanitize options dependent on registration.
		 * Cookie lifetime is pointless for registered users. 
		 */
		if ($registration_required) {
			$limit_multiple = 0;
		}
		
		
		$sql = "INSERT INTO {$wpdb->prefix}{$this->prefix}question
				(name, notes, created, start_dt, end_dt, never, publish, registration_required, limit_multiple, status, date_interval)
				VALUES (%s, %s, NOW(), $start_dt, $end_dt, %d, %d, %d, %d, %s, %s)";
		
		$ret = $wpdb->query( $wpdb->prepare( $sql, $name, $notes, $never, $publish, $registration_required, $limit_multiple, $status, $date_interval) );
		
		if ($ret == 1) {
			
			$id = $wpdb->insert_id;
			
			if ($publish) {
				$this->publish('insert', $id);
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
	private function update($question_id, $name, $notes, $start_dt, $end_dt, $never, $publish, $registration_required, $limit_multiple, $status, $date_interval) {
		global $wpdb;
		
		$name = $this->clean($name);
		$notes = $this->clean($notes);
		$start_dt = $this->clean($start_dt);
		$end_dt = $this->clean($end_dt);
		$never = $this->clean($never);
		$publish = $this->clean($publish);
		$registration_required = $this->clean($registration_required);
		$limit_multiple = $this->clean($limit_multiple);
		$status = $this->clean($status);
		$date_interval = $this->clean($date_interval);
		
		if (!$this->valid($name, $start_dt, $end_dt, $never, $publish, $registration_required, $limit_multiple, $status, $date_interval)) {
			return false;
		}
		
		$this->setMessage(__('Changes saved', PW_TD));
		
		if (empty($start_dt)) {
			$start_dt = 'NULL';
		} else {
			$start_dt = "'$start_dt'";
		}
		if (empty($end_dt)) {
			$end_dt = 'NULL';
		} else {
			$end_dt = "'$end_dt'";
		}
		
		/*
		 * Sanitize options dependent on registration.
		 * Cookie lifetime is pointless for registered users. 
		 */
		if ($registration_required) {
			$limit_multiple = 0;
		}
		
		$old_publish = $this->is_published($question_id);
		
		$sql = "UPDATE {$wpdb->prefix}{$this->prefix}question
				SET
					name = %s,
					notes = %s,
					start_dt = $start_dt,
					end_dt = $end_dt,
					never = %d,
					publish = %d,
					registration_required = %d,
					limit_multiple = %d,
					status = %s,
					date_interval = %s
				WHERE question_id = %d";
		
		$ret = $wpdb->query( $wpdb->prepare( $sql, $name, $notes, $never, $publish, $registration_required, $limit_multiple, $status, $date_interval, $question_id ) );
		if ($ret === false) {
			$this->setMessage(__('Error updating data', PW_TD) . $wpdb->last_error, true);
			return false;
		}
		
		$sql = "SELECT post_id FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		$post_id = $wpdb->get_var($wpdb->prepare($sql, $question_id));
		
		delete_transient($this->prefix.'data'.$question_id);
		delete_transient($this->prefix.'dates'.$question_id);
		
		$permalink = get_permalink($post_id);
		if ($permalink === false) $permalink = '';
		
		// If this is (or was) published 
		if ($old_publish || $publish) {
			$this->publish('update', $question_id, $permalink);
			$this->publish('prediction_data', $question_id);
		}
		
		return true;
	}
	
	/*
	 * Close this question
	 */
	public function close($question_id, $event_dt, $event_tm) {
		global $wpdb;
		
		
		$event_dt = $this->clean($event_dt);
		$event_tm = $this->clean($event_tm);
		
		/*
		 * Verify event date valid
		 */
		
		if (!$this->is_YYYYMMDD($event_dt)) {
			$this->setMessage(__("Date must be YYYY-MM-DD format", PW_TD), true);
			return false;
		}
		
		if (!$this->never_enabled($question_id) && empty($event_dt)) {
			$this->setMessage(__("Date must be YYYY-MM-DD format", PW_TD), true);
			return false;
		}
		
		if (!$this->is_HHMM($event_tm)) {
			$this->setMessage(__("Time must be HH:MM 24hr format", PW_TD), true);
			return false;
		}
		
		if (empty($event_dt)) {
			$event_dt2 = 'NULL';
		} else {
			$event_dt2 = "'$event_dt'";
		}
		
		if (empty($event_tm)) {
			$event_tm2 = 'NULL';
		} else {
			$event_tm2 = "'$event_tm'";
		}
		
		$sql = "UPDATE {$wpdb->prefix}{$this->prefix}question
				SET
					event_dt = $event_dt2,
					event_tm = $event_tm2,
					status = 'closed'
				WHERE question_id = %d AND status='open'";
		
		$ret = $wpdb->query( $wpdb->prepare( $sql, $question_id ) );
		if ($ret === false) {
			$this->setMessage(__('Error updating data', PW_TD) . $wpdb->last_error, true);
			return false;
		}
		
		// No rows updated - so nothing to do.
		if ($ret === 0) {
			return true;
		}
		
		delete_transient($this->prefix.'data'.$question_id);
		delete_transient($this->prefix.'dates'.$question_id);
		
		if ($this->is_published($question_id)) {
			$this->publish('prediction_data', $question_id); // Order important.
			$this->publish('close', $question_id);
		}
		
		/*
		 * Update any scoring
		 * 
		 * Scoring is not solely based on the closest to the date the event actually takes place
		 * but also takes into account how far in advance the prediction was made. Therefore a
		 * prediction that is accurate to within a week but made 6 months out scores higher
		 * than a prediction  that was accurate to within an hour but was only made the week
		 * prior to the event occurring.
		 * 
		 * Scoring is calculated as follows where
		 * 
		 * M = time (DD/MM/YY/HH/SS) at which prediction was made
		 * P = predicted time on which event will occur. Default is to midday on the date selected
		 * E = time and date event actually occurred as specified by the blog owner when closing the prediction.
		 * 
		 * The difference in days between either
		 * E and P or
		 * E and M (whichever is the lower number)
		 * 
		 * Divided by
		 * The difference in days between the E and P.
		 * 
		 * Highest score wins.
		 * 
		 * Update: May 20th 2012
		 * 
		 * The difference in days between either
		 * 
		 * M and P or
		 * M and E (whichever is the lower number)
		 * 
		 * That is not what I wrote originally which understandably confused matters!
		 * 
		 */
		
		if ($this->scoring_enabled($question_id)) {
			
			$e = '';
			$wwhen = 0;
			if (!empty($event_dt)) {
				$e = strtotime($event_dt . ($event_tm == '' ? '' : ' ' . $event_tm));
			} else {
				// Get the timestamp we closed this question as a Never
				$sql = "SELECT DATE_FORMAT(wwhen, '%%Y-%%m-%%d %%T') FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
				$wwhen = $wpdb->get_var($wpdb->prepare($sql, $question_id));
				$wwhen = strtotime($wwhen);
			}
			
			$sql = "SELECT wwhen AS M, event_date AS P, prediction_id FROM {$wpdb->prefix}{$this->prefix}prediction WHERE question_id = %d";
			$results = $wpdb->get_results($wpdb->prepare($sql, $question_id));
			
			//$this->debug($results);
			
			/*
			 * Note - event_date may be NULL for a 'Never' prediction
			 * 
			 */
			foreach ($results as $row) {
				
				// Again - not using MySQL UNIX_TIMESTAMP() as timezone differences between server, blog and PHP settings
				
				$score = 0;
				if (!empty($row->P)) {
					
					if (!empty($e)) {
						$m = strtotime($row->M);
						$p = strtotime($row->P);
						
						$denominator = abs($e - $p);
						if ($denominator) {
							
							// TODO do in all in one SQL update statement
							$numerator = min(abs($m - $p), abs($m - $e)) * 100;
							
							$score = (int)($numerator / $denominator);  // Cast to int to round down.
						} else {
							// Prediction exact, so score as days between event and when prediction made
							$score = (int)(abs($m - $e) / 864);  // Seconds per day / 100
						}
					}
				} else {
					// No predicted date so must be a Never
					if ($wwhen) {
						// Score Never predictions
						$m = strtotime($row->M);
						$score = (int)(abs($wwhen - $m) / 864);
					}
				}
				
				$sql = "UPDATE {$wpdb->prefix}{$this->prefix}prediction
							SET
								score = %d,
								wwhen = wwhen
							WHERE
								prediction_id = %d";
				$wpdb->query($wpdb->prepare($sql, $score, $row->prediction_id));
				
			}
		}
		
		return true;
	}
	
	/*
	 * Get row by id.
	 */
	public function get($question_id) {
		global $wpdb;
		
		$sql = "SELECT question_id, name, notes, created, start_dt, end_dt, never, publish, registration_required, limit_multiple, status, date_interval, event_dt
				FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		$row = $wpdb->get_row( $wpdb->prepare($sql, $question_id) , ARRAY_A );
		
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
	private function delete($question_id, $reject_reason = '') {
		global $wpdb;
		
		$this->setMessage(__('Changes saved', PW_TD));
		
		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}{$this->prefix}prediction WHERE question_id = %d";
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $question_id ) );
		if ($count) {
			$this->setMessage(__('Can not delete a question whilst there are predictions.', PW_TD), true);
			return false;
		}
		
		$published = $this->is_published($question_id);
		$this->notify_rejection($question_id, $reject_reason);
		
		$sql = "DELETE FROM {$wpdb->prefix}{$this->prefix}question WHERE question_id = %d";
		
		$ret = $wpdb->query( $wpdb->prepare( $sql, $question_id ) );
		if ($ret === false) {
			$this->setMessage(__('Error deleting data', PW_TD) . $wpdb->last_error);
			return false;
		}
		
		// No rows deleted - so nothing to do.
		if ($ret === 0) {
			return true;
		}
		
		delete_transient($this->prefix.'data'.$question_id);
		delete_transient($this->prefix.'dates'.$question_id);
		
		if ($published) {
			$this->publish('delete', $question_id);
		}
		
		return true;
	}
	
	
	private function approve($question_id) {
		global $wpdb;
		
		$question_url = '';  // TODO
		
		$question = $this->get($question_id);
		if (empty($question)) return false;
		
		$users = get_users(array('meta_key' => $this->prefix . 'question_' . $question_id));
		foreach ($users as $user) {
			// should only be 1 user with the matching question_id
			$now = date('Y-m-d H:i:s');
			$now_gmt = gmdate('Y-m-d H:i:s');
			$new_post = array(
									    'post_title' => $question['name'],
										'post_name' => sanitize_title($question['name']),
										'post_content' => "[predictwhen id=$question_id]",
										'post_excerpt' => $question['notes'],
									    'post_status' => 'publish',
									    'post_date' => $now,
									    'post_date_gmt' => $now_gmt,
									    'post_author' => $user->ID,
									    'post_type' => 'post',
										'post_modified' => $now,
										'post_modified_gmt' => $now_gmt
			);
			
			$post_id = wp_insert_post($new_post);
			if ($post_id) {
				$sql = "UPDATE {$wpdb->prefix}{$this->prefix}question
						SET
							status = 'open',
							post_id = %d
						WHERE question_id = %d AND status = 'pending'";
		
				$ret = $wpdb->query( $wpdb->prepare( $sql, $post_id, $question_id ) );
				if ($ret === false) {
					$this->setMessage(__('Error approving data', PW_TD) . $wpdb->last_error, true);
					return false;
				}
			}
			
			update_user_meta($user->ID, $this->prefix.'question_' . $question_id, 'accepted');
		}
		
		$this->notify_approval($question_id, $question_url, $post_id);
		
		$this->setMessage(sprintf(__('Edit published post <a href="%s">%s<a/>', PW_TD), 'post.php?post='.$post_id.'&action=edit', $question['name']));
		
		delete_transient($this->prefix.'data'.$question_id);
		delete_transient($this->prefix.'dates'.$question_id);
		
		if ($this->is_published($question_id)) {
			$this->publish('insert', $question_id);
		}
		
		return true;
	}
	
	private function embedcode($question, $url, $question_url) {
		
		$script = add_query_arg(array('embed' => $question['question_id']), $url);
		//if (!empty($question_url)) {
		//	$script = add_query_arg(array('url' => $question_url), $script);
		//}
		
		return htmlentities('<script type="text/javascript" src="'.esc_url($script).'"></script>');
	}
	
	/**
	 * Parse $str and replace with known %%subs%% codes.
	 * 
	 * @param unknown_type $str
	 * @param unknown_type $blogname
	 * @param unknown_type $url
	 * @param unknown_type $user
	 * @param unknown_type $question
	 * @param unknown_type $reject_reason
	 * 
	 * @return Replacement string
	 */
	private function parse($str, $blogname, $url, $user, $question, $question_url, $reject_reason) {
		
		$str = str_replace('%%sitetitle%%', $blogname, $str);
		$str = str_replace('%%siteurl%%', $url, $str);
		$str = str_replace('%%rejectreason%%', $reject_reason, $str);
		$str = str_replace('%%user%%', $user->display_name, $str);
		$str = str_replace('%%question%%', $question['name'], $str);
		$str = str_replace('%%questionurl%%', $question_url, $str);
		$str = str_replace('%%embed%%', $this->embedcode($question, $url, $question_url), $str);
		
		return $str;
	}
	
	/**
	 * For Pending questions notify user who created question
	 * that it is not approved
	 * 
	 * @param unknown_type $question_id
	 */
	private function notify_rejection($question_id, $reject_reason) {
		
		if ($this->question_pending($question_id)) {
			
			$users = get_users(array('meta_key' => $this->prefix . 'question_' . $question_id));
			foreach ($users as $user) {
				update_user_meta($user->ID, $this->prefix.'question_' . $question_id, 'rejected');
			}
			
			$question = $this->get($question_id);
			$admin_email = get_option("admin_email");
			// The blogname option is escaped with esc_html on the way into the database in sanitize_option
			// we want to reverse this for the plain text arena of emails.
			$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
			$url = get_bloginfo('wpurl');
				
			$users = get_users(array('meta_key' => $this->prefix . 'question_' . $question_id));
			foreach ($users as $user) {
				
				$email = $user->user_email;
				$headers = "From: " . $admin_email . "\r\n";
				$headers .= "MIME-Version: 1.0\r\n";
				$headers .= "Content-Type: text/html; charset=utf-8\r\n";
				
				$subject = $this->parse(get_option($this->prefix.'reject_subject'), $blogname, $url, $user, $question, '', $reject_reason);
				$body = $this->parse(get_option($this->prefix.'reject_body'), $blogname, $url, $user, $question, '', $reject_reason);
				
				wp_mail($email, $subject, $body, $headers);
			}
		}
		
	}
	
	/**
	 * For Pending questions notify user who created question
	 * that it is approved
	 * 
	 * @param unknown_type $question_id
	 */
	private function notify_approval($question_id, $question_url, $post_id) {
		
		$question = $this->get($question_id);
		$question_url = get_permalink($post_id);
		$admin_email = get_option("admin_email");
		// The blogname option is escaped with esc_html on the way into the database in sanitize_option
		// we want to reverse this for the plain text arena of emails.
		$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
		$url = get_bloginfo('wpurl');
		
		$users = get_users(array('meta_key' => $this->prefix . 'question_' . $question_id));
		foreach ($users as $user) {
			
			$email = $user->user_email;
			$headers = "From: " . $admin_email . "\r\n";
			$headers .= "MIME-Version: 1.0\r\n";
			$headers .= "Content-Type: text/html; charset=utf-8\r\n";

			$subject = $this->parse(get_option($this->prefix.'approve_subject'), $blogname, $url, $user, $question, $question_url, '');
			$body = $this->parse(get_option($this->prefix.'approve_body'), $blogname, $url, $user, $question, $question_url, '');
				
			wp_mail($email, $subject, $body, $headers);
		}
	}
	
	
}

/*
 * Extend WP_List_Table to manage questions
 * 
 * See http://wordpress.org/extend/plugins/custom-list-table-example/
 * 
 */
class PredictWhenQuestion_Table extends WP_List_Table {
    
        
    var $prefix; // Plugin prefix
        
    var $key;	// Table primary key
    
    var $page_size = 10;
    
    var $caller;  // Object of caller
    
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
            'singular'  => 'question',     //singular name of the listed records
            'plural'    => 'questions',    //plural name of the listed records
            'ajax'      => false        //does this table support ajax?
        ) );
        
    }
    
    /**
    * Print column headers, accounting for hidden and sortable columns.
    *
    * @since 3.1.0
    * @access protected
    *
    * @param bool $with_id Whether to set the id attribute or not
    */
    function print_column_headers( $with_id = true ) {
    	$screen = get_current_screen();
    
    	list( $columns, $hidden, $sortable ) = $this->get_column_info();
    
    	$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    	$current_url = remove_query_arg( 'paged', $current_url );
    	$current_url = remove_query_arg( 'action', $current_url );
    	$current_url = remove_query_arg( '_wpnonce', $current_url );
    
    	$question_filter = 0;
    	if (isset($_REQUEST['question_filter'])) {
    		$question_filter = $_REQUEST['question_filter'];
    	}
    
    	if ( isset( $_GET['orderby'] ) )
    	$current_orderby = $_GET['orderby'];
    	else
    	$current_orderby = '';
    
    	if ( isset( $_GET['order'] ) && 'desc' == $_GET['order'] )
    	$current_order = 'desc';
    	else
    	$current_order = 'asc';
    
    	foreach ( $columns as $column_key => $column_display_name ) {
    		$class = array( 'manage-column', "column-$column_key" );
    
    		$style = '';
    		if ( in_array( $column_key, $hidden ) )
    		$style = 'display:none;';
    
    		$style = ' style="' . $style . '"';
    
    		if ( 'cb' == $column_key )
    		$class[] = 'check-column';
    		elseif ( in_array( $column_key, array( 'posts', 'comments', 'links' ) ) )
    		$class[] = 'num';
    
    		if ( isset( $sortable[$column_key] ) ) {
    			list( $orderby, $desc_first ) = $sortable[$column_key];
    
    			if ( $current_orderby == $orderby ) {
    				$order = 'asc' == $current_order ? 'desc' : 'asc';
    				$class[] = 'sorted';
    				$class[] = $current_order;
    			} else {
    				$order = $desc_first ? 'desc' : 'asc';
    				$class[] = 'sortable';
    				$class[] = $desc_first ? 'asc' : 'desc';
    			}
    
    			$column_display_name = '<a href="' . esc_url( add_query_arg( compact( 'orderby', 'order', 'question_filter'), $current_url ) ) . '"><span>' . $column_display_name . '</span><span class="sorting-indicator"></span></a>';
    		}
    
    		$id = $with_id ? "id='$column_key'" : '';
    
    		if ( !empty( $class ) )
    		$class = "class='" . join( ' ', $class ) . "'";
    
    		echo "<th scope='col' $id $class $style>$column_display_name</th>";
    	}
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
    function column_name($item) {
        
    	$key_value = $item[$this->key];
    	$name = $item['name'];
    	$notes = $item['notes'];
    	
    	$edit_url = esc_url(sprintf('?page=%s&action=%s&%s=%s', $_REQUEST['page'],'edit', $this->key, $key_value));
    	$approve_url = wp_nonce_url(sprintf('?page=%s&action=%s&%s=%s', $_REQUEST['page'],'approve', $this->key, $key_value),
    				$this->prefix.'approve');
    	$delete_url = wp_nonce_url(
    				sprintf('?page=%s&action=%s&%s=%s', $_REQUEST['page'],'delete', $this->key, $key_value),
    				$this->prefix.'delete');
    	$reject_url = wp_nonce_url(
    				sprintf('?page=%s&action=%s&%s=%s', $_REQUEST['page'],'reject', $this->key, $key_value),
    				$this->prefix.'reject');
    	$view_url = esc_url(sprintf('?page=%s&action=%s&%s=%s', $this->prefix.'prediction','view', $this->key, $key_value));
    	$close_url = esc_url(sprintf('?page=%s&action=%s&%s=%s', $_REQUEST['page'],'close', $this->key, $key_value));
    	$embed_url = sprintf("%s?post_title=%s&excerpt=%s&content=[predictwhen id=%d]&%squestion_id=%d", 'post-new.php', $name, $notes, $key_value, $this->prefix, $key_value);
    	$preview_url = esc_url(sprintf("?page=%s&action=%s&question_id=%d&iframe&TB_iframe=true&height=700&width=700", $_REQUEST['page'],'preview', $key_value));

    	$show_post_url = '';
    	if ($item['post_id']) {
	    	$show_post_url = esc_url(get_permalink($item['post_id']));
    	}
    	
    	 //admin_url('post-new.php')
    	
        //Build row actions
        $actions = array(
            'edit'      => sprintf('<a href="%s" title="%s">'.__('Edit', PW_TD).'</a>', 
        						$edit_url, sprintf(__('Edit &quot;%s&quot;', PW_TD), $name)),
            'previewq'  => sprintf('<a class="thickbox" href="%s" title="%s">'.__('Preview', PW_TD).'</a>', 
        						$preview_url, sprintf(__('Preview &quot;%s&quot;', PW_TD), $name)),
        );
        
        if ($this->caller->question_pending($item['question_id'])) {
        	$actions['approveq']	= sprintf('<a href="%s" title="%s">'.__('Approve', PW_TD).'</a>', 
        						$approve_url, sprintf(__('Approve this question', PW_TD), $name));
        }
        
        if ($this->caller->question_open($item['question_id'])) {
        	$actions['view'] = sprintf('<a href="%s" title="%s">'.__('Predictions', PW_TD).'</a>', 
        						$view_url, sprintf(__('View predictions for &quot;%s&quot;', PW_TD), $name));
        	if (!$item['post_id']) {
	        	$actions['embed'] = sprintf('<a href="%s" title="%s">'.__('Embed', PW_TD).'</a>', 
	        						$embed_url, sprintf(__('Embed this question in a new post', PW_TD), $name));
        	}
        	$actions['close'] = sprintf('<a href="%s" title="%s">'.__('Close', PW_TD).'</a>',
        						$close_url, sprintf(__('Close &quot;%s&quot;', PW_TD), $name));
        }
        
        if (!$this->caller->question_pending($item['question_id'])) {
        	if (!empty($show_post_url)) {
	        	$actions['show_post'] = sprintf('<a href="%s" title="%s">'.__('View', PW_TD).'</a>',
			        	$show_post_url, sprintf(__('View &quot;%s&quot;', PW_TD), $name));
        	}
        	 
	        $actions['delete'] = sprintf('<a href="%s" title="%s">'.__('Delete', PW_TD).'</a>',
					$delete_url, sprintf(__('Delete &quot;%s&quot;', PW_TD), $name));
        } else {
	        $actions['delete'] = sprintf('<a href="%s" title="%s">'.__('Reject', PW_TD).'</a>',
					$reject_url, sprintf(__('Reject &quot;%s&quot;', PW_TD), $name));
        }
        
        //Return the name contents
        return sprintf('<strong><a href="%s" title="%s">%s</a></strong>%s',
        	$edit_url,
        	sprintf(__('Edit &quot;%s&quot; %s', PW_TD), $name, $notes),
        	$name,
            $this->row_actions($actions)
        );
    }
    
    /**
     * Checkbox column
     * 
     * @see WP_List_Table::::single_row_columns()
     * @param array $item A singular item (one full row's worth of data)
     * @return string Text to be placed inside the column <td> (movie title only)
     **************************************************************************/
    function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            /*$1%s*/ $this->key,  //Key name
            /*$2%s*/ $item[$this->key] //The value of the checkbox should be the record's id
        );
    }
    
    function column_created($item) {
    	return $this->caller->nice_date($item['created']);
    }
    
    function column_publish($item) {
    	return $item['publish'] ? $this->caller->tick() : '';
    }
    
    function column_registration_required($item) {
    	return $item['registration_required'] ? $this->caller->tick() : '';
    }
    
    function column_status($item) {
    	
    	if ($item['status'] == 'open') {
    		return __('Open', PW_TD);
    	}
    	
       	if ($item['status'] == 'pending') {
    		return __('Pending', PW_TD);
    	}
    	
   		$out =  __('Closed', PW_TD);
   		$out .= '<div class="row-actions">';
		$out .= "<span class='".__('closed', PW_TD)."'>".$this->caller->nice_date($item['event_dt'], $item['event_tm'])."</span>";
   		$out .= '</div>';
    		
    	return $out;
    		
    }
    
    function column_predictions($item) {
    	return $item['predictions'];
    }
    
    function column_predicted_mean($item) {
    	if ($item['predictions']) {
    		$out =  $this->caller->nice_date($item['predicted_mean'], '', $item['date_interval']);
    		
    		return $out;
    	}
    	
    	return '';
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
            'cb'        => '<input type="checkbox" />', //Render a checkbox instead of text
        	$this->key  => __('ID', PW_TD),
            'name'      => __('Name', PW_TD),
            'created'	=> __('Created', PW_TD),
            'publish'	=> __('Publish', PW_TD),
            'registration_required' => __('Registration', PW_TD),
            'status'	=> __('Status', PW_TD),
            'predictions'	=> __('Predictions', PW_TD),
            'predicted_mean' => __('Predicted Date', PW_TD)
        );
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
            'name'			=> array('name',false),     		//true means its already sorted
            'created'		=> array('created', true),
            'publish'		=> array('publish', false),
            'registration_required' => array('registration_required', false),
            'status'		=> array('status', false),
            'predictions'	=> array('predictions', false),
            'predicted_mean' => array('predicted_mean', false)
        );
        return $sortable_columns;
    }
    
    
    /**
     * Setup bulk actions
     * 
     * @return array An associative array containing all the bulk actions: 'slugs'=>'Visible Titles'
     **************************************************************************/
    function get_bulk_actions() {
        $actions = array(
        	'bulkclose'		=> __('Close', PW_TD),
        	'bulkapprove'	=> __('Approve', PW_TD),
        	'bulkdelete'    => __('Delete', PW_TD)
        );
        return $actions;
    }
    
    function extra_tablenav( $which ) {
    	
    	if ($which == 'top') {
    		
    		$selected = -1;
    		
    		if (isset($_REQUEST['question_filter'])) {
    			$selected = $_REQUEST['question_filter'];
    		}
	    	?>
	    		<div class="alignleft actions">
	    		
	    		<select name="question_filter">
	    			<option <?php echo ($selected == 0 ? 'selected="selected"' : ''); ?> value="0"><?php _e('All questions', PW_TD); ?></option>
	    			<option <?php echo ($selected == 1 ? 'selected="selected"' : ''); ?> value="1"><?php _e('Open', PW_TD); ?></option>
	    			<option <?php echo ($selected == 2 ? 'selected="selected"' : ''); ?> value="2"><?php _e('Closed', PW_TD); ?></option>
	    			<option <?php echo ($selected == 3 ? 'selected="selected"' : ''); ?> value="3"><?php _e('Pending', PW_TD); ?></option>
	    		</select>
	    	<?php
    			submit_button( __( 'Filter' ), 'secondary', false, false, array( 'id' => 'questions-query-submit' ) );
		    ?>
	    		</div>
	    	<?php
    	}
    }
        
    /**
     * @uses $this->_column_headers
     * @uses $this->items
     * @uses $this->get_columns()
     * @uses $this->get_sortable_columns()
     * @uses $this->get_pagenum()
     * @uses $this->set_pagination_args()
     **************************************************************************/
    function prepare_items() {
    	global $wpdb;
        
    	
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
		 * Get and sort the current question list
		 */
        
        $search = (!empty($_REQUEST['s'])) ? $_REQUEST['s'] : '';
        $search = str_replace('%', '', $search);  // Lose % as messes with $wpdb->prepare
        
        if (!empty($search)) {
        	$search = "WHERE (name LIKE '%%$search%%')";
        }
        
        $selected = 0;
        if (isset($_REQUEST['question_filter'])) {
        	$selected = $_REQUEST['question_filter'];
        }
        
        if ($selected == 1) { // Open only
        	if (empty($search)) {
        		$search = 'WHERE status = "open"';
        	} else {
        		$search .= ' AND status = "open"';
        	}
        }
        
        if ($selected == 2) { // Closed only
            if (empty($search)) {
        		$search = 'WHERE status = "closed"';
        	} else {
        		$search .= ' AND status = "closed"';
        	}
        }
        
        if ($selected == 3) { // Pending only
            if (empty($search)) {
        		$search = 'WHERE status = "pending"';
        	} else {
        		$search .= ' AND status = "pending"';
        	}
        }
        
        $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'created'; //If no sort, default to created date
        $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'desc'; //If no order, default to desc
        
        if ($orderby == 'open') {
        	$orderby = "open $order, event_dt";
        }
        
        
		$sql = "SELECT question_id, name, notes, created, publish, registration_required, event_dt, event_tm,
					status, predicted_mean, post_id, date_interval,
					(SELECT COUNT(*) FROM {$wpdb->prefix}{$this->prefix}prediction p WHERE p.question_id = q.question_id) AS predictions
				FROM 
					{$wpdb->prefix}{$this->prefix}question q
				$search
				GROUP BY q.question_id
				ORDER BY $orderby $order , wwhen DESC";
					
		$data = $wpdb->get_results( $sql , ARRAY_A );
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
<?php
/**
 * WP Force Logout Process File.
 *
 * @package    WP Force Logout
 * @author     Sanjeev Aryal
 * @since      1.0.0
 * @license    GPL-3.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WP Force Logout Process Class.
 *
 * @class WP_Force_Logout_Process
 *
 * @since  1.0.0
 */
class WP_Force_Logout_Process {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'manage_users_columns', array( $this, 'add_column_title' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'add_column_value' ), 10, 3 );
		add_filter( 'manage_users_sortable_columns', array( $this, 'sortable_login_activity' ) );
		add_filter( 'users_list_table_query_args', array( $this, 'sortby_login_activity' ) );
		add_action( 'init', array( $this, 'update_online_users_status' ) );
		add_action( 'init', array( $this, 'update_last_login' ) );
		// add_action( 'load-users.php', array( $this, 'add_last_login' ) );
		add_action( 'load-users.php', array( $this, 'trigger_query_actions' ) );
		add_action( 'load-users.php', array( $this, 'trigger_bulk_actions' ) );
		add_filter( 'bulk_actions-users', array( $this, 'add_bulk_action' ) );
		add_action( 'restrict_manage_users', array( $this, 'add_all_users_logout' ), 1000 );
		add_action( 'wp_ajax_wp_force_logout_deactivation_notice', array( $this, 'deactivation_notice' ) );
		add_action( 'wp_ajax_wp_force_logout_send_deactivation_email', array( $this, 'deactivation_email' ) );
		add_action( 'in_admin_header', array( $this, 'review_notice' ), 100 );
		add_action( 'wp_ajax_wp_force_logout_dismiss_review_notice', array( $this, 'dismiss_review_notice' ) );
	}

	/**
	 * Enqueue Scripts
	 */
	public function enqueue_scripts() {

		global $pagenow;

		if ( 'plugins.php' === $pagenow || 'users.php' === $pagenow ) {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			wp_enqueue_style( 'wp-force-logout', plugins_url( 'assets/css/wp-force-logout.min.css', WP_FORCE_LOGOUT_PLUGIN_FILE ), array(), WPFL_VERSION, $media = 'all' );

			wp_enqueue_script( 'wp-force-logout-js', plugins_url( 'assets/js/admin/wp-force-logout' . $suffix . '.js', WP_FORCE_LOGOUT_PLUGIN_FILE ), array(), WPFL_VERSION, false );
			wp_enqueue_script( 'sweetalert', plugins_url( 'assets/js/admin/sweetalert.min.js', WP_FORCE_LOGOUT_PLUGIN_FILE ), array(), WPFL_VERSION, false );
			wp_localize_script(
				'wp-force-logout-js',
				'wpfl_plugins_params',
				array(
					'ajax_url'           => admin_url( 'admin-ajax.php' ),
					'deactivation_nonce' => wp_create_nonce( 'deactivation-notice' ),
					'review_nonce'       => wp_create_nonce( 'review-notice' ),
					'deactivating'       => __( 'Deactivating...', 'wp-force-logout' ),
					'error'              => __( 'Error!', 'wp-force-logout' ),
					'success'            => __( 'Success!', 'wp-force-logout' ),
					'deactivated'        => __( 'Plugin Deactivated!', 'wp-force-logout' ),
					'sad_to_see'         => __( 'Sad to see you leave!', 'wp-force-logout' ),
					'wrong'              => __( 'Oops! Something went wrong', 'wp-force-logout' ),
				)
			);
		}
	}

	/**
	 * Add the column title for the login activity column
	 *
	 * @param array $columns Columns.
	 *
	 * @return array
	 */
	public function add_column_title( $columns ) {

		if ( ! current_user_can( 'edit_user' ) ) {
			return $columns;
		}

		$new_columns['wpfl'] = __( 'Login Activity', 'wp-force-logout' );

		return $this->custom_insert_after_helper( $columns, $new_columns, 'cb' );
	}

	/**
	 * Insert Login Activity after first checkbox column
	 *
	 * @param  array  $columns     WP_User_List_Table columns.
	 * @param  array  $new_column  New Columns to insert.
	 * @param  string $after       Position of new column after.
	 * @return array  Columns.
	 */
	public function custom_insert_after_helper( $columns, $new_columns, $after ) {

		// Search for the item position and +1 since is after the selected item key.
		$position = array_search( $after, array_keys( $columns ) ) + 1;

		// Insert the new item.
		$return_columns  = array_slice( $columns, 0, $position, true );
		$return_columns += $new_columns;
		$return_columns += array_slice( $columns, $position, count( $columns ) - $position, true );

		return $return_columns;
	}

	/**
	 * Set the value for login activity column for each user in the users list
	 *
	 * @param string $value       Value to display
	 * @param string $column_name Column Name.
	 * @param int    $user_id        User ID.
	 *
	 * @return string
	 */
	public function add_column_value( $value, $column_name, $user_id ) {

		if ( ! current_user_can( 'edit_user' ) ) {
			return false;
		}

		if ( $column_name == 'wpfl' ) {
			$user = wp_get_current_user();

			$is_user_online = $this->is_user_online( $user_id );

			if ( $is_user_online ) {
				$logout_link = add_query_arg(
					array(
						'action' => 'wpfl-logout',
						'user'   => $user_id,
					)
				);
				$logout_link = remove_query_arg( array( 'new_role' ), $logout_link );
				$logout_link = wp_nonce_url( $logout_link, 'wpfl-logout' );

				$value  = '<span class="online-circle">' . __( 'Online', 'wp-force-logout' ) . '</span>';
				$value .= ' ';
				$value .= '<a style="color:red" href="' . esc_url( $logout_link ) . '">' . _x( 'Logout', 'The action on users list page', 'wp-force-logout' ) . '</a>';
			} else {
				$last_login = $this->get_last_login( $user_id );
				$value      = '<span class="offline-circle">' . __( 'Offline ', 'wp-force-logout' );
				$value     .= '</br>' . __( 'Last Login: ', 'wp-force-logout' );
				$value     .= ! empty( $last_login ) ? $last_login . ' ago' : __( 'Never', 'wp-force-logout' ) . '</span>';
			}
		}

		return $value;
	}

	/**
	 * Make login activity column sortable
	 *
	 * @param  array $columns Sortable columns.
	 * @since  1.2.2
	 *
	 * @return array
	 */
	public function sortable_login_activity( $columns ) {
		$columns['wpfl'] = 'wpfl';

		return $columns;
	}

	/**
	 *
	 * Sort users by login activity.
	 *
	 * @param array $args.
	 *
	 * @since 1.2.2
	 *
	 * @return array
	 */
	public function sortby_login_activity( $args ) {

		if ( isset( $args['orderby'] ) && 'wpfl' == $args['orderby'] ) {

			$order = isset( $args['order'] ) && $args['order'] === 'asc' ? 'desc' : 'asc';  // Wierd way of reversing the order. Still works.

			$args = array_merge(
				$args,
				array(
					'meta_key' => 'last_login',
					'orderby'  => 'meta_value',
					'order'    => $order,
					'fields'   => 'all',
				)
			);
		}

		$users = get_users( $args );

		return $args;
	}

	/**
	 * Checks if the current user is online or not.
	 *
	 * @param  int $user_id    User ID.
	 * @return boolean
	 */
	public function is_user_online( $user_id ) {

		// Get the online users list
		$logged_in_users = get_transient( 'online_status' );

		 // Online, if (s)he is in the list and last activity was less than 60 seconds ago
		return isset( $logged_in_users[ $user_id ] ) && ( $logged_in_users[ $user_id ] > ( time() - ( 1 * 60 ) ) );
	}

	/**
	 * Update online users status. Store in transient.
	 *
	 * @link  https://wordpress.stackexchange.com/a/34434/126847
	 * @return void.
	 */
	public function update_online_users_status() {

		// Get the user online status list.
		$logged_in_users = get_transient( 'online_status' );

		// Get current user ID
		$user = wp_get_current_user();

		// Check if the current user needs to update his online status;
		// Needs if user is not in the list.
		$no_need_to_update = isset( $logged_in_users[ $user->ID ] )

			// And if his "last activity" was less than let's say ...6 seconds ago
			&& $logged_in_users[ $user->ID ] > ( time() - ( 1 * 60 ) );

		// Update the list if needed
		if ( ! $no_need_to_update ) {
			$logged_in_users[ $user->ID ] = time();
			set_transient( 'online_status', $logged_in_users, $expire_in = ( 60 * 60 ) ); // 60 mins
		}
	}

	/**
	 * Store last login info in usermeta table.
	 *
	 * @since  1.1.0
	 *
	 * @return void.
	 */
	public function update_last_login() {

		$user_id = get_current_user_id();
		update_user_meta( $user_id, 'last_login', time() );
	}

	/**
	 * Add last_login meta key for all users with a random number to later compare.
	 *
	 * @todo :: this might gets slower for sites with large number of users.
	 *
	 * @since  1.2.2
	 *
	 * @return  void.
	 */
	public function add_last_login() {
		$users = get_users();
		foreach ( $users as $user ) {
			$last_login = get_user_meta( $user->ID, 'last_login', true );
			if ( empty( $last_login ) ) {
				update_user_meta( $user->ID, 'last_login', 0.00058373 );    // Store random number to identify.
			}
		}
	}

	/**
	 * Get last login time.
	 *
	 * @since  1.1.0
	 *
	 * @return string
	 */
	public function get_last_login( $user_id ) {
		$last_login     = get_user_meta( $user_id, 'last_login', true );
		$the_login_date = '';

		if ( ! empty( $last_login ) ) {
			$the_login_date = human_time_diff( $last_login );
		}

		return $the_login_date;
	}

	/**
	 * Trigger the action query logout
	 */
	public function trigger_query_actions() {

		if ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'force_logout_all' ) {
			check_admin_referer( 'wp-force-logout-nonce' );
			$this->force_all_users_logout();

			// Redirect to users/same page after logout.
			wp_safe_redirect( admin_url( 'users.php ' ) );
			exit();
		}

		// Return if current user cannot edit users.
		if ( ! current_user_can( 'edit_user' ) ) {
			throw new Exception( 'You donot have enough permission to perform this action' );
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : false;
		$mode   = isset( $_POST['mode'] ) ? $_POST['mode'] : false;

		// If this is a multisite, bulk request, stop now!
		if ( 'list' == $mode ) {
			return;
		}

		if ( ! empty( $action ) && 'wpfl-logout' === $action && ! isset( $_GET['new_role'] ) ) {

			check_admin_referer( 'wpfl-logout' );

			$redirect = admin_url( 'users.php' );
			$user_id  = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;

			if ( $action == 'wpfl-logout' ) {
				// Get all sessions for user with ID $user_id
				$sessions = WP_Session_Tokens::get_instance( $user_id );

				// We have got the sessions, destroy them all!
				$sessions->destroy_all();
			}

			wp_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Trigger the action query logout for bulk users.
	 */
	public function trigger_bulk_actions() {

		// Return if current user cannot edit users.
		if ( ! current_user_can( 'edit_user' ) ) {
			throw new Exception( 'You donot have enough permission to perform this action' );
		}

		if ( empty( $_REQUEST['users'] ) || empty( $_REQUEST['action'] ) && 'wpfl-bulk-logout' === $_REQUEST['action'] ) {
			return;
		}

		$user_ids = array_map( 'absint', $_REQUEST['users'] );

		foreach ( $user_ids as $user_id ) {

			// Get all sessions for user with ID $user_id
			$sessions = WP_Session_Tokens::get_instance( $user_id );

			// We have got the sessions, destroy them all!
			$sessions->destroy_all();
		}

		return admin_url( 'users.php' );
	}

	/**
	 * Add Bulk Logout Action.
	 *
	 * @param  array $actions    Current Actions.
	 * @return array    All Actions along with logout action.
	 */
	public function add_bulk_action( $actions ) {
		$actions['wpfl-bulk-logout'] = __( 'Logout', 'wp-force-logout' );

		return $actions;
	}

	/**
	 * All users logout functionality.
	 *
	 * @since  1.0.1
	 */
	public function add_all_users_logout( $which ) {
		echo '<div class="alignright">';
		$url = wp_nonce_url( 'users.php?action=force_logout_all', 'wp-force-logout-nonce' );
		echo '<a style="margin-left:5px; margin-top:0px" class="button wp-force-logout" href="' . $url . '">' . __( 'Logout All Users', 'wp-force-logout' ) . '</a>';
		echo '</div>';
	}

	/**
	 * All users logout functionality.
	 *
	 * @return void.
	 */
	public function force_all_users_logout() {

		$users = get_users();

		foreach ( $users as $user ) {

			// Get all sessions for user with ID $user_id
			$sessions = WP_Session_Tokens::get_instance( $user->ID );

			// We have got the sessions, destroy them all!
			$sessions->destroy_all();
		}
	}

	/**
	 * AJAX plugin deactivation notice.
	 *
	 * @since  1.0.0
	 */
	public static function deactivation_notice() {

		check_ajax_referer( 'deactivation-notice', 'security' );

		ob_start();
		global $status, $page, $s;
		$deactivate_url = wp_nonce_url( 'plugins.php?action=deactivate&amp;plugin=' . WP_FORCE_LOGOUT_PLUGIN_FILE . '&amp;plugin_status=' . $status . '&amp;paged=' . $page . '&amp;s=' . $s, 'deactivate-plugin_' . WP_FORCE_LOGOUT_PLUGIN_FILE );

		?>
			<!-- The Modal -->
			<div id="wp-force-logout-modal" class="wp-force-logout-modal">

				 <!-- Modal content -->
				 <div class="wp-force-logout-modal-content">
					<div class="wp-force-logout-modal-header">
					</div>

					<div class="wp-force-logout-modal-body">
						<div class="container">
							  <form method="post" id="wp-force-logout-send-deactivation-email">

								<div class="row">
										<h3 for=""><?php echo __( 'Would you care to let me know the deactivation reason so that I can improve it for you?', 'wp-force-logout' ); ?></h3>
									<div class="col-75">
										<textarea id="message" name="message" placeholder="Deactivation Reason?" style="height:150px"></textarea>
									</div>
								</div>
								<div class="row">
										<?php wp_nonce_field( 'wp_force_logout_send_deactivation_email', 'wp_force_logout_send_deactivation_email' ); ?>
										<a href="<?php echo $deactivate_url; ?>"><?php echo __( 'Skip and deactivate', 'wp-force-logout' ); ?>
										<input type="submit" id="wpfl-send-deactivation-email" value="Deactivate">
								</div>
						  </form>
						</div>

					<div class="wp-force-logout-modal-footer">
					</div>
				 </div>
			</div>

		<?php

		$content = ob_get_clean();
		wp_send_json( $content ); // WPCS: XSS OK.
	}

	/**
	 * Deactivation Email.
	 *
	 * @since  1.0.1
	 *
	 * @return void
	 */
	public function deactivation_email() {

		// check_ajax_referer( 'wp_force_logout_send_deactivation_email', 'security' );

		$message = sanitize_textarea_field( $_POST['message'] );

		if ( ! empty( $message ) ) {
			wp_mail( 'sanzeeb.aryal@gmail.com', 'WPForce Logout Deactivation', $message );
		}

		deactivate_plugins( WP_FORCE_LOGOUT_PLUGIN_FILE );
	}

	/**
	 * Outputs the Review notice on admin header.
	 *
	 * @since 1.2.1
	 */
	public function review_notice() {

		global $current_screen;

		// Show only to Admins
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice_dismissed = get_option( 'wpfl_review_notice_dismissed', 'no' );

		if ( 'yes' == $notice_dismissed ) {
			return;
		}

		if ( ! empty( $current_screen->id ) && $current_screen->id !== 'users' ) {
			return;
		}

		$logged_in_users = get_transient( 'online_status' );

		?>
			<div id="wp-force-logout-review-notice" class="notice notice-info wp-force-logout-review-notice">
				<div class="wp-force-logout-review-thumbnail">
					<img src="<?php echo plugins_url( 'assets/img/logo.jpg', WP_FORCE_LOGOUT_PLUGIN_FILE ); ?>" alt="">
				</div>
				<div class="wp-force-logout-review-text">

						<h3><?php _e( 'Whoopee! 😀', 'wp-force-logout' ); ?></h3>
						<p><?php _e( 'WPForce Logout already started displaying your ' . ( count( $logged_in_users ) - 1 ) . ' online users. Would you do us some favour and leave a <a href="https://wordpress.org/support/plugin/wp-force-logout/reviews/?filter=5#new-post" target="_blank">&#9733;&#9733;&#9733;&#9733;&#9733;</a> review on <a href="https://wordpress.org/support/plugin/wp-force-logout/reviews/?filter=5#new-post" target="_blank"><strong>WordPress.org</strong></a>? to help us spread the word and boost our motivation.', 'wp-force-logout' ); ?></p>

					<ul class="wp-force-logout-review-ul">
						<li><a class="button button-primary" href="https://wordpress.org/support/plugin/wp-force-logout/reviews/?filter=5#new-post" target="_blank"><span class="dashicons dashicons-external"></span><?php _e( 'Sure, I\'d love to!', 'wp-force-logout' ); ?></a></li>
						<li><a href="#" class="button button-secondary notice-dismiss"><span  class="dashicons dashicons-smiley"></span><?php _e( 'I already did!', 'wp-force-logout' ); ?></a></li>
						<li><a href="#" class="button button-link notice-dismiss"><span class="dashicons dashicons-dismiss"></span><?php _e( 'Never show again', 'wp-force-logout' ); ?></a></li>
					 </ul>
				</div>
			</div>
		<?php
	}

	/**
	 * Dismiss the reveiw notice on dissmiss click
	 *
	 * @since 1.2.1
	 */
	public function dismiss_review_notice() {

		check_admin_referer( 'review-notice', 'security' );

		if ( ! empty( $_POST['dismissed'] ) ) {
			update_option( 'wpfl_review_notice_dismissed', 'yes' );
		}
	}

}

new WP_Force_Logout_Process();

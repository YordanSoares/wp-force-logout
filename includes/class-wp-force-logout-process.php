<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * @class WP_Force_Logout_Process
 * @since  1.0.0
 */
Class WP_Force_Logout_Process {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'manage_users_columns', array( $this, 'add_column_title' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'add_column_value' ), 10, 3 );
		add_action( 'init', array( $this, 'update_online_users_status' ) );
		add_action( 'load-users.php', array( $this, 'trigger_query_actions' ) );
		add_filter( 'bulk_actions-users', array( $this, 'add_bulk_action' ) );
	}

	/**
	 * Enqueue Scripts
	 */
	public function enqueue_scripts() {

		wp_enqueue_style( 'wp-force-logout', plugins_url( '/wp-force-logout/assets/css/wp-force-logout.css' ), array(), WPFL_VERSION, $media = 'all' );
	}

	/**
	 * Add the column title for the login activity column
	 *
	 * @param array $columns
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
	 * @param  array  $columns     WP_User_List_Table columns
	 * @param  array  $new_column  New Columns to insert
	 * @param  string $after       Position of new column after
	 * @return array  Columns.
	 */
	public function custom_insert_after_helper( $columns, $new_columns, $after ) {

		// Search for the item position and +1 since is after the selected item key.
		$position = array_search( $after, array_keys( $columns ) ) + 1;

		// Insert the new item.
		$return_columns = array_slice( $columns, 0, $position, true );
		$return_columns += $new_columns;
		$return_columns += array_slice( $columns, $position, count( $columns ) - $position, true );

	    return $return_columns;
	}

	/**
	 * Set the value for login activity column for each user in the users list
	 *
	 * @param string $value 	  Value to display
	 * @param string $column_name Column Name.
	 * @param int $user_id		  User ID.
	 *
	 * @return string
	 */
	public function add_column_value( $value, $column_name, $user_id ) {

		if ( ! current_user_can( 'edit_user' ) ) {
			return false;
		}

		if ( $column_name == 'wpfl') {
			$user = wp_get_current_user();

			$is_user_online = $this->is_user_online( $user_id );

			if( $is_user_online ) {
				$logout_link = add_query_arg( array( 'action' => 'wpfl-logout', 'user' => $user_id ) );
				$logout_link = remove_query_arg( array( 'new_role' ), $logout_link );
				$logout_link = wp_nonce_url( $logout_link, 'wpfl-logout' );

        		$value   = '<span class="online-circle">Online</span>';
        		$value 	.= ' ';
				$value  .= '<a style="color:red" href="' . esc_url( $logout_link ) . '">' . _x( 'Logout', 'The action on users list page', 'user-registration' ) . '</a>';
		    } else {
		    	$value = '<span class="offline-circle">Offline</span>';
		    }
		}

		return $value;
	}

	/**
	 * Checks if the current user is online or not.
	 * @param  int 	$user_id  	User ID.
	 * @return boolean
	 */
	public function is_user_online($user_id) {

  		// Get the online users list
		$logged_in_users = get_transient('online_status');

 		 // Online, if (s)he is in the list and last activity was less than 6 seconds ago
  		return isset( $logged_in_users[ $user_id ] ) && ( $logged_in_users[ $user_id ] > ( current_time( 'timestamp' ) - ( 0.1 * 60 ) ) );
	}

	/**
	 * Update online users status. Store in transient.
	 * @link  https://wordpress.stackexchange.com/a/34434/126847
	 * @return void.
	 */
	public function update_online_users_status() {

		// Get the user online status list.
		$logged_in_users = get_transient('online_status');

		// Get current user ID
		$user = wp_get_current_user();

		// Check if the current user needs to update his online status;
		// Needs if user is not in the list.
		$no_need_to_update = isset( $logged_in_users[ $user->ID ] )

		    // And if his "last activity" was less than let's say ...6 seconds ago
		    && $logged_in_users[ $user->ID ] >  ( time() - ( 0.1 * 60 ) );

		// Update the list if needed
		if( ! $no_need_to_update ) {
		  $logged_in_users[ $user->ID ] = time();
		  set_transient( 'online_status', $logged_in_users, $expire_in = ( 60 * 60 ) ); // 60 mins
		}
	}

	/**
	 * Trigger the action query logout
	 */
	public function trigger_query_actions() {

		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : false;
		$mode    = isset( $_POST['mode'] ) ? $_POST['mode'] : false;

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
	 * Add Bulk Logout Action.
	 * @param  array    $actions    Current Actions.
	 * @return array    All Actions along with logout action.
	 */
	public function add_bulk_action( $actions ) {
		$actions['logout'] = __( 'Logout', 'wp-force-logout' );

		return $actions;
 	}
}

new WP_Force_Logout_Process();

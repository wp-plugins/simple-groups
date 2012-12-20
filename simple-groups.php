<?php
/*
Plugin Name: Simple Groups
Plugin URI: http://MyWebsiteAdvisor.com/tools/wordpress-plugins/simple-groups/
Description: Simple Groups
Version: 1.0
Author: MyWebsiteAdvisor
Author URI: http://MyWebsiteAdvisor.com
*/

class Simple_Groups{

	function __construct(){
	
		//create and register the groups custom taxonomy
		add_action('init', array(&$this,'register_groups_taxonomy'), 0 );
	
		//update group taxonomy when users are deleted
		add_action( 'delete_user', array(&$this,'delete_user_group_relationships') );
		

		/*
		manage groups taxonomy page
		*/
		//add the groups taxonomy admin page
		add_action( 'admin_menu', array(&$this,'add_group_admin_page') );
		
		// filter by Group
		add_action('pre_user_query', array(&$this, 'user_query'));	
				
		// add the user count column on the group page
		add_action( 'manage_group_custom_column', array(&$this,'manage_user_group_column'), 10, 3 );
		
		// Unsets the 'posts' column and adds a 'users' column on the manage groups admin page.
		add_filter( 'manage_edit-group_columns', array(&$this,'manage_group_taxonomy_user_column') );	
		
		// tell the tax page that its (menu) parent is the users page in the admin menu
		add_filter( 'parent_file', array(&$this,'fix_group_taxonomy_page_menu') );
		
	
		/*
		users list page
		*/
		//add group memberships column to the users.php page list table
		add_filter( 'manage_users_columns',  array(&$this,'add_manage_users_columns'), 15, 1);
		add_action( 'manage_users_custom_column', array(&$this, 'user_column_data'), 15, 3);
		
		//add Groups row action link on the users page list table
		add_filter( 'user_row_actions', array(&$this,'add_users_action_row_groups_link'), 10, 2 );
		
		
		/*
		user profile/edit user page
		*/
		// Add section to the edit user page in the admin to select group.
		add_action( 'show_user_profile', array(&$this, 'edit_user_group_section'), 25);
		add_action( 'edit_user_profile', array(&$this, 'edit_user_group_section'), 25);
		
		// Update the user groups when the edit user page is saved.
		add_action( 'personal_options_update', array(&$this, 'save_user_groups'));
		add_action( 'edit_user_profile_update', array(&$this, 'save_user_groups'));
		

	}

	
	// registers the custom 'groups' taxonomy
	function register_groups_taxonomy() {
	
		$labels = array(
			'name' 							=> __( 'Groups' ),
			'singular_name' 				=> __( 'Group' ),
			'menu_name' 					=> __( 'Groups' ),
			'search_items' 					=> __( 'Search Groups' ),
			'popular_items' 				=> __( 'Popular Groups' ),
			'all_items' 					=> __( 'All Groups' ),
			'edit_item' 					=> __( 'Edit Group' ),
			'update_item' 					=> __( 'Update Group' ),
			'add_new_item' 					=> __( 'Add New Group' ),
			'new_item_name' 				=> __( 'New Group Name' ),
			'separate_items_with_commas' 	=> __( 'Separate Groups with commas' ),
			'add_or_remove_items' 			=> __( 'Add or remove Groups' ),
			'choose_from_most_used' 		=> __( 'Choose from the most popular Groups' )
		);
		
		$params = array(
			'labels' 						=> $labels,
			'public' 						=> true,
			'hierarchical'    				=> true,
			'rewrite' => array(
				'with_front' 				=> true,
				'slug' 						=> 'users/groups' // Use 'author' (default WP user slug).
			),
			'capabilities' => array(
				'manage_terms' 				=> 'edit_users', // Using 'edit_users' cap to keep this simple.
				'edit_terms'  				=> 'edit_users',
				'delete_terms' 				=> 'edit_users',
				'assign_terms' 				=> 'read',
			),
			'update_count_callback' 		=> array(&$this, 'update_user_group_count') // Use a custom function to update the count.
		);
	
		 register_taxonomy(
			'group',
			'user',
			$params
		);
	}
	

	
	//displays groups related to user in the user row, group column
	function user_column_data($value, $column_name, $user_id) {
		switch($column_name) {
			case 'group':
				return $this->get_group_tags($user_id);
			  	break;
		}
		return $value;
	}
	
	//gets list of groups for a specific user (object)
	function get_groups($user = '') {
		if(is_object($user)) { $user_id = $user->ID; } elseif(is_int($user*1)) { $user_id = $user*1; }
		if(empty($user)) { return false;}
		$groups = wp_get_object_terms($user_id, 'group', array('fields' => 'all_with_object_id'));
		return $groups;
	}
	

	//generates list of links
	function get_group_tags($user, $page = null) {
		$terms = $this->get_groups($user);
		if(empty($terms)) { return false; }
		
		$in = array();
		foreach($terms as $term) {
			$link = empty($page) ? add_query_arg(array('group' => $term->slug), admin_url('users.php')) : add_query_arg(array('group' => $term->slug), $page);
			$in[] = sprintf('%s%s%s', '<a  href="'.$link.'" title="'.esc_attr($term->description).'">', $term->name, '</a>');
		}

		//return the list of groups styled similarly to post categories.
	  	return implode(', ', $in);
	}
	

	
	//for the users page to filter by group
	function user_query($Query = '') {
		global $pagenow,$wpdb;

		if('users.php' !== $pagenow) return; 

		if(!empty($_GET['group'])) {

			$groups = explode(',',$_GET['group']);
			$ids = array();
			foreach($groups as $group) {
				$term = get_term_by('slug', esc_attr($group), 'group');
				$user_ids = get_objects_in_term($term->term_id, 'group');
				$ids = array_merge($user_ids, $ids);
			}
			$ids = implode(',', wp_parse_id_list( $user_ids ) );

			$Query->query_where .= " AND $wpdb->users.ID IN ($ids)";
		}
	}


	//create the select group for edit users page.
	function edit_user_group_section( $user ) {

		$tax = get_taxonomy( 'group' );

		/* Make sure the user can assign terms of the group taxonomy before proceeding. */
		if ( !current_user_can( $tax->cap->assign_terms ) || !current_user_can('edit_users') )
			return;

		/* Get the terms of the 'profession' taxonomy. */
		$terms = get_terms( 'group', array( 'hide_empty' => false ) ); ?>

		<h3 id="user-groups">Groups</h3>
		<table class="form-table">
		<tr>
			<th>
				<label for="user-group" style="display:block;"><?php _e( sprintf(_n(__('Group Membership', 'user-groups'), __('Group Membership', 'simple-groups'), sizeof($terms)))); ?></label>
			</th>

			<td>
			<p><a href="<?php echo admin_url('edit-tags.php?taxonomy=group'); ?>"><?php _e('Manage Groups', 'simple-groups'); ?></a></p>
			
			<?php
			/* If there are any terms available, loop through them and display checkboxes. */
			if ( !empty( $terms ) ) {
			
				echo "<div style='border: 1px solid #ccc; width:90%; height: 100px; overflow-x:auto; overflow-y:auto; padding-left:10px; padding-right:50px;'>";
				echo '<ul>';
				foreach ( $terms as $term ) {
				?>
					<li>
					<input type="checkbox" name="group[]" id="user-group-<?php echo esc_attr( $term->slug ); ?>" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( true, is_object_in_term( $user->ID, 'group', $term->slug ) ); ?> /> 
					<label for="user-group-<?php echo esc_attr( $term->slug ); ?>"><?php echo $term->name; ?>: (<?php echo $term->description; ?>) </label></li>
				<?php 
				}
				echo '</ul>';
				echo "</div>";
			}

			/* If there are no user-group terms, display a message. */
			else {
				_e('There are no groups defined. <a href="'.admin_url('edit-tags.php?taxonomy=group').'">'.__('Add a Group', 'simple-groups').'</a>');
			}

			?></td>
		</tr>
	</table>
<?php
	}

	
	
	//save user info after the user edit page is saved
	function save_user_groups( $user_id, $groups = array(), $bulk = false) {

		$tax = get_taxonomy( 'group' );

		// Make sure the current user can edit the user and assign terms before proceeding.
		if ( !current_user_can( 'edit_user', $user_id ) && current_user_can( $tax->cap->assign_terms ) ) {
			return false;
		}

		if(empty($user_groups) && !$bulk) {
			$groups = @$_POST['group'];
		}

		if(is_null($groups) || empty($groups)) {
			wp_delete_object_term_relationships( $user_id, 'group' );
		} else {

			$saved_groups = array();
			foreach($groups as $group) {
				$saved_groups[] = esc_attr($group);
			}

			// saves the terms for the user. 
			wp_set_object_terms( $user_id, $saved_groups, 'group', false);
		}

		clean_object_term_cache( $user_id, 'group' );
	}

	
	//create the manage group page
	function add_group_admin_page() {
	
		$tax = get_taxonomy( 'group' );
	
		add_users_page(
			esc_attr( $tax->labels->menu_name ),
			esc_attr( $tax->labels->menu_name ),
			$tax->cap->manage_terms,
			'edit-tags.php?taxonomy=' . $tax->name
		);
	}


	
	//update the user count for each group
	function update_user_group_count( $terms, $taxonomy ) {
		global $wpdb;

		foreach ( (array) $terms as $term ) {

			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id = %d", $term ) );

			do_action( 'edit_term_taxonomy', $term, $taxonomy );
			$wpdb->update( $wpdb->term_taxonomy, compact( 'count' ), array( 'term_taxonomy_id' => $term ) );
			do_action( 'edited_term_taxonomy', $term, $taxonomy );
		}
	}
	
	
	// add group memberships column to the users.php page list table
	function add_manage_users_columns($defaults) {
		$defaults['group'] = __('Groups', 'group');
		return $defaults;
	}


	// add the user count column on the group page
	function manage_user_group_column( $display, $column, $term_id ) {
		if ( 'users' === $column ) {
			$term = get_term( $term_id, 'group' );
			echo '<a href="'.admin_url('users.php?group='.$term->slug).'">'.sprintf(_n(__('%s User'), __('%s Users'), $term->count), $term->count).'</a>';
		}
		return;
	}

	
	//add Groups row action link on the users page list table
	function add_users_action_row_groups_link( $actions, $user_object ) {
		if ( current_user_can( 'administrator', $user_object->ID ) )
			$actions['groups'] = '<a href="edit-tags.php?taxonomy=group">Groups</a>';
		return $actions;
	}

	
	
	// Create custom columns for the manage groups page.
	// Unsets the 'posts' column and adds a 'users' column on the manage groups admin page.
	function manage_group_taxonomy_user_column( $columns ) {
		unset( $columns['posts'] );
		$columns['users'] = __( 'Users' );
		return $columns;
	}

	
	
	//update group taxonomy when users are deleted
	function delete_user_group_relationships( $user_id ) {
		wp_delete_object_term_relationships( $user_id, 'group' );
	}
	

	
	// this is a custom tax applied to a user rather than a post or page, so WP gets a bit confused.
	// this filter function tell the tax page that its parent is the users page in the admin menu
	function fix_group_taxonomy_page_menu( $parent_file = '' ) {
		global $pagenow;
	
		if (!empty($_GET['taxonomy']) && 'group' == $_GET['taxonomy']  && 'edit-tags.php' == $pagenow){
			$parent_file = 'users.php';
		}
	
		return $parent_file;
	}




	// worker function for [group_access group="name"][/group_access] shortcode.
	function group_access_shortcode( $atts, $content = null ){
		if(is_user_logged_in() && isset($atts['group']) && "" !== $atts['group']){
		
			$user = wp_get_current_user();
			$terms = $this->get_groups($user);
			if(empty($terms)) { return false; }
			
			$groups = array();
			foreach($terms as $term){
				$groups[] = $term->name;
			}
			
			if(in_array($atts['group'], $groups)){
				return '<span>' . do_shortcode($content) . '</span>';
			}
		}
	}
	
	
	
	// worker function for [members_only][/members_only] shortcode.
	function members_only_shortcode( $atts, $content = null ){
		if(is_user_logged_in()){
			return '<span>' . do_shortcode($content) . '</span>';
		}
	}
	
	
}	// end of Simple_Groups Class




// instantiate new simple_groups object
$simple_groups = new Simple_Groups;




//add register shortcode functions
add_shortcode( 'members_only', array($simple_groups, 'members_only_shortcode') );
add_shortcode( 'group_access', array($simple_groups, 'group_access_shortcode') );



?>
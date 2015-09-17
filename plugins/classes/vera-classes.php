<?php
/*
Plugin Name: The Vera Project Classes
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

if( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/*** Register class post type and class_category taxonomy ***/
function vera_classes_init() {
  register_post_type( 'class_category',
    array(
      'label' => 'Class Category',
      'public' => false,
      'supports' => array( 'title' ),
      'rewrite' => array(
        'slug' => false
      )
    )
  );
  register_post_type( 'class',
    array(
      'labels' => array(
        'name' => __( 'Classes' ),
        'singular_name' => __( 'Class' ),
        'menu_name' => __( 'Classes [IN DEVELOPMENT]'),
        'add_new_item' => __( 'Add New Class'),
        'edit_item' => __( 'Edit Class'),
        'new_item' => __('New Class'),
        'view_item' => __('View Class'),
        'search_items' => __('Search Classes'),
        'not_found' => __('No classes found'),
        'not_found_in_trash' => __('No classes found in Trash'),
      ),
      'public' => true,
      'show_in_nav_menus' => true,
      'menu_position' => 9,
      'menu_icon' => 'dashicons-book-alt',
      'capability_type' => 'page',
      'supports' => array('title', 'editor', 'excerpt'),
      'rewrite' => array(
        'slug' => '_classes/detail',
        'with_front' => false,
      ),
    )
  );
}
add_action( 'init', 'vera_classes_init' );


/** Admin Interface **/

class Classes_Order_List_Table extends WP_List_Table {

  public $category;
  public $category_display_name;

  function __construct( $category ) {
    parent::__construct( array(
      'singular'=> 'class',
      'plural' => 'classes',
      'ajax'   => true,
    ) );
    $this->category = $category;
    $this->category_display_name = empty($category) ? "other classes" : $category . " classes";
  }

  function get_columns() {
    return $columns = array(
      'col_class_order' => __('Order'),
      'col_class_title' => __('Title'),
    );
  }

  function prepare_items() {
    $columns = $this->get_columns();
    $hidden = array();
    $sortable = array();
    $this->_column_headers = array($columns, $hidden, $sortable, 'col_class_title');
    $this->items = get_posts( array(
      'post_type' => 'class',
      'numberposts' => -1,
      'meta_key' => '_order',
      'orderby' => 'meta_value_num',
      'order' => 'ASC',
      'meta_query' => array(
        vera_category_query($this->category),
      ),
    ));
    foreach( $this->items as &$item ) {
      $item->order = (int) get_post_meta( $item->ID, "_order", true );
    }
  }

  function column_col_class_title($item) {
    return "<strong class=\"row-title\">$item->post_title</a></strong>"
         . "<p class=\"excerpt\">$item->post_excerpt</p>";
  }

  function column_col_class_order( $item ) {
    return "<div><a class=\"class-order-btn class-order-btn-up dashicons dashicons-arrow-up-alt2\" "
         . "href=\"admin-post.php?action=reorder_classes&class_id=$item->ID&order=". ($item->order-1) .'"></a></div>'
         . "<div><strong>$item->order</strong></div>"
         . "<div><a class=\"class-order-btn class-order-btn-down dashicons dashicons-arrow-down-alt2\" "
         . "href=\"admin-post.php?action=reorder_classes&class_id=$item->ID&order=". ($item->order+1) .'"></a></div>';
  }

  function display_tablenav( $which ) {
    // Use the tablenav top section as a secondary header
    switch( $which ) {
      case 'top':
        echo '<h3 style="margin-bottom:5px;">';
        echo ucwords($this->category_display_name) . ' <span class="count">(' . sizeof($this->items) . ')</span>';
        echo '</h3>';
        break;
      case 'bottom':
        break;
      default:
        parent::display_tablenav( $which );
    }
  }
}

function vera_edit_class_categories_cb() {
  echo '<div class="wrap">';
  echo '<h2>Class Categories</h2>';
  $categories = get_posts(array(
    'post_type' => 'class_category',
    'numberposts' => -1,
  ));
  $categories = array_map( function( $category ) {
    return $category->post_name;
  }, $categories);
  array_push($categories, '');
  foreach ( $categories as $category ) {
    echo '<div class="category-wrap">';
    $test_lt = new Classes_Order_List_Table($category);
    $test_lt->prepare_items();
    $test_lt->display();
    echo '</div>';
  }
  echo '</div>';
}

function vera_admin_init() {
  add_submenu_page( 'edit.php?post_type=class', 'Categories', 'Categories',
    'read', 'class-categories', 'vera_edit_class_categories_cb' );
}
add_action( 'admin_menu', 'vera_admin_init');


/*** Reorder classes endpoint ***/

function vera_reorder_classes() {
  // get querystring parameters
  // TODO: try/catch around $_REQUEST gets
  $class_id = (int) $_REQUEST['class_id'];
  $new_position = (int) $_REQUEST['order'];
  // get the post and verify input
  $post = get_post($class_id);
  if( empty($post) || $post->post_type != "class") {
    status_header(404);
    die("class not found");
  }
  $old_position = get_post_meta($post->ID, '_order', true);
  $category = get_post_meta($post->ID, '_category', true);
  if( $old_position === false ) {
    $old_position = vera_set_default_class_order($post->ID, $category);
  }
  $old_position = (int) $old_position;
  if( $old_position == $new_position ) {
    // nothing to do
    status_header(200);
    die();
  }
  // shift as needed
  if ($new_position < $old_position ) {
    $between = array($new_position, $old_position-1);
    $start_position = $new_position;
  } else {
    $between = array($old_position+1, $new_position);
    $start_position = $old_position;
  }
  $displaced_posts = get_posts( array(
    'post_type' => 'class',
    'meta_key' => '_order',
    'orderby' => 'meta_value_num',
    'order' => 'ASC',
    'meta_query' => array(
      'relation' => 'AND',
      array(
        'key' => '_order',
        'compare' => 'between',
        'value' => $between,
      ),
      vera_category_query($category),
    ),
  ));
  $displaced_ids = array_map(function($item) { return $item->ID; }, $displaced_posts);
  if ($old_position < $new_position) {
    array_push($displaced_ids, $class_id);
  } else {
    array_unshift($displaced_ids, $class_id);
  }
  $current_position = $start_position;
  foreach ($displaced_ids as $id) {
    update_post_meta($id, '_order', $current_position);
    $current_position++;
  }
  wp_redirect("/wp-admin/edit.php?post_type=class&page=class-categories");
  die();
}
add_action( 'admin_post_reorder_classes', 'vera_reorder_classes' );


/*** Add payment script metabox to class page ***/

function add_classes_metabox() {
  add_meta_box( 'payment_script', 'Payment Script URL', 'classes_payments_metabox_cb', 'class', 'normal' );
  add_meta_box( 'class_category', 'Class Category',     'classes_category_metabox_cb', 'class', 'side' );
}
add_action( 'add_meta_boxes', 'add_classes_metabox' );
function classes_payments_metabox_cb($post) {
  // Add a nonce field so we can check for it later.
  wp_nonce_field( 'classes_save_meta_box_data', 'classes_meta_box_nonce' );
  $value = get_post_meta( $post->ID, '_payment_script', true );
  echo '<input type="text" id="classes_payment_script" name="classes_payment_script"' .
              'value="' . esc_attr( $value ) . '" size="80" />';
  echo '<p>If provided, the payment script will be embedded at the end of the page.</p>';
}
function classes_category_metabox_cb( $post ) {
  // Pretty sure I only need one nonce.
  $value = get_post_meta( $post->ID, '_category', true );
  $categories = (new WP_Query( array('post_type' => 'class_category') ))->posts;
  echo '<ul class="form-no-clear">';
  foreach ($categories as $category) {
    $checked = $value == $category->post_name ? " checked" : "";
    echo '<li><label><input type="radio" name="class_category" value="' . $category->post_name . '"' .
         $checked . '>' . $category->post_title . '</label></li>';
  }
  $checked = $value == "" ? " checked" : "";
  echo '<li><label><input type="radio" name="class_category" value=""'. $checked . '>Other</label></li></ul>';
}
function classes_save_meta_box_data( $post_id ) {
  // We need to verify this came from our screen and with proper authorization,
  // because the save_post action can be triggered at other times.

  // Check if our nonce is set.
  if ( !isset($_POST['classes_meta_box_nonce']) ) { return; }
  // Verify that the nonce is valid.
  if ( !wp_verify_nonce($_POST['classes_meta_box_nonce'], 'classes_save_meta_box_data') ) { return; }
  // If this is an autosave, our form has not been submitted, so we don't want to do anything.
  if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) { return; }
  // Check the user's permissions.
  if ( !current_user_can('edit_page', $post_id) ) { return; }
  // Make sure that the fields are set
  if ( !isset($_POST['classes_payment_script']) || !isset($_POST['class_category'])) { return; }
  // Sanitize user input.
  $payment_script = sanitize_text_field( $_POST['classes_payment_script'] );
  $class_category = sanitize_text_field( $_POST['class_category'] );
  // Update the meta field in the database.
  update_post_meta( $post_id, '_payment_script', $payment_script );
  if( empty($class_category) ) {
    delete_post_meta( $post_id, '_category' );
  } else {
    update_post_meta( $post_id, '_category', $class_category );
  }
  // add default order field if this post is new.
  $order_meta = get_post_meta( $post_id, '_order');
  if( empty($order_meta) ) {
    vera_set_default_class_order($post_id, $class_category);
  }
}
function vera_category_query($category) {
  return array(
    'key' => '_category',
    'value' => $category,
    'compare' => empty($category) ? "NOT EXISTS" : "=",
  );
}
function vera_set_default_class_order($post_id, $class_category) {
  $max = get_posts(array(
    'post_type' => 'class',
    'numposts' => 1,
    'meta_key' => '_order',
    'meta_compare' => 'EXISTS',
    'orderby' => 'meta_value_num',
    'order' => 'DESC',
    'meta_query' => array(
      vera_category_query($class_category),
    ),
  ));
  $order = empty($max) ? 0 : get_post_meta($max[0]->ID, '_order', true) + 1;
  add_post_meta( $post_id, '_order', $order, true );
  return $order;
}
add_action( 'save_post', 'classes_save_meta_box_data' );

function vera_classes_content_filter($content) {
  /* This filter adds the payment script to the content */
  global $post;
  if ( get_post_type() == "class" ) {
    // append payment stuff
    $payment_script = get_post_meta( $post->ID, '_payment_script', true);

    if ( !empty($payment_script) ) {
      $content = sprintf(
          '%s<br/><br/>' . 
          '<i>(Please wait while the payment information loads below)</i>' .
          '<br/><br/><br/>' .
          '<script type="text/javascript" src="%s"></script>',
          $content,
          $payment_script
      );
    }
  }
  return $content;
}
add_filter('the_content', 'vera_classes_content_filter');

// /* make sorting by menu_order also group by class_category */
// function set_classes_admin_default_order($wp_query) {
//   if (is_admin()) {
//     $post_type = $wp_query->query['post_type'];
//     if ( $post_type == 'class' && $wp_query->get('orderby') == '' ) {
//       $wp_query->set('orderby', 'menu_order');
//       $wp_query->set('order', 'ASC');
//     }
//   }
// }
// add_filter('pre_get_posts', 'set_classes_admin_default_order');
//
// /*** remove 'Sort By Order' view introduced by the 'Simple Page Ordering' plugin ***/
// function vera_classes_remove_order_view($views) {
//   unset($views['byorder']);
//   return $views;
// }
// add_filter( 'views_edit-class', 'vera_classes_remove_order_view', 11 ); 

/*** Add admin styles ***/
function vera_classes_enqueue_admin_styles($hook) {
  if( $hook == 'class_page_class-categories' ) {
    wp_enqueue_style( 'vera_classes_categories', plugin_dir_url( __FILE__ ) . 'css/admin-class-categories.css', true );
  }
}
add_action( 'admin_enqueue_scripts', 'vera_classes_enqueue_admin_styles' );

?>
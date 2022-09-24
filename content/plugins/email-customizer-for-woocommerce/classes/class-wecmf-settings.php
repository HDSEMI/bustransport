<?php
/**
 * Woo Email Customizer Settings
 *
 * @author   ThemeHiGH
 * @category Admin
 */

if(!defined('ABSPATH')){ exit; }

if(!class_exists('WECMF_Settings')) :
class WECMF_Settings {
	protected static $_instance = null;	
	public $admin = null;
	public $frontend_fields = null;
	private $plugins_pages = null;
	private $template_version = '';

	public function __construct() {
		$required_classes = apply_filters('th_wecmf_require_class', array(
			'admin' => array(
				'classes/inc/class-wecmf-builder-settings.php',
				'classes/inc/class-wecmf-general-template.php',
				'classes/inc/class-wecmf-template-settings.php',
			),
			'common' => array(
				'classes/inc/class-wecmf-email-customizer-utils.php',
			),
		));
		
		$this->include_required( $required_classes );

		$this->plugin_pages = array(
			'toplevel_page_thwecmf_email_customizer',
			'email-customizer_page_thwecmf_email_mapping',
			'email-customizer_page_thwecmf_premium_features'
		);
		
		add_action( 'admin_init', array( $this, 'prepare_preview') );
		add_action( 'admin_init', array( $this, 'verify_nonce') );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_title', array( $this, 'set_wecmf_title' ), 10, 2 );
		add_filter( 'woocommerce_screen_ids', array( $this, 'add_screen_id' ) );
		add_filter( 'plugin_action_links_'.TH_WECMF_BASE_NAME, array($this, 'add_settings_link' ) );
		add_action( 'admin_body_class', array( $this, 'add_thwecmf_body_class') );
		$directory = $this->get_template_directory();
		!defined('THWECMF_CUSTOM_T_PATH') && define('THWECMF_CUSTOM_T_PATH', $directory);
		!defined('TH_WECMF_T_PATH') && define('TH_WECMF_T_PATH', TH_WECMF_PATH.'classes/inc/templates/');
		$this->init();
		!defined('THWECMF_LOGIN_USER') && define('THWECMF_LOGIN_USER', WECMF_Utils::get_logged_user_email());
	}

	public static function instance() {
		if(is_null(self::$_instance)){
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function prepare_preview(){
		if( isset( $_GET['preview'] ) ){
			$order_id = isset( $_GET['id'] ) ? absint( base64_decode( $_GET['id'] ) ) : false;
			$email_index = isset( $_GET['email'] ) ? sanitize_text_field( base64_decode( $_GET['email'] ) ) : false;
			$template = isset($_GET['preview']) ? sanitize_text_field( base64_decode( $_GET['preview'] ) ) : '';
			$content = $this->admin_instance->prepare_preview( $order_id, $email_index, $template, true );
			echo $this->render_preview( $content );
			die;
		}
	}

	public function render_preview( $content ){
		?>
		<html>
			<head>
				<title>Preview - Email Customizer for WooCommerce (Themehigh)</title>
				<style>
					body{
						margin: 0;
					}
				</style>
				
			</head>
			<body>
				<?php echo $content; ?>
				<script>
					var links = document.getElementsByClassName('thwecmf-link');
					var email = '';
					for (var i = 0; i < links.length; i++){
						email = links[i].innerHTML;
						links[i].innerHTML = '<a href="mailto:'+esc_attr( email )+'">'+esc_html( email )+'</a>';
					}
				</script>
			</body>
		</html>
		<?php
	}

	protected function get_template_directory(){
	    $upload_dir = wp_upload_dir();
	    $dir = $upload_dir['basedir'].'/thwec_templates';
      	$dir = trailingslashit($dir);
      	return $dir;
	}

	protected function include_required( $required_classes ) {
		foreach($required_classes as $section => $classes ) {
			foreach( $classes as $class ){
				if('common' == $section  || ('frontend' == $section && !is_admin() || ( defined('DOING_AJAX') && DOING_AJAX) ) 
					|| ('admin' == $section && is_admin()) && file_exists( TH_WECMF_PATH . $class )){
					require_once( TH_WECMF_PATH . $class );
				}
			}
		}
	}

	public function init() {	
		if(is_admin()){
			$this->admin_instance = WECMF_General_Template::instance();
		}
		add_filter('woocommerce_locate_template', array($this, 'thwecmf_woo_locate_template'), 999, 3);	
		add_filter('woocommerce_email_styles', array($this, 'thwecmf_woocommerce_email_styles') );
	}

	public function wecmf_capability() {
		$allowed = array('manage_woocommerce', 'manage_options');
		$capability = apply_filters('thwecmf_required_capability', 'manage_woocommerce');

		if(!in_array($capability, $allowed)){
			$capability = 'manage_woocommerce';
		}
		return $capability;
	}

	public function admin_menu() {
		global $wp;
		
		$page  = isset( $_GET['page'] ) ? esc_attr( $_GET['page'] ) : 'thwecmf_email_customizer';

		$capability = $this->wecmf_capability();
		$this->screen_id = add_menu_page(esc_attr__('Email Customizer'), esc_attr__('Email Customizer'), esc_html( $capability ), 'thwecmf_email_customizer', array($this, 'output_settings'), 'dashicons-admin-customizer', 56);
		add_submenu_page('thwecmf_email_customizer', esc_attr__('Templates'), esc_attr__('Templates'), $capability, 'thwecmf_email_customizer', array($this, 'output_settings'));
		add_submenu_page('thwecmf_email_customizer', esc_attr__('Email Mapping'), esc_attr__('Email Mapping'), esc_html( $capability ), 'thwecmf_email_mapping', array($this, 'output_settings'));
		add_submenu_page('thwecmf_email_customizer', esc_attr__('Pro Features'), esc_attr__('Pro Features'), esc_html( $capability ), 'thwecmf_premium_features', array($this, 'output_settings'));
		add_action('admin_print_scripts', array($this, 'disable_admin_notices'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
	}

	public function add_screen_id($ids){
		$ids[] = 'woocommerce_page_thwecmf_email_customizer';
		$ids[] = strtolower(__('WooCommerce', 'woocommerce')) .'_page_thwecmf_email_customizer';
		return $ids;
	}
	
	public function add_settings_link($links) {
		$settings_link = '<a href="'.admin_url('admin.php?page=thwecmf_email_customizer').'">'. esc_html__('Settings') .'</a>';
		array_unshift($links, $settings_link);
		$pro_link = '<a style="color:green; font-weight:bold" target="_blank" href="https://www.themehigh.com/product/woocommerce-email-customizer/?utm_source=free&utm_medium=plugin_action_link&utm_campaign=wec_upgrade_link">'. __('Get Pro', 'woo-email-customizer') .'</a>';
		array_push($links,$pro_link);
		return $links;
	}

	public function output_settings() {
		$page  = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : 'thwecmf_email_customizer';
		if( WECMF_Utils::edit_template( $page ) ){
			$fields_instance = WECMF_General_Template::instance();	
			$fields_instance->render_page();

		}else if( $page === "thwecmf_premium_features" ){
			$this->render_premium_contents();

		}else{	
			$fields_instance = WECMF_Template_Settings::instance();	
			$fields_instance->render_page($page);	

		}
	}

	public function disable_admin_notices(){
		$page  = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
		if( WECMF_Utils::edit_template( $page ) ){
			global $wp_filter;
      		if (is_user_admin() ) {
        		if (isset($wp_filter['user_admin_notices'])){
            		unset($wp_filter['user_admin_notices']);
        		}
      		} elseif(isset($wp_filter['admin_notices'])){
            	unset($wp_filter['admin_notices']);
      		}
      		if(isset($wp_filter['all_admin_notices'])){
        		unset($wp_filter['all_admin_notices']);
      		}
		}
	}

	public function thwecmf_woo_locate_template($template, $template_name, $template_path){
		$template_map = WECMF_Utils::thwecmf_get_template_map();
		if($template_map && strpos($template_name, 'emails/') !== false){ 
		    $search = array('emails/', '.php');
            $replace = array('', '');
		    $template_name_new = str_replace($search, $replace, $template_name);
			if(array_key_exists($template_name_new, $template_map)) {
    			$template_name_new = $template_map[$template_name_new];
    			if($template_name_new != ''){  
        			$email_template = $this->get_email_template($template_name_new);  
    				if($email_template){
    					return $email_template;
    				}
    			}		
    		}
    	}
       	return $template;
	}

	public function thwecmf_woocommerce_email_styles($buffer){
		$styles = WECMF_Utils::get_thwecmf_styles();
		return $buffer.$styles;
	}
	
	public function get_email_template($t_name){
    	$path = false;

    	$path = $this->get_email_template_path( $t_name );
    	if( $path ){
    	   	return $path;
    	}
    	
    	$path = $this->get_email_template_path( $t_name, true );
    	if( $path ){
    		return $path;
    	}

    	return $path;
    }

    public function get_email_template_path( $name, $default=false ){
    	$path = $default ? TH_WECMF_T_PATH .$name.'.php' : THWECMF_CUSTOM_T_PATH .$name.'.php';
    	return file_exists( $path ) ? $path : false;
    }

    public function render_advanced_content(){
    	?>
    	<div id="wecmf_builder_page_disabled">
    		<div class="wecmf-feature-access-wrapper">
    			<div class="wecmf-feature-access">
    				<p><b>Upgrade to Premium to access this feature</b></p>
    				<p>Goto <a href=>Templates</a> to edit and assign templates to email status</p>
    			</div>
    		</div>
    	</div>
    	<?php
    }

    public function verify_nonce(){
    	$template_details = isset($_POST['i_template_name']) ? sanitize_text_field($_POST['i_template_name']): false;
		if ( isset( $_POST['i_edit_template'] ) && $template_details ){
			if( !wp_verify_nonce( $_POST['thwecmf_edit_template_'.$template_details], 'thwecmf_edit_template'  ) || !WECMF_Utils::is_user_capable() ){
				wp_die( '<div class="wecm-wp-die-message">Action failed. Could not verify nonce.</div>' );
			}
		}
    }

    /**
	 * Add custom class to body classes
	 *
	 * @param  string $classes classes
	 * @return string $classes classes
	 */	
    public function add_thwecmf_body_class( $classes ){
    	$pages = array('thwecmf_email_customizer', 'thwecmf_email_mapping');
		$page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : false;
		if( in_array($page, $pages) ){
			$classes .= ' thwecmf-page';
		}
		if( $page === 'thwecmf_email_mapping' ){
			$classes .= ' thwecmf-mapping-page';
		}else if( $page === 'thwecmf_email_customizer' ){
			$classes .= isset( $_POST['i_edit_template'] ) ? ' thwecmf-builder-page' : ' thwecmf-template-page';
		}
		return $classes;
	}

	private function is_editor_page( $hook ){
		if( $hook === "toplevel_page_thwecmf_email_customizer" && isset($_POST["i_template_name"]) && !isset($_POST["reset_template"]) ){
			return true;
		}
		return false;
	}

	private function should_remove_unencoded(){
		$remove = false;
		if( $this->template_version === "2.3.0" ){
			$remove = true;
		}
		return apply_filters('thwecmf_remove_template_json_html', $remove);
	}

	public function enqueue_admin_scripts($hook){
		if(!in_array($hook, $this->plugin_pages)){
			return;
		}

		$additional = array();
		
		wp_enqueue_media();
		wp_enqueue_style (array('woocommerce_admin_styles', 'jquery-ui-style'));
		wp_enqueue_style ('thwecmf-admin-style', plugins_url('/assets/css/thwecmf-admin.min.css', dirname(__FILE__)), array(), TH_WECMF_VERSION);
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_style('raleway-style','https://fonts.googleapis.com/css?family=Raleway:400,600,800');
		if( $this->is_editor_page($hook) ){
			wp_enqueue_script( 'thwecmf-admin-script', plugins_url('/assets/js/thwecmf-editor.min.js', dirname(__FILE__)), ['wp-element', 'jquery'], TH_WECMF_VERSION, true );
			$additional = array(
	            'woo_orders' => $this->get_woo_orders(),
	            'woo_emails' => $this->get_woo_emails(),
	            'template' => $this->get_template_details(sanitize_text_field($_POST["i_template_name"])),
	            'bloginfo' => get_bloginfo(),
	            'testmail_recepient' => apply_filters('thwecmf_set_testmail_recepient', true) ? THWECMF_LOGIN_USER : "",
	            'admin_plugin_url' => TH_WECMF_ASSETS_URL,
	            'allowed_tags' => apply_filters('thwecmf_set_allowed_tags_in_text', ['b', 'strong', 'u', 'i', 'a']),
	            'remove_unencoded_html' => $this->should_remove_unencoded(),
	        );
		}else{
			wp_enqueue_script('thwecmf-admin-script', plugins_url('/assets/js/thwecmf-admin.min.js', dirname(__FILE__)), array('jquery', 'jquery-ui-core', 'jquery-ui-draggable', 'jquery-ui-droppable', 'jquery-ui-sortable', 'jquery-ui-dialog', 'jquery-tiptip', 'wc-enhanced-select', 'select2', 'wp-color-picker'), TH_WECMF_VERSION, true);
		}


		$wecmf_var = array(
            'admin_url' 	=> admin_url(),
            'ajaxurl'   	=> admin_url( 'admin-ajax.php' ),
            'ajax_nonce' 	=> wp_create_nonce('thwecmf_ajax_security'),
            'ajax_save' 	=> wp_create_nonce('thwecmf_ajax_save'),
            'ajax_banner_nonce' => wp_create_nonce('thwecmf_banner_ajax_security'),
            'tstatus'		=> WECMF_Utils::get_status(),
            'template_name' => isset( $_POST['i_template_name'] ) ? sanitize_text_field( $_POST['i_template_name'] ) : '',
            'preview_order' => wp_create_nonce( 'thwecmf_preview_order' ),
            'reset_preview' => wp_create_nonce('thwecmf_reset_preview'),
        );
		wp_localize_script('thwecmf-admin-script', 'thwecmf_admin_var', array_merge($wecmf_var, $additional));
	}

	/**
     * Get the selected template data 
     *
	 * @param  string $t_name template name of chosen template
	 * @return  array $template_data get chosen template data
     */
	private function get_template_details($t_name){
		$template_data = false;
		if($t_name){
			$t_list = WECMF_Utils::thwecmf_get_template_settings();
			if( WECMF_Utils::wecm_valid( $t_name, true ) ){
				$template_data = isset( $t_list['templates'][$t_name] ) ? WECMF_Utils::sanitize_template_data( $t_list['templates'][$t_name], true ) : WECMF_Utils::thwecmf_get_templates($t_name);
			}else{
				$this->thwecmf_invalid_template();
			}
			
			$template_display_name = isset ( $template_data['display_name'] ) ? sanitize_text_field( $template_data['display_name'] ) : '';
			$json_data = isset( $template_data['template_data'] ) ? wp_kses_post( $template_data['template_data'] ) : '';

			if($json_data == '' || $template_display_name == '' ){
				$this->thwecmf_invalid_template();
			}
			$this->template_version = isset( $template_data['version'] ) ? $template_data['version'] : "";
			return array(
				"display_name" => $template_display_name,
				"template_json" => $json_data,
				"is_react_template" => version_compare( $this->template_version, '2.3.0', '>=' ) ? 1 : 0,
			);
		}
		return $template_data;
	}

	/**
     * Redirect incase of invalid template
     *
     */
	private function thwecmf_invalid_template(){
		$url =  admin_url('admin.php?page=thwecmf_email_customizer');
		wp_redirect($url); 
	}

	private function get_woo_emails(){
		$woo_emails = [];
		$wc_emails = WC_Emails::instance();
		$wc_emails = isset( $wc_emails->emails ) ? $wc_emails->emails : false;
		if( $wc_emails ){
			foreach ($wc_emails as $wc_key => $wc_email) {
				if( !WECMF_Utils::is_compatible_email( $wc_email ) ){
					continue;
				}
				$woo_emails[$wc_key] = $wc_email->title;
			}
		}
		return $woo_emails;
	}

	private function get_woo_orders(){
		$woo_orders = [];
		$orders = WECMF_Utils::get_woo_orders();
		foreach ($orders as $key => $order) {
	    	$buyer = $this->get_buyer_info( $order );
	    	$order_id = $order->get_id();
	    	if( $buyer ){
				$user_string = sprintf( '(#%1$s) %2$s', $order_id, $buyer );
				$woo_orders[$order_id] = wp_kses_post( $user_string );
			}
		}
		return $woo_orders;
	}

	private function get_buyer_info( $order ){
		$buyer = false;
		if ( $order->get_billing_first_name() || $order->get_billing_last_name() ) {
			$buyer = trim( sprintf( _x( '%1$s %2$s', 'full name', 'woocommerce' ), $order->get_billing_first_name(), $order->get_billing_last_name() ) );
		} elseif ( $order->get_billing_company() ) {
			$buyer = trim( $order->get_billing_company() );
		} elseif ( $order->get_customer_id() ) {
			$user  = get_user_by( 'id', $order->get_customer_id() );
			$buyer = ucwords( $user->display_name );
		}
		return $buyer;
	}

	public function set_wecmf_title($admin_title, $title){
		if( isset($_POST["i_template_name"]) ){
			$template = str_replace("_", "", sanitize_text_field($_POST["i_template_name"]));
			$admin_title = str_replace($title, "Edit Template", $admin_title);
		}

		return $admin_title;
	}

	private function render_premium_contents(){
		?>
		<div class="thwecmf-premium">
			<div class="thwecmf-premium-header">
				<div class="thwecmf-premium-header-text">
					<h2>Premium Features</h2>
					<p>Email Customizer For WooCommerce plugin comes with several advanced features that let you create WooCommerce transactional emails reflecting your brand style. With these premium features of the plugin, you can create much better email templates and boost sales.</p>
				</div>
				<a class="button thwecmf-upgrade" href="https://www.themehigh.com/product/woocommerce-email-customizer/?utm_source=free&utm_medium=premium_tab&utm_campaign=wec_upgrade_link">Upgrade to Premium Version</a>
				<a class="button thwecmf-demo" href="https://flydemos.com/wecm/">Try Demo</a>
			</div>
			<div class="thwecmf-premium-body">
				<div class="thwecmf-premium-features">
					<p class="thwecmf-premium-title">Key Features of Email Customizer for Woocommerce</p>
					<p class="thwecmf-premium-subtitle">Following are some of the key features of the Email Customizer for WooCommerce plugin.</p>
					<div class="thwecmf-premium-features-list">
						<ul>
							<li>Improved plugin UI and builder.</li>
							<li>Comes with a user-friendly interface.</li>
							<li>Create unlimited templates for every WooCommerce Email.</li>
							<li>Let's you add a number of placeholders in the template to display dynamic data.</li>
							<li>Easy to choose saved templates for the corresponding transaction emails.</li>
						</ul>
						<ul>
							<li>Let's you import and export the created email templates.</li>
							<li>Option to add Email Subject to email.</li>
							<li>Compatible with WPML plugin.</li>
							<li>Compatible with Yith Gift Cards.</li>
							<li>Compatible with order status manager.</li>
						</ul>
					</div>
				</div>
				<div class="thwecmf-premium-features">
					<p class="thwecmf-premium-title">Custom Hooks</p>
					<p class="thwecmf-premium-subtitle">Custom hooks allow you to add dynamic content to your Email Template</p>
					<div class="thwecmf-premium-features-list">
						<p>Using this custom hook, you can provide order meta fields, include checkout field values, add the current email status like on hold, processing, and much more. Also, it allows the users to display the shortcode from third-party plugins.</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
endif;
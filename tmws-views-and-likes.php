<?php
/**
 * @package TMWS\ViewsAndLikes
 * @version 0.0.1
 *
 * Plugin Name: Views &amp; Likes
 * Plugin URI: https://www.templatemonster.com/services/list/
 * Description: Adds basic Views and Likes functionality to blog posts
 * Author: Ares & Tolumba
 * Version: 0.0.1
 *
*/

namespace TMWS\ViewsAndLikes {

	defined( 'ABSPATH' ) || exit;

	class ViewsAndLikes {

		private static $_instance;

		private const ENDPOINT_NAME = 'tmws-val-api';

		private $_meta_fields;

		private $_valid_renderers;

		private $_output_formats;

		public function __construct() {

			if(is_admin()) {
				return;
			}

			add_action( 'init', [$this, 'add_rewrite_endpoint'] );
			add_action( 'template_redirect', [$this, 'template_redirect'] );

			add_action( 'wp_enqueue_scripts', [$this, 'register_scripts'] );

			add_shortcode( 'tmws_val', [$this, 'shortcode'] );

			add_action( 'tmws_val_render', [$this, 'render_action'] );
			add_action( 'tmws_val_add_data', [$this, 'add_data'], 10, 2 );

			add_action( 'wp_body_open', [$this, 'wp_body_open'] );

			$this->_meta_fields = apply_filters( 'TMWS\\ViewsAndLikes\\ViewsAndLikes-meta_fields', [
				'view'    => 'post_views',
				'like'    => 'post_likes',
				'dislike' => 'post_dislikes',
			]);

			$this->_valid_renderers = apply_filters('TMWS\\ViewsAndLikes\\ViewsAndLikes-valid_renderers', [
				'view', 'like', 'dislike'
			]);

			$this->_output_formats = apply_filters('TMWS\\ViewsAndLikes\\ViewsAndLikes-output_formats', [
				'single' => [
					'view'    => 'view',
					'like'    => 'like',
					'dislike' => 'dislike',
				],
				'plural' => [
					'view'    => 'view',
					'like'    => 'like',
					'dislike' => 'dislike',
				],
			]);
		}

		public function add_rewrite_endpoint() {
			add_rewrite_endpoint(self::ENDPOINT_NAME, EP_ALL);
		}

		public function template_redirect() {
			global $wp_query;

			if ( ! ( isset( $wp_query->query_vars['name'] ) && self::ENDPOINT_NAME === $wp_query->query_vars['name'] ) ) {
				return;
			}

			//$this->view_field = 'post_views'
			$actions = join( '|', array_keys($this->_meta_fields) );
			$settings = [
				'action' => [
					'filter'  => FILTER_VALIDATE_REGEXP,
					'options' => [
						'regexp' => "`^(" . $actions . ")$`",
						'default' => 'unknown',
					]
				],
				'post_id' => [
					'filter' => FILTER_VALIDATE_INT,
				]
			];

			$input = filter_input_array(INPUT_POST, $settings);

			if (! ($input && is_array($input))) {
				$this->_error_response(esc_html__('No or invalid input', 'tmws_val'));
			}

			extract($input);

			if (! ($action && 'unknown' != $action)) {
				$this->_error_response(esc_html__('Invalid action', 'tmws_val'));
			}

			if( ! $post_id ) {
				$this->_error_response(esc_html__('No post id provided', 'tmws_val'));
			}

			$result = ['action' => $action];

			try {
				$result = array_merge($result, $this->set_data($post_id, $action));
			} catch (Exception $e) {
				$this->_error_response($e->getMessage());
			}

			$result['html'] = [];
			foreach ($this->_valid_renderers as $type) {
				$result['html'][$type] = $this->render_data($type, $post_id);
			}

			$this->_success_response($result);
		}

		public function register_scripts() {

			wp_register_script('tmws-val-main', $this->_assets_url('js/main.js'), array('jquery'), null, true);
			wp_localize_script('tmws-val-main', 'tmws_options', apply_filters('TMWS\\ViewsAndLikes\\ViewsAndLikes-main_js_data', [
				'endpoint' => self::ENDPOINT_NAME,
				'actions' => array_combine(array_keys($this->_meta_fields), array_keys($this->_meta_fields)),
			]));
			wp_enqueue_script('tmws-val-main');
		}

		public function wp_body_open() {

			if( is_a( get_queried_object(), 'WP_Post' ) ) {

				$post_id = get_queried_object_id();

				$this->set_data($post_id, 'view');
			}
		}

		public function shortcode( $atts, $content='', $shortcode_name='tmws_val' ) {

			extract(shortcode_atts([
				'type' => $this->_valid_renderers
			], $atts, $shortcode_name));

			if (empty($type)) {
				$type = $this->_valid_renderers;
			}

			if ( !is_array($type) && is_string($type) ) {
				$type = preg_split('`(,|;|\s)+`', $type);
			}

			return $this->_render_data_html($type);
		} 

		public function render_action( $type, $post_id=null, $echo=true ) {

			$html = $this->_render_data_html($type, $post_id);

			if($echo) {
				echo $html;
			} else {
				return $html;
			}
		}

		public function render_data($type, $post_id=null) {

			if (! $post_id) {
				$post_id = get_the_ID();
			}

			if (! $post_id) {
				return '';
			}

			global $post;
			if( ! $post ) {
				$post = get_post($post_id);
			}

			$count = $this->get_data_count($post_id, $type);

			return sprintf( _n( $this->{$type . '_format_single'}, $this->{$type . '_format_plural'}, $count, 'tmws_val' ), number_format_i18n( $count ) );
		}

		// Meta data
		public function get_data($post_id, $action) {

			if(! is_string($this->{$action . '_field'})) {
				return false;
			}

			$data = maybe_unserialize(get_post_meta($post_id, $this->{$action . '_field'}, true));

			if ('view' === $action) {
				return empty($data)? 0 : $data;
			} else if (is_array($data)) {
				return $data;
			} else {
				return [];
			}
		}

		public function get_data_count($post_id, $action) {

			$data = $this->get_data($post_id, $action);

			if (is_array($data)) {
				return count($data);
			}

			return (int) $data;
		}

		public function set_data($post_id, $action) {

			if ( ! is_numeric($post_id)) {
				return [
					'message' => esc_html__( 'Invalid user ID', 'tmws_val' ),
				];
			}

			if(! is_string($this->{$action . '_field'})) {
				return [
					'message' => sprintf( esc_html__( 'Invalid action "%s"', 'tmws_val' ), $action ),
				];
			}

			$data = $this->get_data($post_id, $action);

			if ( 'view' === $action ) {

				empty($data) ? $data = 1 : $data++;
				update_post_meta($post_id, $this->view_field, $data);

				return [
					'message' => esc_html__( 'View added', 'tmws_val' ),
				];
			}

			$user_id = get_current_user_id();

			if ( 0 === $user_id ) {
				return [
					'message' => esc_html__( 'Invalid user', 'tmws_val' ),
				];
			}

			if ( 'like' === $action ) {
				$this->unset_data($post_id, 'dislike');
			}

			if ( 'dislike' === $action ) {
				$this->unset_data($post_id, 'like');
			}

			if ( in_array($user_id, $data) ) {
				return $this->unset_data($post_id, $action, $data);
			}

			array_push($data, $user_id);

			if (FALSE !== update_post_meta($post_id, $this->{$action . '_field'}, serialize($data))) {
				return [
					'message' => sprintf( esc_html__( '%s added', 'tmws_val' ), ucfirst($action) ),
				];
			} else {
				return [
					'message' => sprintf( esc_html__( 'Failed to add %s', 'tmws_val' ), ucfirst($action) ),
				];
			}
		}

		public function unset_data($post_id, $action, $data = null) {

			if ( ! is_numeric($post_id)) {
				return [
					'message' => esc_html__( 'Invalid user ID', 'tmws_val' ),
				];
			}

			if(! is_string($this->{$action . '_field'})) {
				return [
					'message' => sprintf( esc_html__( 'Invalid action "%s"', 'tmws_val' ), $action ),
				];
			}

			if(! is_array($data)) {
				$data = $this->get_data($post_id, $action);
			}

			$user_id = get_current_user_id();

			if ( 0 === $user_id ) {
				return [
					'message' => esc_html__( 'Invalid user', 'tmws_val' ),
				];
			}

			$index = array_search($user_id, $data);

			if( FALSE !== $index ) {
				$data[$index] = false;
			} else {
				return [
					'message' => esc_html__( 'Nothing to remove', 'tmws_val' ),
				];
			}

			$data = array_filter($data);

			if (FALSE !== update_post_meta($post_id, $this->{$action . '_field'}, serialize($data))) {
				return [
					'message' => sprintf( esc_html__( '%s removed', 'tmws_val' ), ucfirst($action) ),
				];
			} else {
				return [
					'message' => sprintf( esc_html__( 'Failed to remove %s', 'tmws_val' ), ucfirst($action) ),
				];
			}
		}

		public function load_template($template, $args=[]) {

			$template_located = locate_template('tmws-views-and-likes/' . $template . '.php');

			if(empty($template_located)) {
				$template_located  = trailingslashit( $this->plugin_dir . 'templates' ) . $template . '.php';
			}

			if( empty($template_located) || ! file_exists($template_located) ) {
				return '';
			}

			try {
				ob_start();
				load_template($template_located, false, $args);
				return ob_get_clean();
			} catch (Exception $e) {
				error_log($e->getMessage());
			}

			return '';
		}

		private function _render_data_html( $type, $post_id=null ) {

			$output = '';

			foreach ($type as $value) {

				if(in_array($value, $this->_valid_renderers)) {
					$output .= $this->render_data($value, $post_id);
				}
			}

			return apply_filters( 'TMWS\\ViewsAndLikes\\ViewsAndLikes-html_output', $output );
		}

		private function _error_response($message = null) {

			header( 'Content-Type: application/json' );
			header( 'HTTP/1.1 401 Error' );
			$result = [
				'result'  => 'error',
				'message' => (string) $message,
			];
			echo wp_json_encode($result);
			exit;
		}

		private function _success_response($data = null) {

			header( 'Content-Type: application/json' );
			header( 'HTTP/1.1 200 OK' );

			$result = [
				'result' => 'success',
				'data'   => (array) $data,
			];

			echo wp_json_encode($result);
			exit;
		}

		private function _assets_url( $relpath ) {

			return trailingslashit( $this->plugin_url . 'assets' ) . $relpath;
		}

		public function __get($var) {

			if ( 'plugin_url' === $var ) {
				return plugin_dir_url(__FILE__);
			}

			if ( 'plugin_dir' === $var ) {
				return plugin_dir_path(__FILE__);
			}

			if ( 0 !== preg_match( '`^(\w+)_field$`', $var, $maches ) ) {
				
				if ( array_key_exists( $maches[1], $this->_meta_fields )) {
					return $this->_meta_fields[$maches[1]];
				}
			}

			if ( 0 !== preg_match( '`^(\w+)_format_(single|plural)$`', $var, $maches ) ) {
				
				if ( array_key_exists( $maches[2], $this->_output_formats )) {
					return $this->load_template($this->_output_formats[$maches[2]][$maches[1]]);
				}
			}

			return FALSE;
		}

		public static function instance() {
			if(is_null(self::$_instance)) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}
	}
}

namespace {
	if(! function_exists('tmws_val')) {
		function tmws_val() {
			return TMWS\ViewsAndLikes\ViewsAndLikes::instance();
		}
	}

	tmws_val();
}

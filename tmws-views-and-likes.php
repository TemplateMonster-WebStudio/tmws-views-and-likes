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

		private $meta_fields;

		public function __construct() {

			if(is_admin()) {
				return;
			}

			add_action( 'init', [$this, 'add_rewrite_endpoint'] );
			add_action( 'template_redirect', [$this, 'template_redirect'] );

			$this->meta_fields = apply_filters( 'TMWS\\ViewsAndLikes\\ViewsAndLikes-meta_fields', [
				'view'    => 'post_views',
				'like'    => 'post_likes',
				'dislike' => 'post_dislikes',
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

			//$this->views_field = 'post_views'
			$settings = [
				'action' => [
					'filter'  => FILTER_VALIDATE_REGEXP,
					'options' => [
						'regexp' => '`^(view|like|dislike)$`',
						'default' => 'unknown',
					]
				],
				'post_id' => [
					'filter' => FILTER_VALIDATE_INT,
				]
			];

			//extract(filter_input_array(INPUT_POST, $settings));

			$input = filter_input_array(INPUT_GET, $settings);

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
				$result = $this->set_data($post_id, $action);
			} catch (Exception $e) {
				$this->_error_response($e->getMessage());
			}

			//get_current_user_id

			$this->_success_response($result);
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

		public function set_data($post_id, $action) {

			if ( ! is_numeric($post_id)) {
				return [
					'message' => esc_html__( 'Invalid user ID', 'tmws_val' ),
				];
			}

			error_log(print_r($this->{$action . '_field'}, true));

			if(! is_string($this->{$action . '_field'})) {
				return [
					'message' => sprintf( esc_html__( 'Invalid action "%s"', 'tmws_val' ), $action ),
				];
			}

			$data = $this->get_data($post_id, $action);

			if ( 'view' === $action ) {

				empty($data) ? $data = 1 : $data++;
				update_post_meta($post_id, $this->views_field, $data);

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

		public function __get($var) {

			error_log($var);

			if ( 0 === preg_match( '`^(\w+)_field$`', $var, $maches ) ) {
				return FALSE;
			}

			if ( array_key_exists( $maches[1], $this->meta_fields )) {
				return $this->meta_fields[$maches[1]];
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

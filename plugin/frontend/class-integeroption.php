<?php
namespace Sgdg\Frontend;

require_once 'class-option.php';

class IntegerOption extends Option {
	public function register() {
		register_setting( 'sgdg', $this->name, [
			'type'              => 'integer',
			'sanitize_callback' => [ $this, 'sanitize' ],
		]);
	}

	public function sanitize( $value ) {
		if ( is_numeric( $value ) ) {
			return intval( $value );
		}
		return $this->default_value;
	}

	public function html() {
		echo( '<input type="text" name="' . esc_attr( $this->name ) . '" value="' . esc_attr( get_option( $this->name, $this->default_value ) ) . '" class="regular-text">' );
	}
}
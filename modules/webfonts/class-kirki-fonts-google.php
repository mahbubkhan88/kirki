<?php
/**
 * Processes typography-related fields
 * and generates the google-font link.
 *
 * @package     Kirki
 * @category    Core
 * @author      Aristeides Stathopoulos
 * @copyright   Copyright (c) 2017, Aristeides Stathopoulos
 * @license     http://opensource.org/licenses/https://opensource.org/licenses/MIT
 * @since       1.0
 */

/**
 * Manages the way Google Fonts are enqueued.
 */
final class Kirki_Fonts_Google {

	/**
	 * The Kirki_Fonts_Google instance.
	 * We use the singleton pattern here to avoid loading the google-font array multiple times.
	 * This is mostly a performance tweak.
	 *
	 * @access private
	 * @var null|object
	 */
	private static $instance = null;

	/**
	 * If set to true, forces loading ALL variants.
	 *
	 * @static
	 * @access public
	 * @var bool
	 */
	public static $force_load_all_variants = false;

	/**
	 * If set to true, forces loading ALL subsets.
	 *
	 * @static
	 * @access public
	 * @var bool
	 */
	public static $force_load_all_subsets = false;

	/**
	 * The array of fonts
	 *
	 * @access private
	 * @var array
	 */
	private $fonts = array();

	/**
	 * An array of all google fonts.
	 *
	 * @access private
	 * @var array
	 */
	private $google_fonts = array();

	/**
	 * The array of subsets
	 *
	 * @access private
	 * @var array
	 */
	private $subsets = array();

	/**
	 * The google link
	 *
	 * @access private
	 * @var string
	 */
	private $link = '';

	/**
	 * Which method to use when loading googlefonts.
	 * Available options: link, js, embed.
	 *
	 * @static
	 * @access private
	 * @since 3.0.0
	 * @var string
	 */
	private static $method = array(
		'global' => 'embed',
	);

	/**
	 * Whether we should fallback to the link method or not.
	 *
	 * @access private
	 * @since 3.0.0
	 * @var bool
	 */
	private $fallback_to_link = false;

	/**
	 * The class constructor.
	 */
	private function __construct() {

		$config = apply_filters( 'kirki/config', array() );

		// Get the $fallback_to_link value from transient.
		$fallback_to_link = get_transient( 'kirki_googlefonts_fallback_to_link' );
		if ( 'yes' === $fallback_to_link ) {
			$this->fallback_to_link = true;
		}

		// Use links when in the customizer.
		global $wp_customize;
		if ( $wp_customize ) {
			$this->fallback_to_link = true;
		}

		// If we have set $config['disable_google_fonts'] to true then do not proceed any further.
		if ( isset( $config['disable_google_fonts'] ) && true === $config['disable_google_fonts'] ) {
			return;
		}

		// Populate the array of google fonts.
		$this->google_fonts = Kirki_Fonts::get_google_fonts();

		// Process the request.
		$this->process_request();

	}

	/**
	 * Processes the request according to the method we're using.
	 *
	 * @access protected
	 * @since 3.0.0
	 */
	protected function process_request() {

		// Figure out which method to use for all.
		$method = 'link';
		foreach ( self::$method as $config_id => $method ) {
			$method = apply_filters( "kirki/{$config_id}/googlefonts_load_method", 'link' );
			if ( 'embed' === $method && true !== $this->fallback_to_link ) {
				$method = 'embed';
			}
		}
		// Force using the JS method while in the customizer.
		// This will help us work-out the live-previews for typography fields.
		if ( is_customize_preview() ) {
			$method = 'async';
		}
		foreach ( self::$method as $config_id => $config_method ) {

			switch ( $method ) {

				case 'embed':
					add_filter( "kirki/{$config_id}/dynamic_css", array( $this, 'embed_css' ) );

					if ( true === $this->fallback_to_link ) {
						// Fallback to enqueue method.
						add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 105 );
					}
					break;
				case 'async':
					add_action( 'wp_head', array( $this, 'webfont_loader' ) );
					break;
				case 'link':
					// Enqueue link.
					add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 105 );
					break;
			}
		}
	}

	/**
	 * Get the one, true instance of this class.
	 * Prevents performance issues since this is only loaded once.
	 *
	 * @return object Kirki_Fonts_Google
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new Kirki_Fonts_Google();
		}
		return self::$instance;
	}

	/**
	 * Calls all the other necessary methods to populate and create the link.
	 */
	public function enqueue() {

		// Go through our fields and populate $this->fonts.
		$this->loop_fields();

		$this->fonts = apply_filters( 'kirki/enqueue_google_fonts', $this->fonts );

		// Goes through $this->fonts and adds or removes things as needed.
		$this->process_fonts();

		// Go through $this->fonts and populate $this->link.
		$this->create_link();

		// If $this->link is not empty then enqueue it.
		if ( '' !== $this->link ) {
			wp_enqueue_style( 'kirki_google_fonts', $this->link, array(), null );
		}
	}

	/**
	 * Goes through all our fields and then populates the $this->fonts property.
	 */
	private function loop_fields() {
		foreach ( Kirki::$fields as $field ) {
			$this->generate_google_font( $field );
		}
	}

	/**
	 * Processes the arguments of a field
	 * determines if it's a typography field
	 * and if it is, then takes appropriate actions.
	 *
	 * @param array $args The field arguments.
	 */
	private function generate_google_font( $args ) {

		// Process typography fields.
		if ( isset( $args['type'] ) && 'kirki-typography' === $args['type'] ) {

			// Get the value.
			$value = Kirki_Values::get_sanitized_field_value( $args );

			// If we don't have a font-family then we can skip this.
			if ( ! isset( $value['font-family'] ) ) {
				return;
			}

			// Add support for older formats of the typography control.
			// We used to have font-weight instead of variant.
			if ( isset( $value['font-weight'] ) && ( ! isset( $value['variant'] ) || empty( $value['variant'] ) ) ) {
				$value['variant'] = $value['font-weight'];
			}

			// Set a default value for variants.
			if ( ! isset( $value['variant'] ) ) {
				$value['variant'] = 'regular';
			}
			if ( isset( $value['subsets'] ) ) {

				// Add the subset directly to the array of subsets in the Kirki_GoogleFonts_Manager object.
				// Subsets must be applied to ALL fonts if possible.
				if ( ! is_array( $value['subsets'] ) ) {
					$this->subsets[] = $value['subsets'];
				} else {
					foreach ( $value['subsets'] as $subset ) {
						$this->subsets[] = $subset;
					}
				}
			}

			// Add the requested google-font.
			if ( ! isset( $this->fonts[ $value['font-family'] ] ) ) {
				$this->fonts[ $value['font-family'] ] = array();
			}
			if ( ! in_array( $value['variant'], $this->fonts[ $value['font-family'] ], true ) ) {
				$this->fonts[ $value['font-family'] ][] = $value['variant'];
			}
		} else {

			// Process non-typography fields.
			if ( isset( $args['output'] ) && is_array( $args['output'] ) ) {
				foreach ( $args['output'] as $output ) {

					// If we don't have a typography-related output argument we can skip this.
					if ( ! isset( $output['property'] ) || ! in_array( $output['property'], array( 'font-family', 'font-weight', 'font-subset', 'subset', 'subsets' ), true ) ) {
						continue;
					}

					// Get the value.
					$value = Kirki_Values::get_sanitized_field_value( $args );

					if ( 'font-family' === $output['property'] ) {
						if ( ! array_key_exists( $value, $this->fonts ) ) {
							$this->fonts[ $value ] = array();
						}
					} elseif ( 'font-weight' === $output['property'] ) {
						foreach ( $this->fonts as $font => $variants ) {
							if ( ! in_array( $value, $variants, true ) ) {
								$this->fonts[ $font ][] = $value;
							}
						}
					} elseif ( 'font-subset' === $output['property'] || 'subset' === $output['property'] || 'subsets' === $output['property'] ) {
						if ( ! is_array( $value ) ) {
							if ( ! in_array( $value, $this->subsets, true ) ) {
								$this->subsets[] = $value;
							}
						} else {
							foreach ( $value as $subset ) {
								if ( ! in_array( $subset, $this->subsets, true ) ) {
									$this->subsets[] = $subset;
								}
							}
						}
					}
				}
			} // End if().
		} // End if().
	}

	/**
	 * Determines the vbalidity of the selected font as well as its properties.
	 * This is vital to make sure that the google-font script that we'll generate later
	 * does not contain any invalid options.
	 */
	private function process_fonts() {

		// Early exit if font-family is empty.
		if ( empty( $this->fonts ) ) {
			return;
		}

		$valid_subsets = array();
		foreach ( $this->fonts as $font => $variants ) {

			// Determine if this is indeed a google font or not.
			// If it's not, then just remove it from the array.
			if ( ! array_key_exists( $font, $this->google_fonts ) ) {
				unset( $this->fonts[ $font ] );
				continue;
			}

			// Get all valid font variants for this font.
			$font_variants = array();
			if ( isset( $this->google_fonts[ $font ]['variants'] ) ) {
				$font_variants = $this->google_fonts[ $font ]['variants'];
			}
			foreach ( $variants as $variant ) {

				// If this is not a valid variant for this font-family
				// then unset it and move on to the next one.
				if ( ! in_array( $variant, $font_variants, true ) ) {
					$variant_key = array_search( $variant, $this->fonts[ $font ] );
					unset( $this->fonts[ $font ][ $variant_key ] );
					continue;
				}
			}

			// Check if the selected subsets exist, even in one of the selected fonts.
			// If they don't, then they have to be removed otherwise the link will fail.
			if ( isset( $this->google_fonts[ $font ]['subsets'] ) ) {
				foreach ( $this->subsets as $subset ) {
					if ( in_array( $subset, $this->google_fonts[ $font ]['subsets'], true ) ) {
						$valid_subsets[] = $subset;
					}
				}
			}
		}
		$this->subsets = $valid_subsets;
	}

	/**
	 * Creates the google-fonts link.
	 */
	private function create_link() {

		// If we don't have any fonts then we can exit.
		if ( empty( $this->fonts ) ) {
			return;
		}

		// Add a fallback to Roboto.
		$font = 'Roboto';

		// Get font-family + subsets.
		$link_fonts = array();
		foreach ( $this->fonts as $font => $variants ) {

			// Are we force-loading all variants?
			if ( true === self::$force_load_all_variants ) {
				if ( isset( $this->google_fonts[ $font ]['variants'] ) ) {
					$variants = $this->google_fonts[ $font ]['variants'];
				}
			}
			$variants = implode( ',', $variants );

			$link_font = str_replace( ' ', '+', $font );
			if ( ! empty( $variants ) ) {
				$link_font .= ':' . $variants;
			}
			$link_fonts[] = $link_font;
		}

		// Are we force-loading all subsets?
		if ( true === self::$force_load_all_subsets ) {

			if ( isset( $this->google_fonts[ $font ]['subsets'] ) ) {
				foreach ( $this->google_fonts[ $font ]['subsets'] as $subset ) {
					$this->subsets[] = $subset;
				}
			}
		}

		if ( ! empty( $this->subsets ) ) {
			$this->subsets = array_unique( $this->subsets );
		}

		$this->link = add_query_arg( array(
			'family' => str_replace( '%2B', '+', urlencode( implode( '|', $link_fonts ) ) ),
			'subset' => urlencode( implode( ',', $this->subsets ) ),
		), 'https://fonts.googleapis.com/css' );

	}

	/**
	 * Get the contents of a remote google-fonts link.
	 * Responses get cached for 1 day.
	 *
	 * @access protected
	 * @since 3.0.0
	 * @param string $url The link we want to get.
	 * @return string|false Returns false if there's an error.
	 */
	protected function get_url_contents( $url = '' ) {

		// If $url is not set, use $this->link.
		$url = ( '' === $url ) ? $this->link : $url;

		// Sanitize the URL.
		$url = esc_url_raw( $url );

		// The transient name.
		$transient_name = 'kirki_googlefonts_contents_' . md5( $url );

		// Get the transient value.
		$html = get_transient( $transient_name );

		// Check for transient, if none, grab remote HTML file.
		if ( false === $html ) {

			// Get remote HTML file.
			$response = wp_remote_get( $url );

			// Check for error.
			if ( is_wp_error( $response ) ) {
				set_transient( 'kirki_googlefonts_fallback_to_link', 'yes', HOUR_IN_SECONDS );
				return false;
			}

			// Parse remote HTML file.
			$data = wp_remote_retrieve_body( $response );

			// Check for error.
			if ( is_wp_error( $data ) ) {
				set_transient( 'kirki_googlefonts_fallback_to_link', 'yes', HOUR_IN_SECONDS );
				return false;
			}

			// If empty, return false.
			if ( ! $data ) {
				set_transient( 'kirki_googlefonts_fallback_to_link', 'yes', HOUR_IN_SECONDS );
				return false;
			}

			// Store remote HTML file in transient, expire after 24 hours.
			set_transient( $transient_name, $data, DAY_IN_SECONDS );
			set_transient( 'kirki_googlefonts_fallback_to_link', 'no', DAY_IN_SECONDS );
		}

		return $html;

	}

	/**
	 * Embeds the CSS from googlefonts API inside the Kirki output CSS.
	 *
	 * @access public
	 * @since 3.0.0
	 * @param string $css The original CSS.
	 * @return string     The modified CSS.
	 */
	public function embed_css( $css ) {

		// Go through our fields and populate $this->fonts.
		$this->loop_fields();

		$this->fonts = apply_filters( 'kirki/enqueue_google_fonts', $this->fonts );

		// Goes through $this->fonts and adds or removes things as needed.
		$this->process_fonts();

		// Go through $this->fonts and populate $this->link.
		$this->create_link();

		// If $this->link is not empty then enqueue it.
		if ( '' !== $this->link ) {
			return $this->get_url_contents( $this->link ) . "\n" . $css;
		}
		return $css;
	}

	/**
	 * Webfont Loader for Google Fonts.
	 *
	 * @access public
	 * @since 3.0.0
	 */
	public function webfont_loader() {

		// Go through our fields and populate $this->fonts.
		$this->loop_fields();

		$this->fonts = apply_filters( 'kirki/enqueue_google_fonts', $this->fonts );

		// Goes through $this->fonts and adds or removes things as needed.
		$this->process_fonts();

		$fonts_to_load = array();
		foreach ( $this->fonts as $font => $weights ) {
			$fonts_to_load[] = esc_attr( $font ) . ':' . esc_attr( join( ',', $weights ) );
		}

		?>
		<script src="https://ajax.googleapis.com/ajax/libs/webfont/1.6.26/webfont.js"></script>
		<script id="kirki-webfont-loader">
			window.kirkiWebontsLoaderFonts = '<?php echo esc_attr( join( '\', \'', $fonts_to_load ) ); ?>';
			WebFont.load({
				google: {
					families: [ window.kirkiWebontsLoaderFonts ]
				}
			});
		</script>
		<?php
	}
}
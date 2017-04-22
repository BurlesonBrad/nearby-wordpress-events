<?php
/**
 * A class to handle locations and events.
 *
 * @package Nearby WordPress Events
 */

defined( 'WPINC' ) || die();

/**
 * Class WP_Nearby_Events
 *
 * A client for api.wordpress.org/events.
 */
class WP_Nearby_Events {
	/**
	 * WP user ID.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * Stored location data for the user.
	 *
	 * @var bool|array
	 */
	private $user_location = false;

	/**
	 * WP_Nearby_Events constructor.
	 *
	 * @param int        $user_id       WP user ID.
	 * @param bool|array $user_location Stored location data for the user.
	 */
	public function __construct( $user_id, $user_location = false ) {
		$this->user_id       = absint( $user_id );
		$this->user_location = $user_location;
	}

	/**
	 * Get data about events near a particular location.
	 *
	 * If the `user_location` property is set and there are cached events for this
	 * location, these will be immediately returned.
	 *
	 * If not, this method will send a request to the Events API with location data.
	 * The API will send back a recognized location based on the data, along with
	 * nearby events.
	 *
	 * @param string $location_search Optional search string to help determine the location.
	 * @param string $timezone        Optional timezone to help determine the location.
	 *
	 * @return array|WP_Error
	 */
	public function get_events( $location_search = '', $timezone = '' ) {
		$cached_events = $this->get_cached_events();

		if ( ! $location_search && $cached_events ) {
			return $cached_events;
		}

		$request_url   = $this->build_api_request_url( $location_search, $timezone );

		$response      = wp_remote_get( $request_url );
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $response_code ) {
			return new WP_Error(
				'api-error',
				/* translators: %s is a numeric HTTP status code; e.g., 400, 403, 500, 504, etc. */
				esc_html( sprintf( __( 'Invalid API response code (%s)', 'nearby-wp-events' ), $response_code ) ),
				array(
					'request_url'   => $request_url,
					'response_code' => $response_code,
					'response_body' => $response_body,
				) // @todo remove this during merge to Core
			);
		}

		if ( ! isset( $response_body['location'], $response_body['events'] ) ) {
			return new WP_Error(
				'api-invalid-response',
				isset( $response_body['error'] ) ? $response_body['error'] : __( 'Unknown API error.', 'nearby-wp-events' ),
				array(
					'request_url'   => $request_url,
					'response_code' => $response_code,
					'response_body' => $response_body,
				) // @todo remove this during merge to Core
			);
		}

		$this->cache_events( $response_body );

		$response_body = $this->trim_events( $response_body );
		$response_body = $this->format_event_data_time( $response_body );

		$response_body['api_request_info'] = compact( 'request_url', 'response_code' ); // @todo remove this during merge to Core

		return $response_body;
	}

	/**
	 * Build a URL for requests to the Events API
	 *
	 * @param string $search   City search string.
	 * @param string $timezone Timezone string.
	 *
	 * @return string
	 */
	private function build_api_request_url( $search = '', $timezone = '' ) {
		$api_url = 'https://api.wordpress.org/events/1.0/';

		$args = array(
			'number' => 5, // Get more than three in case some get trimmed out.
			'ip'     => $this->get_unsafe_client_ip(),
			'locale' => get_user_locale( $this->user_id ),
		);

		if ( $timezone ) {
			$args['timezone'] = wp_unslash( $timezone );
		}

		if ( $search ) {
			$args['location'] = wp_unslash( $search );
		} elseif ( isset( $this->user_location['latitude'], $this->user_location['longitude'] ) ) {
			// Send pre-determined location.
			$args['latitude']  = $this->user_location['latitude'];
			$args['longitude'] = $this->user_location['longitude'];
		}

		return add_query_arg( $args, $api_url );
	}

	/**
	 * Determine the user's actual IP if possible
	 *
	 * If the user is making their request through a proxy, or if the web server
	 * is behind a proxy, then $_SERVER['REMOTE_ADDR'] will be the proxy address
	 * rather than the user's actual address.
	 *
	 * Modified from http://stackoverflow.com/a/2031935/450127
	 *
	 * SECURITY WARNING: This function is _NOT_ intended to be used in
	 * circumstances where the authenticity of the IP address matters. This does
	 * _NOT_ guarantee that the returned address is valid or accurate, and it can
	 * be easily spoofed.
	 *
	 * @return false|string `false` on failure, the `string` address on success
	 */
	private function get_unsafe_client_ip() {
		$client_ip = false;

		// In order of preference, with the best ones for this purpose first.
		$address_headers = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $address_headers as $header ) {
			if ( array_key_exists( $header, $_SERVER ) ) {
				// HTTP_X_FORWARDED_FOR can contain a chain of comma-separated
				// addresses. The first one is the original client. It can't be
				// trusted for authenticity, but we don't need to for this purpose.
				$address_chain = explode( ',', $_SERVER[ $header ] );
				$client_ip     = trim( $address_chain[0] );

				break;
			}
		}

		return $client_ip;
	}

	/**
	 * Generate a transient key based on user location
	 *
	 * This could be reduced to a one-liner in the calling functions, but it's
	 * intentionally a separate function because it's called from multiple
	 * locations, and having it abstracted keeps the logic consistent and DRY,
	 * which is less prone to errors.
	 *
	 * @param array $location Should contain 'latitude' and 'longitude' indexes.
	 *
	 * @return bool|string `false` on failure, or a string on success
	 */
	private function get_events_transient_key( $location ) {
		$key = false;

		if ( isset( $location['latitude'], $location['longitude'] ) ) {
			$key = 'nearbywp-' . md5( $location['latitude'] . $location['longitude'] );
		}

		return $key;
	}

	/**
	 * Cache an array of events data from the Events API.
	 *
	 * @param array $events Response body from the API request.
	 *
	 * @return bool
	 */
	private function cache_events( $events ) {
		$set              = false;
		$transient_key    = $this->get_events_transient_key( $events['location'] );
		$cache_expiration = isset( $events['ttl'] ) ? absint( $events['ttl'] ) : HOUR_IN_SECONDS * 12;

		if ( $transient_key ) {
			$set = set_site_transient( $transient_key, $events, $cache_expiration );
		}

		return $set;
	}

	/**
	 * Get cached events
	 *
	 * @return false|array `false` on failure; an array containing `location`
	 *                     and `events` items on success
	 */
	public function get_cached_events() {
		$cached_response = get_site_transient( $this->get_events_transient_key( $this->user_location ) );
		$cached_response = $this->trim_events( $cached_response );

		return $this->format_event_data_time( $cached_response );
	}

	/**
	 * Add formatted date and time items for each event in an API response
	 *
	 * This has to be called after the data is pulled from the cache, because
	 * the cached events are shared by all users. If it was called before storing
	 * the cache, then all users would see the events in the localized data/time
	 * of the user who triggered the cache refresh, rather than their own.
	 *
	 * @param array $response_body The response which contains the events.
	 *
	 * @return array
	 */
	protected function format_event_data_time( $response_body ) {
		if ( isset( $response_body['events'] ) ) {
			foreach ( $response_body['events'] as $key => $event ) {
				$timestamp = strtotime( $event['date'] );

				/* translators: date format for upcoming events on the dashboard, see https://secure.php.net/date */
				$response_body['events'][ $key ]['formatted_date'] = date_i18n( __( 'l, M j, Y', 'nearby-wp-events' ), $timestamp );

				/* translators: time format for upcoming events on the dashboard, see https://secure.php.net/date */
				$response_body['events'][ $key ]['formatted_time'] = date_i18n( __( 'g:i a', 'nearby-wp-events' ), $timestamp );
			}
		}

		return $response_body;
	}

	/**
	 * Discard events that occurred more than 24 hours ago, then reduce the remaining list down to three items.
	 *
	 * @param array $response_body The response which contains the events.
	 *
	 * @return array
	 */
	protected function trim_events( $response_body ) {
		if ( isset( $response_body['events'] ) ) {
			$current_timestamp = time();

			foreach ( $response_body['events'] as $key => $event ) {
				if ( 'meetup' !== $event['type'] ) {
					continue;
				}

				$event_timestamp = strtotime( $event['date'] );

				if ( $current_timestamp > $event_timestamp && ( $current_timestamp - $event_timestamp ) > DAY_IN_SECONDS ) {
					unset( $response_body['events'][ $key ] );
				}
			}

			$response_body['events'] = array_slice( $response_body['events'], 0, 3 );
		}

		return $response_body;
	}
}
